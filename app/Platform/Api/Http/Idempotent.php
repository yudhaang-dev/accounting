<?php

namespace App\Platform\Api\Http;

use App\Platform\Api\ApiException;
use App\Platform\Api\ErrorCode;
use App\Platform\Api\Principal;
use App\Support\Company\CompanyContext;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * `Idempotency-Key` for POST commands (API-TR-13, API-AC-08).
 *
 * The key is scoped per principal and company. A repeated key with the same request returns the
 * original response; with a different request it is an `IDEMPOTENCY_KEY_REUSED` conflict. Only
 * successful (2xx) responses are stored; a failed attempt releases the key so it can be retried.
 * The key is mandatory for machine clients and recommended for the SPA.
 */
final class Idempotent
{
    private const HEADER = 'Idempotency-Key';

    private const REPLAYED_HEADERS = ['Content-Type', 'Location'];

    public function __construct(private readonly CompanyContext $company) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethod('POST')) {
            return $next($request);
        }

        $key = $request->header(self::HEADER);

        if ($key === null || $key === '') {
            if (Principal::isMachine($request)) {
                throw ValidationException::withMessages([self::HEADER => [__('validation.required', ['attribute' => self::HEADER])]]);
            }

            return $next($request);
        }

        if (strlen($key) > 255) {
            throw ValidationException::withMessages([self::HEADER => [__('validation.max.string', ['attribute' => self::HEADER, 'max' => 255])]]);
        }

        $principal = Principal::key($request) ?? throw new AuthenticationException;
        $scope = $this->company->has() ? $this->company->require() : 'global';
        $fingerprint = $this->fingerprint($request);

        $record = $this->claim($principal, $scope, $key, $fingerprint);

        if ($record !== null) {
            return $this->replayOrReject($record, $fingerprint);
        }

        try {
            $response = $next($request);
        } catch (Throwable $e) {
            $this->release($principal, $scope, $key);

            throw $e;
        }

        if ($response->isSuccessful()) {
            $this->complete($principal, $scope, $key, $response);
        } else {
            $this->release($principal, $scope, $key);
        }

        return $response;
    }

    /**
     * Reserves the key. Returns null when this request owns it, or the existing record otherwise.
     *
     * @return array<string, mixed>|null
     */
    private function claim(string $principal, string $scope, string $key, string $fingerprint): ?array
    {
        $table = DB::table('pf_idempotency_keys');
        $where = ['principal' => $principal, 'scope' => $scope, 'key' => $key];

        // Expired keys are forgotten, so the same key may be used again after the retention period.
        (clone $table)->where($where)->where('expires_at', '<=', now())->delete();

        $inserted = (clone $table)->insertOrIgnore([
            'id' => (string) Str::ulid(),
            ...$where,
            'fingerprint' => $fingerprint,
            'state' => 'processing',
            'created_at' => now(),
            'expires_at' => now()->addHours((int) config('api.idempotency.ttl_hours')),
        ]);

        if ($inserted === 1) {
            return null;
        }

        return (array) (clone $table)->where($where)->first();
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function replayOrReject(array $record, string $fingerprint): Response
    {
        if (! hash_equals((string) $record['fingerprint'], $fingerprint)) {
            throw new ApiException(ErrorCode::IdempotencyKeyReused);
        }

        if ($record['state'] !== 'completed') {
            throw new ApiException(ErrorCode::RequestInProgress);
        }

        $headers = json_decode((string) $record['response_headers'], true) ?: [];

        return response((string) $record['response_body'], (int) $record['response_status'], [...$headers, 'Idempotent-Replayed' => 'true']);
    }

    private function complete(string $principal, string $scope, string $key, Response $response): void
    {
        $headers = [];
        foreach (self::REPLAYED_HEADERS as $name) {
            if ($response->headers->has($name)) {
                $headers[$name] = $response->headers->get($name);
            }
        }

        DB::table('pf_idempotency_keys')
            ->where(['principal' => $principal, 'scope' => $scope, 'key' => $key])
            ->update([
                'state' => 'completed',
                'response_status' => $response->getStatusCode(),
                'response_body' => $response->getContent(),
                'response_headers' => json_encode($headers),
            ]);
    }

    private function release(string $principal, string $scope, string $key): void
    {
        DB::table('pf_idempotency_keys')
            ->where(['principal' => $principal, 'scope' => $scope, 'key' => $key])
            ->delete();
    }

    /** Same method, path, query and body (key order ignored) means the same request. */
    private function fingerprint(Request $request): string
    {
        $canonical = fn (mixed $value): mixed => is_array($value) ? $this->canonicalize($value) : $value;

        return hash('sha256', json_encode([
            $request->method(),
            $request->getPathInfo(),
            $canonical($request->query()),
            $canonical($request->json()->all() ?: $request->post()),
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private function canonicalize(array $value): array
    {
        foreach ($value as $k => $v) {
            if (is_array($v)) {
                $value[$k] = $this->canonicalize($v);
            }
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
