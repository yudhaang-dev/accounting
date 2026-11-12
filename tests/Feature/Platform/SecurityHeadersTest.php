<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Vite;

// D3f: every response carries the browser hardening headers; only the HTML of the SPA carries a content security policy.

beforeEach(function () {
    Route::get('/__test/page', fn () => response('<html></html>', 200, ['Content-Type' => 'text/html; charset=UTF-8']));
    Route::get('/docs/__test', fn () => response('<html></html>', 200, ['Content-Type' => 'text/html; charset=UTF-8']));
});

it('sets the hardening headers on API responses, without a content security policy', function () {
    $response = $this->getJson('/api/v1/csrf-cookie');

    $response->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeaderMissing('Content-Security-Policy');
    expect($response->headers->get('Permissions-Policy'))->toContain('camera=()')->toContain('publickey-credentials-create=(self)');
});

it('puts a content security policy with a fresh nonce on HTML pages', function () {
    $first = $this->get('/__test/page')->headers->get('Content-Security-Policy');
    $second = $this->get('/__test/page')->headers->get('Content-Security-Policy');

    expect($first)->toContain("default-src 'self'")->toContain("frame-ancestors 'none'")->toContain("object-src 'none'")
        ->and($first)->toMatch("/script-src 'self' 'nonce-[A-Za-z0-9+\/=]{20,}'/")
        ->and($first)->not->toBe($second);
});

it('hands the nonce of the policy to Vite for the shell', function () {
    $this->get('/__test/page');

    expect(Vite::cspNonce())->toMatch('/^[A-Za-z0-9+\/=]{20,}$/')
        ->and(file_get_contents(resource_path('views/spa.blade.php')))->toContain('nonce="{{ Vite::cspNonce() }}"');
});

it('leaves the API docs without the policy', function () {
    $this->get('/docs/__test')->assertHeaderMissing('Content-Security-Policy')->assertHeader('X-Frame-Options', 'DENY');
});

it('sends HSTS only over HTTPS', function () {
    $this->get('http://localhost/__test/page')->assertHeaderMissing('Strict-Transport-Security');
    $this->get('https://localhost/__test/page')->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
});

it('ships a production environment template that is safe and carries no secret', function () {
    $env = collect(file(base_path('.env.production.example'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES))
        ->reject(fn (string $line): bool => str_starts_with($line, '#'))
        ->mapWithKeys(function (string $line): array {
            [$key, $value] = explode('=', $line, 2);

            return [$key => trim($value, '"')];
        });

    expect($env['APP_ENV'])->toBe('production')
        ->and($env['APP_DEBUG'])->toBe('false')
        ->and($env['SESSION_SECURE_COOKIE'])->toBe('true')
        ->and($env['SESSION_ENCRYPT'])->toBe('true')
        ->and($env['MAIL_MAILER'])->not->toBe('log');

    foreach (['APP_KEY', 'DB_PASSWORD', 'MAIL_PASSWORD'] as $secret) {
        expect($env[$secret])->toBe('');
    }
});
