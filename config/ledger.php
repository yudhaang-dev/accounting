<?php

return [

    /*
    | Decimal places of ledger amounts until currencies and their scale become company data (M2-03,
    | MD-FR-06). Amounts with more decimals than this are refused, never rounded.
    */
    'default_scale' => 2,

    /*
    | Journal number series created for a company on its first posting. A company will be able to
    | change it with the ledger policy (M4); issued numbers never change.
    */
    'journal_number' => [
        'pattern' => 'JU/{yyyy}/{mm}/{seq}',
        'padding' => 6,
        'reset' => 'monthly',
    ],

];
