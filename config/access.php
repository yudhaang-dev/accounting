<?php

return [

    // Days an emailed workspace invitation stays valid (IA-FR-01).
    'invitation_days' => (int) env('ACCESS_INVITATION_DAYS', 7),

    // The only address `access:invite-owner` may invite while no user exists (ADR-0021). Empty means no first owner can be invited.
    'first_owner_email' => env('FIRST_OWNER_EMAIL') ?: null,

];
