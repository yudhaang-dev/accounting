<?php

it('never delivers mail through the log transport by default', function () {
    // Invitation and reset emails carry single-use tokens that must not reach log files (D2a).
    expect(config('mail.default'))->not->toBe('log')
        ->and(config('mail.mailers.failover.mailers'))->not->toContain('log');
});
