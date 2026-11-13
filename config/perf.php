<?php

return [

    /*
    | Only read by the volume seeder (`database/seeders/VolumeSeeder.php`, tests/Performance/README.md).
    | Defaults are a small smoke run; the target volume of D3e is 20 companies, 1,000,000 journal lines in
    | total, 50,000 open items, 3 fiscal years.
    */

    'companies' => (int) env('PERF_COMPANIES', 2),

    'years' => (int) env('PERF_YEARS', 3),

    // First fiscal year; the books run for `years` years from 1 January of it.
    'start_year' => (int) env('PERF_START_YEAR', 2024),

    // Journals per company (each has two to four lines).
    'journals' => (int) env('PERF_JOURNALS', 5000),

    // Open invoices per company, each with its own journal, document and open item.
    'open_items' => (int) env('PERF_OPEN_ITEMS', 500),

    'parties' => (int) env('PERF_PARTIES', 40),

    // Dimension combinations journal lines are spread over, per company.
    'dimension_sets' => (int) env('PERF_DIMENSION_SETS', 150),

    'batch' => (int) env('PERF_BATCH', 1000),

    'password' => env('PERF_PASSWORD') ?: null,

    // The seeder refuses `production` unless this is true (a staging server that runs as production).
    'allow_production' => (bool) env('PERF_ALLOW_PRODUCTION', false),

];
