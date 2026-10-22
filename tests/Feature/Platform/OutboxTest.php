<?php

use App\Platform\Outbox\Outbox;
use App\Platform\Outbox\OutboxMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// The outbox (API-TR-25): written with the fact, relayed after commit, at least once.

beforeEach(function () {
    $this->outbox = app(Outbox::class);
    $this->company = (string) Str::ulid();
    $this->seen = [];

    // The handler of the test replaces whatever the application registered for the topic.
    $this->outbox->handle('test.topic', function (OutboxMessage $message) {
        $this->seen[] = $message;
    });
});

function outboxRow(string $id): object
{
    return DB::table('pf_outbox')->where('id', $id)->first();
}

describe('publishing', function () {
    it('is relayed after the surrounding transaction commits, with what was published', function () {
        $id = DB::transaction(fn () => $this->outbox->publish('test.topic', $this->company, 'subject-1', ['seq' => 7]));

        // The queue is sync in tests, so the relay that follows the commit has already run.
        expect($this->seen)->toHaveCount(1)
            ->and($this->seen[0])->toMatchObject(['id' => $id, 'companyId' => $this->company, 'topic' => 'test.topic', 'subjectId' => 'subject-1', 'payload' => ['seq' => 7], 'attempts' => 0])
            ->and(outboxRow($id)->dispatched_at)->not->toBeNull();
    });

    it('leaves nothing behind when the transaction of the fact rolls back', function () {
        try {
            DB::transaction(function () {
                $this->outbox->publish('test.topic', $this->company, null, []);

                throw new RuntimeException('the fact failed');
            });
        } catch (RuntimeException) {
        }

        $this->outbox->relay();

        expect($this->seen)->toBe([])->and(DB::table('pf_outbox')->where('company_id', $this->company)->count())->toBe(0);
    });
});

describe('relaying', function () {
    it('hands a row over once, oldest first', function () {
        [$first, $second] = DB::transaction(fn () => [
            $this->outbox->publish('test.topic', $this->company, null, ['n' => 1]),
            $this->outbox->publish('test.topic', $this->company, null, ['n' => 2]),
        ]);

        // Relayed once by the commit; the scheduled relay finds nothing left.
        expect($this->outbox->relay())->toBe(0)
            ->and(array_map(fn (OutboxMessage $m) => $m->id, $this->seen))->toBe([$first, $second]);
    });

    it('keeps a row whose handler fails, and tries it again later with a longer wait', function () {
        $fail = true;
        $this->outbox->handle('flaky.topic', function (OutboxMessage $message) use (&$fail) {
            $this->seen[] = $message;

            if ($fail) {
                throw new RuntimeException('receiver down');
            }
        });
        $id = $this->outbox->publish('flaky.topic', $this->company, null, []);

        expect($this->outbox->relay())->toBe(0);

        $row = outboxRow($id);
        expect($row->dispatched_at)->toBeNull()->and($row->attempts)->toBe(1)->and($row->last_error)->toBe('receiver down')
            ->and(Carbon::parse($row->available_at)->isFuture())->toBeTrue();

        // Not due yet: the relay leaves it alone.
        expect($this->outbox->relay())->toBe(0)->and($this->seen)->toHaveCount(1);

        $this->travel(11)->seconds();
        $fail = false;

        expect($this->outbox->relay())->toBe(1)->and($this->seen)->toHaveCount(2)->and($this->seen[1]->attempts)->toBe(1)
            ->and(outboxRow($id))->toMatchObject(['attempts' => 2, 'last_error' => null]);

        $this->travelBack();
    });

    it('does not let a handler that fails halfway leave its writes behind', function () {
        $this->outbox->handle('half.topic', function () {
            DB::table('pf_outbox')->insert(['id' => (string) Str::ulid(), 'company_id' => $this->company, 'topic' => 'side.effect', 'payload' => '{}', 'available_at' => now(), 'created_at' => now()]);

            throw new RuntimeException('halfway');
        });
        $this->outbox->publish('half.topic', $this->company, null, []);

        $this->outbox->relay();

        expect(DB::table('pf_outbox')->where('topic', 'side.effect')->count())->toBe(0);
    });

    it('keeps a row of a topic nobody handles, so that it is not lost', function () {
        $id = $this->outbox->publish('nobody.topic', $this->company, null, []);

        $this->outbox->relay();

        expect(outboxRow($id))->toMatchObject(['dispatched_at' => null, 'attempts' => 1]);
    });

    it('prunes what was relayed long ago and nothing else', function () {
        $old = $this->outbox->publish('test.topic', $this->company, null, []);
        $this->outbox->relay();
        $pending = $this->outbox->publish('nobody.topic', $this->company, null, []);
        DB::table('pf_outbox')->update(['dispatched_at' => DB::raw("case when id = '{$old}' then now() - interval '8 days' else null end")]);

        expect($this->outbox->prune(7))->toBe(1)->and(DB::table('pf_outbox')->pluck('id')->all())->toBe([$pending]);
    });
});
