<?php

namespace Database\Seeders\Demo;

/**
 * Entries the way people enter them: the finance admin prepares and submits, the finance manager approves,
 * and a second approver (the director) approves too where the policy asks for two stages. Posting follows
 * the approval, and each step is a call to the API with the right person signed in.
 */
final class DemoBooks
{
    public function __construct(private readonly DemoFoundation $f) {}

    /**
     * Prepares an entry (a draft). Values are for structured types; lines for free-line ones.
     *
     * @param  array<string, mixed>  $values
     * @param  list<array<string, mixed>>|null  $lines
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    public function draft(string $type, string $date, string $description, array $values = [], ?array $lines = null, array $extra = [], string $by = 'preparer'): array
    {
        $body = ['transaction_type_key' => $type, 'accounting_date' => $date, 'description' => $description, ...$extra];

        if ($values !== []) {
            $body['values'] = $values;
        }

        if ($lines !== null) {
            $body['lines'] = $lines;
        }

        return $this->f->api->as($this->f->users[$by])->post($this->f->url('/entries'), $body)['data'];
    }

    /** @return array<string, mixed> */
    public function submit(string $id, string $by = 'preparer'): array
    {
        return $this->f->api->as($this->f->users[$by])->post($this->f->url("/entries/{$id}/submit"))['data'];
    }

    /**
     * Approves the stages still open, the first by the finance manager and the second by the director, and
     * posts when the policy does not do it by itself.
     *
     * @param  array<string, mixed>  $submitted
     * @return array<string, mixed>
     */
    public function approve(array $submitted): array
    {
        $entry = $submitted;
        $approvers = ['approver', 'owner'];

        for ($stage = (int) ($entry['approval']['approved'] ?? 0); $entry['status'] === 'submitted' && $stage < 4; $stage++) {
            $entry = $this->f->api->as($this->f->users[$approvers[$stage % 2]])->post($this->f->url("/entries/{$entry['id']}/approve"))['data'];
        }

        if ($entry['status'] === 'approved') {
            $entry = $this->f->api->as($this->f->users['approver'])->post($this->f->url("/entries/{$entry['id']}/post"))['data'];
        }

        return $entry;
    }

    /**
     * Prepared, submitted, approved and posted.
     *
     * @param  array<string, mixed>  $values
     * @param  list<array<string, mixed>>|null  $lines
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    public function post(string $type, string $date, string $description, array $values = [], ?array $lines = null, array $extra = [], string $by = 'preparer'): array
    {
        $entry = $this->draft($type, $date, $description, $values, $lines, $extra, $by);
        $entry = $this->approve($this->submit($entry['id'], $by));

        if ($entry['status'] !== 'posted') {
            throw new \RuntimeException("Entry {$entry['id']} ({$type} {$date}) did not post: status {$entry['status']}.");
        }

        return $entry;
    }
}
