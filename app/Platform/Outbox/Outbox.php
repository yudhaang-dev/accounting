<?php

namespace App\Platform\Outbox;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

/**
 * The outbox (API-TR-25): `publish` writes a row in the transaction of the fact, `relay` hands the due rows
 * to the handler of their topic after commit and marks them done. A handler that throws leaves the row to
 * come back later with a growing delay, so a fact is relayed at least once and never before it is committed.
 * Handlers are registered by whoever knows what to do with a topic; this class never calls into business modules.
 */
final class Outbox
{
    /** Longest wait between two attempts of the relay for one row, in seconds. */
    private const MAX_DELAY = 3600;

    /** @var array<string, Closure(OutboxMessage): void> */
    private array $handlers = [];

    /**
     * @param  Closure(OutboxMessage): void  $handler
     */
    public function handle(string $topic, Closure $handler): void
    {
        $this->handlers[$topic] = $handler;
    }

    /**
     * Writes the fact and asks for the relay once the surrounding transaction has committed.
     *
     * @param  array<string, mixed>  $payload
     */
    public function publish(string $topic, string $companyId, ?string $subjectId, array $payload): string
    {
        $id = (string) Str::ulid();

        DB::table('pf_outbox')->insert([
            'id' => $id, 'company_id' => $companyId, 'topic' => $topic, 'subject_id' => $subjectId,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'available_at' => now(), 'created_at' => now(),
        ]);

        DB::afterCommit(fn () => dispatch(new RelayOutbox));

        return $id;
    }

    /**
     * Relays up to `$limit` due rows, oldest first. Rows another relay holds are left to it.
     *
     * @return int the number of rows handed over
     */
    public function relay(int $limit = 100): int
    {
        $relayed = 0;

        foreach (DB::table('pf_outbox')->whereNull('dispatched_at')->where('available_at', '<=', now())->orderBy('id')->limit($limit)->pluck('id') as $id) {
            $relayed += DB::transaction(fn (): int => $this->relayOne((string) $id));
        }

        return $relayed;
    }

    /** Deletes the rows relayed more than `$days` days ago. */
    public function prune(int $days): int
    {
        return DB::table('pf_outbox')->whereNotNull('dispatched_at')->where('dispatched_at', '<', now()->subDays($days))->delete();
    }

    private function relayOne(string $id): int
    {
        $row = DB::table('pf_outbox')->where('id', $id)->whereNull('dispatched_at')->where('available_at', '<=', now())->lock('for update skip locked')->first();

        if ($row === null) {
            return 0;
        }

        $message = new OutboxMessage(
            $row->id, $row->company_id, $row->topic, $row->subject_id,
            (array) json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR), (int) $row->attempts,
        );

        try {
            $handler = $this->handlers[$row->topic] ?? throw new LogicException("No handler for the outbox topic {$row->topic}.");

            // A savepoint, so that a handler that fails halfway leaves nothing of its own behind.
            DB::transaction(fn () => $handler($message));
        } catch (Throwable $e) {
            report($e);

            DB::table('pf_outbox')->where('id', $id)->update([
                'attempts' => $message->attempts + 1,
                'available_at' => now()->addSeconds(min(10 * 2 ** $message->attempts, self::MAX_DELAY)),
                'last_error' => mb_substr($e->getMessage(), 0, 1000),
            ]);

            return 0;
        }

        DB::table('pf_outbox')->where('id', $id)->update(['dispatched_at' => now(), 'attempts' => $message->attempts + 1, 'last_error' => null]);

        return 1;
    }
}
