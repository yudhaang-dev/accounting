<?php

namespace Database\Seeders\Demo;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\Concerns\InteractsWithAuthentication;
use Illuminate\Foundation\Testing\Concerns\MakesHttpRequests;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Calls the application's own API in-process, as a signed-in user, so the demo data goes through the same
 * validation, approval, numbering, posting and audit as data entered on screen. Nothing is written around
 * the rules: a refused request stops the seeder with the reason the API gave.
 */
final class DemoApi
{
    use InteractsWithAuthentication;
    use MakesHttpRequests;

    /** @var Application */
    protected $app;

    public int $requests = 0;

    public function __construct()
    {
        $this->app = app();

        // The browser's CSRF cookie has no meaning for a call made inside the process.
        $this->app->instance(PreventRequestForgery::class, new class
        {
            public function handle(mixed $request, Closure $next): mixed
            {
                return $next($request);
            }
        });

        // Thousands of calls in a few minutes are the point of a seeder, not abuse (API-TR-15).
        config(['api.rate_limit.user_per_minute' => 10_000_000, 'api.rate_limit.client_per_minute' => 10_000_000]);
    }

    public function as(User $user): self
    {
        unset($this->defaultHeaders['Authorization']);
        $this->app->make('auth')->forgetGuards();
        $this->app->make('auth')->shouldUse('web');
        $this->actingAs($user);

        return $this;
    }

    /**
     * The Bearer token an integration client gets for its credentials (API-TR-22).
     *
     * @param  list<string>  $scopes
     */
    public function clientToken(string $clientId, string $secret, array $scopes): string
    {
        $this->requests++;
        $this->app->make('auth')->forgetGuards();
        $response = $this->call('POST', '/api/v1/oauth/token', ['grant_type' => 'client_credentials', 'scope' => implode(' ', $scopes)], [], [], [
            'HTTP_AUTHORIZATION' => 'Basic '.base64_encode("{$clientId}:{$secret}"),
            'HTTP_ACCEPT' => 'application/json',
        ]);

        return (string) (json_decode((string) $response->getContent(), true)['access_token'] ?? throw new RuntimeException('No token: '.$response->getContent()));
    }

    /**
     * Calls as an integration client: only the token, no session user.
     *
     * @param  array<string, mixed>  $data
     * @param  int|list<int>  $expect
     * @return array<string, mixed>
     */
    public function asClient(string $token, string $method, string $uri, array $data = [], int|array $expect = [200, 201]): array
    {
        $this->requests++;
        $this->app->make('auth')->forgetGuards();
        $this->app->make('auth')->shouldUse('web');
        $response = $this->json($method, $uri, $data, ['Authorization' => "Bearer {$token}", 'Idempotency-Key' => (string) Str::uuid()]);

        if (! in_array($response->getStatusCode(), (array) $expect, true)) {
            throw new RuntimeException("{$method} {$uri} (client) answered {$response->getStatusCode()}: ".mb_substr((string) $response->getContent(), 0, 1200));
        }

        return json_decode((string) $response->getContent(), true) ?? [];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  int|list<int>  $expect
     * @return array<string, mixed>
     */
    public function send(string $method, string $uri, array $data = [], int|array $expect = [200, 201, 204]): array
    {
        $this->requests++;
        $response = $this->json($method, $uri, $data);
        $expected = (array) $expect;

        if (! in_array($response->getStatusCode(), $expected, true)) {
            throw new RuntimeException(sprintf(
                '%s %s answered %d (expected %s): %s',
                $method,
                $uri,
                $response->getStatusCode(),
                implode('/', $expected),
                mb_substr((string) $response->getContent(), 0, 1500),
            ));
        }

        $body = json_decode((string) $response->getContent(), true);

        return is_array($body) ? $body : [];
    }

    /**
     * The rows of a list endpoint.
     *
     * @param  array<string, mixed>  $query
     * @return list<array<string, mixed>>
     */
    public function rows(string $uri, array $query = []): array
    {
        return $this->get($uri, $query)['data'];
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function get(string $uri, array $query = []): array
    {
        return $this->send('GET', $uri.($query === [] ? '' : '?'.http_build_query($query)));
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  int|list<int>  $expect
     * @return array<string, mixed>
     */
    public function post(string $uri, array $data = [], int|array $expect = [200, 201, 204]): array
    {
        return $this->send('POST', $uri, $data, $expect);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  int|list<int>  $expect
     * @return array<string, mixed>
     */
    public function put(string $uri, array $data = [], int|array $expect = [200, 201]): array
    {
        return $this->send('PUT', $uri, $data, $expect);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  int|list<int>  $expect
     * @return array<string, mixed>
     */
    public function patch(string $uri, array $data = [], int|array $expect = [200]): array
    {
        return $this->send('PATCH', $uri, $data, $expect);
    }

    /**
     * Uploads a small text file as an attachment.
     *
     * @return array<string, mixed>
     */
    public function upload(string $uri, string $name, string $content, string $mime = 'text/plain'): array
    {
        $this->requests++;
        $file = UploadedFile::fake()->createWithContent($name, $content);
        $response = $this->call('POST', $uri, [], [], ['file' => $file], ['HTTP_ACCEPT' => 'application/json']);

        if ($response->getStatusCode() !== 201) {
            throw new RuntimeException("POST {$uri} answered {$response->getStatusCode()}: ".mb_substr((string) $response->getContent(), 0, 800));
        }

        return json_decode((string) $response->getContent(), true) ?? [];
    }
}
