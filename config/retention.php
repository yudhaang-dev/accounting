<?php

return [

    /*
    | Retention of financial data, attachments and audit (X-FR-05). Years count from the record's date.
    | The default follows Indonesian bookkeeping law (UU KUP Pasal 28 ayat 11, [R10]); a company may
    | choose a longer period but never one below `minimum_years`.
    */

    'default_years' => (int) env('RETENTION_DEFAULT_YEARS', 10),

    'minimum_years' => (int) env('RETENTION_MINIMUM_YEARS', 10),

    /*
    | Tables whose prefix marks them as financial data but which may still be hard-deleted (for example
    | scratch or draft tables). Listing a table here is a deliberate, reviewed exception: every other
    | gl_, sl_ and te_ table must carry the retention guard (see RetentionGuard).
    */

    'unprotected_tables' => [],

    /* Table prefixes of financial data that must be protected against hard delete. */
    'protected_prefixes' => ['gl_', 'sl_', 'te_'],

];
