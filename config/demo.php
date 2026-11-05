<?php

return [

    /*
    | Only read by the demo seeder (DEMO.md). `today` (YYYY-MM-DD) shortens a trial run; by default the
    | books run up to the real today. `timing` prints the seconds each stage took.
    */

    'today' => env('DEMO_TODAY') ?: null,

    'timing' => (bool) env('DEMO_TIMING', false),

];
