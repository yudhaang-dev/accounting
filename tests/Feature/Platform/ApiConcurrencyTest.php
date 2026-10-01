<?php

use App\Models\User;
use App\Platform\Api\ApiException;
use App\Platform\Api\Concerns\HasLockVersion;
use App\Platform\Api\ErrorCode;
use App\Support\Eloquent\UlidModel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class LockedThing extends UlidModel
{
    use HasLockVersion;

    protected $table = 'pf_lock_test';

    protected $guarded = [];
}

beforeEach(function () {
    $this->calls = 0;

    Route::prefix('api/v1')->middleware('api')->group(function () {
        Route::middleware('idempotent')->post('_probe/command', function (Request $request) {
            $request->validate(['name' => 'required']);
            $GLOBALS['probe_calls'] = ($GLOBALS['probe_calls'] ?? 0) + 1;

            return response()->json(['data' => ['name' => $request->input('name'), 'call' => $GLOBALS['probe_calls']]], 201, ['Location' => '/x/1']);
        });
        Route::middleware('idempotent')->get('_probe/read', fn () => response()->json(['ok' => true]));
        Route::get('_probe/limited', fn () => response()->json(['ok' => true]));

        Route::patch('_probe/things/{thing}', function (Request $request, string $thing) {
            $data = $request->validate(['name' => 'required', 'lock_version' => 'required|integer|min:1']);

            $model = LockedThing::findOrFail($thing);
            $model->updateLocked(['name' => $data['name']], $data['lock_version']);

            return response()->json(['data' => ['name' => $model->name, 'lock_version' => $model->lock_version]]);
        });
    });
});

afterEach(function () {
    unset($GLOBALS['probe_calls']);
});

describe('Idempotency-Key (API-TR-13, API-AC-08)', function () {
    $post = fn ($test, array $body, string $key = 'k1') => $test->postJson('/api/v1/_probe/command', $body, ['Idempotency-Key' => $key]);

    it('returns the original response for a repeated request and runs the command once', function () use ($post) {
        $this->actingAs(User::factory()->create());

        $first = $post($this, ['name' => 'a'])->assertCreated();
        $second = $post($this, ['name' => 'a'])->assertCreated()->assertHeader('Idempotent-Replayed', 'true')->assertHeader('Location', '/x/1');

        expect($second->json())->toBe($first->json())
            ->and($GLOBALS['probe_calls'])->toBe(1)
            ->and($first->headers->get('Idempotent-Replayed'))->toBeNull();
    });

    it('treats the same body with a different key order as the same request', function () use ($post) {
        $this->actingAs(User::factory()->create());

        $post($this, ['name' => 'a', 'extra' => ['b' => 1, 'a' => 2]]);
        $post($this, ['extra' => ['a' => 2, 'b' => 1], 'name' => 'a'])->assertHeader('Idempotent-Replayed', 'true');

        expect($GLOBALS['probe_calls'])->toBe(1);
    });

    it('rejects the same key with a different body (IDEMPOTENCY_KEY_REUSED)', function () use ($post) {
        $this->actingAs(User::factory()->create());

        $post($this, ['name' => 'a']);

        $post($this, ['name' => 'b'])->assertStatus(409)->assertJson(['code' => 'IDEMPOTENCY_KEY_REUSED']);
        expect($GLOBALS['probe_calls'])->toBe(1);
    });

    it('scopes keys per principal', function () use ($post) {
        $this->actingAs(User::factory()->create());
        $post($this, ['name' => 'a']);

        $this->actingAs(User::factory()->create());
        $post($this, ['name' => 'a'])->assertCreated()->assertHeaderMissing('Idempotent-Replayed');

        expect($GLOBALS['probe_calls'])->toBe(2);
    });

    it('does not store failures, so a failed attempt can be retried with the same key', function () use ($post) {
        $this->actingAs(User::factory()->create());

        $post($this, [])->assertStatus(422);
        $post($this, [])->assertStatus(422);

        expect(DB::table('pf_idempotency_keys')->count())->toBe(0);

        $post($this, ['name' => 'ok'])->assertCreated();
    });

    it('reports a request that is still being processed', function () use ($post) {
        $this->actingAs(User::factory()->create());
        $post($this, ['name' => 'a']);
        DB::table('pf_idempotency_keys')->update(['state' => 'processing']);

        $post($this, ['name' => 'a'])->assertStatus(409)->assertJson(['code' => 'REQUEST_IN_PROGRESS']);
    });

    it('forgets expired keys', function () use ($post) {
        $this->actingAs(User::factory()->create());
        $post($this, ['name' => 'a']);
        DB::table('pf_idempotency_keys')->update(['expires_at' => now()->subMinute()]);

        $post($this, ['name' => 'a'])->assertCreated()->assertHeaderMissing('Idempotent-Replayed');

        expect($GLOBALS['probe_calls'])->toBe(2);
    });

    it('purges expired keys with the scheduled command', function () use ($post) {
        $this->actingAs(User::factory()->create());
        $post($this, ['name' => 'a'], 'old');
        $post($this, ['name' => 'a'], 'fresh');
        DB::table('pf_idempotency_keys')->where('key', 'old')->update(['expires_at' => now()->subMinute()]);

        $this->artisan('idempotency:purge')->assertSuccessful();

        expect(DB::table('pf_idempotency_keys')->pluck('key')->all())->toBe(['fresh']);
    });

    it('is mandatory for machine clients and optional for the SPA', function () {
        $this->postJson('/api/v1/_probe/command', ['name' => 'a'], ['Authorization' => 'Bearer opaque'])
            ->assertStatus(422)->assertJsonValidationErrors(['Idempotency-Key']);

        $this->postJson('/api/v1/_probe/command', ['name' => 'a'], ['Authorization' => 'Bearer opaque', 'Idempotency-Key' => 'm1'])
            ->assertCreated();

        $this->actingAs(User::factory()->create())->postJson('/api/v1/_probe/command', ['name' => 'a'])->assertCreated();
    });

    it('ignores reads', function () {
        $this->getJson('/api/v1/_probe/read', ['Idempotency-Key' => 'k'])->assertOk();

        expect(DB::table('pf_idempotency_keys')->count())->toBe(0);
    });
});

