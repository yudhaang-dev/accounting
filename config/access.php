<?php

return [

    // Days an emailed workspace invitation stays valid (IA-FR-01).
    'invitation_days' => (int) env('ACCESS_INVITATION_DAYS', 7),

];
