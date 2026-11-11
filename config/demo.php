<?php

return [

    /*
    | Only read by the demo seeder (DEMO.md). `today` (YYYY-MM-DD) shortens a trial run; by default the
    | books run up to the real today. `timing` prints the seconds each stage took.
    */

    'today' => env('DEMO_TODAY') ?: null,

    /*
    | Password of the demo users. Random per run unless set here; it is printed once at the end of the run.
    */

    'password' => env('DEMO_PASSWORD') ?: null,

    'timing' => (bool) env('DEMO_TIMING', false),

];