describe('lock_version (API-TR-14, API-AC-09)', function () {
    beforeEach(function () {
        Schema::create('pf_lock_test', function ($table) {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->unsignedInteger('lock_version')->default(1);
            $table->timestamps();
        });

        $this->thing = LockedThing::create(['name' => 'a'])->refresh();
    });

    it('updates and increments the version when the client is current', function () {
        $this->patchJson("/api/v1/_probe/things/{$this->thing->id}", ['name' => 'b', 'lock_version' => 1])
            ->assertOk()->assertJson(['data' => ['name' => 'b', 'lock_version' => 2]]);

        expect(LockedThing::find($this->thing->id)->lock_version)->toBe(2);
    });

    it('answers 409 VERSION_CONFLICT for a stale version and changes nothing', function () {
        $this->patchJson("/api/v1/_probe/things/{$this->thing->id}", ['name' => 'b', 'lock_version' => 1])->assertOk();

        $this->patchJson("/api/v1/_probe/things/{$this->thing->id}", ['name' => 'c', 'lock_version' => 1])
            ->assertStatus(409)->assertJson(['code' => 'VERSION_CONFLICT']);

        expect(LockedThing::find($this->thing->id)->name)->toBe('b');
    });

    it('requires lock_version on update', function () {
        $this->patchJson("/api/v1/_probe/things/{$this->thing->id}", ['name' => 'b'])
            ->assertStatus(422)->assertJsonValidationErrors(['lock_version']);
    });

    it('detects a concurrent write between read and write with compare-and-set', function () {
        $reader = LockedThing::find($this->thing->id);

        // Someone else writes after we loaded the row, without going through this model instance.
        DB::table('pf_lock_test')->where('id', $this->thing->id)->update(['name' => 'theirs', 'lock_version' => 2]);

        // The loaded copy still says version 1, so only the SQL guard can catch this.
        expect(fn () => $reader->updateLocked(['name' => 'mine'], 1))
            ->toThrow(fn (ApiException $e) => $e->errorCode === ErrorCode::VersionConflict);

        expect(DB::table('pf_lock_test')->where('id', $this->thing->id)->value('name'))->toBe('theirs');
    });

    it('bumps the version on plain saves so stale clients conflict', function () {
        $model = LockedThing::find($this->thing->id);
        $model->update(['name' => 'changed']);

        expect($model->lock_version)->toBe(2)
            ->and(fn () => LockedThing::find($this->thing->id)->updateLocked(['name' => 'x'], 1))->toThrow(ApiException::class);
    });
});

describe('rate limit (API-TR-15)', function () {
    it('limits per user and answers 429 with Retry-After', function () {
        config(['api.rate_limit.user_per_minute' => 2]);

        $user = User::factory()->create();
        $this->actingAs($user)->getJson('/api/v1/_probe/limited')->assertOk();
        $this->actingAs($user)->getJson('/api/v1/_probe/limited')->assertOk();

        $limited = $this->actingAs($user)->getJson('/api/v1/_probe/limited')->assertStatus(429)->assertJson(['code' => 'TOO_MANY_REQUESTS']);

        expect((int) $limited->headers->get('Retry-After'))->toBeGreaterThan(0);

        $this->actingAs(User::factory()->create())->getJson('/api/v1/_probe/limited')->assertOk();
    });

    it('gives integration clients their own budget', function () {
        config(['api.rate_limit.user_per_minute' => 1, 'api.rate_limit.client_per_minute' => 3]);

        foreach (range(1, 3) as $_) {
            $this->getJson('/api/v1/_probe/limited', ['Authorization' => 'Bearer client-a'])->assertOk();
        }

        $this->getJson('/api/v1/_probe/limited', ['Authorization' => 'Bearer client-a'])->assertStatus(429);
        $this->getJson('/api/v1/_probe/limited', ['Authorization' => 'Bearer client-b'])->assertOk();
    });

    it('limits unauthenticated callers per IP', function () {
        config(['api.rate_limit.guest_per_minute' => 1]);

        $this->getJson('/api/v1/_probe/limited')->assertOk();
        $this->getJson('/api/v1/_probe/limited')->assertStatus(429);
    });
});
