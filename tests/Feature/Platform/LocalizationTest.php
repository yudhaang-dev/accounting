<?php

use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::middleware('api')->get('/api/v1/_locale-probe', fn () => response()->json([
        'code' => 'NOT_FOUND',
        'message' => __('errors.not_found'),
    ], 404));
});

it('answers in the language of Accept-Language', function (string $header, string $locale, string $message) {
    $response = $this->getJson('/api/v1/_locale-probe', ['Accept-Language' => $header])
        ->assertNotFound()
        ->assertHeader('Content-Language', $locale)
        ->assertJson(['code' => 'NOT_FOUND', 'message' => $message]);

    expect($response->headers->get('Vary'))->toContain('Accept-Language');
})->with([
    'indonesian' => ['id', 'id', 'Resource yang diminta tidak ditemukan.'],
    'english' => ['en', 'en', 'The requested resource was not found.'],
    'region variant' => ['id-ID', 'id', 'Resource yang diminta tidak ditemukan.'],
    'quality values' => ['fr, id;q=0.8, en;q=0.5', 'id', 'Resource yang diminta tidak ditemukan.'],
]);

it('falls back to the configured default for unsupported or missing languages', function (?string $header) {
    config(['app.locale' => 'id']);

    // Symfony's test requests add a default Accept-Language, so "missing" must be blanked explicitly.
    $this->withServerVariables(['HTTP_ACCEPT_LANGUAGE' => $header ?? ''])
        ->getJson('/api/v1/_locale-probe')
        ->assertHeader('Content-Language', 'id')
        ->assertJson(['message' => 'Resource yang diminta tidak ditemukan.']);
})->with([
    'unsupported' => ['fr'],
    'missing' => [null],
]);

it('uses the configured default even when it is not the first supported locale', function () {
    config(['app.locale' => 'en']);

    $this->getJson('/api/v1/_locale-probe', ['Accept-Language' => 'fr'])
        ->assertHeader('Content-Language', 'en');
});

it('changes only the message, never the code or status (API-AC-10)', function () {
    $id = $this->getJson('/api/v1/_locale-probe', ['Accept-Language' => 'id']);
    $en = $this->getJson('/api/v1/_locale-probe', ['Accept-Language' => 'en']);

    expect($id->status())->toBe($en->status())
        ->and($id->json('code'))->toBe($en->json('code'))
        ->and($id->json('message'))->not->toBe($en->json('message'));
});

it('translates framework validation messages', function () {
    app()->setLocale('id');

    $message = validator(['name' => ''], ['name' => 'required'])->errors()->first('name');
    $failure = validator(['name' => ''], ['name' => 'required'])->fails();

    expect($failure)->toBeTrue()
        ->and($message)->toBe('Isian name wajib diisi.');
});

it('keeps the id and en language files in sync', function () {
    $keys = function (string $locale, string $file): array {
        $flatten = function (array $items, string $prefix = '') use (&$flatten): array {
            $out = [];
            foreach ($items as $key => $value) {
                is_array($value) ? $out = [...$out, ...$flatten($value, "$prefix$key.")] : $out[] = "$prefix$key";
            }

            return $out;
        };

        return $flatten(require lang_path("$locale/$file"));
    };

    foreach (array_map('basename', glob(lang_path('en/*.php'))) as $file) {
        expect(file_exists(lang_path("id/$file")))->toBeTrue();
        expect($keys('id', $file))->toEqualCanonicalizing($keys('en', $file));
    }
});

it('also translates the messages of Fortify auth routes, which live in the web group', function () {
    $this->postJson('/login', ['email' => 'nobody@example.com', 'password' => 'wrong'], ['Accept-Language' => 'id'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.email.0', 'Kredensial ini tidak cocok dengan data kami.');
});
