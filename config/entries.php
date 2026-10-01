<?php

return [

    /*
    | When true the creator of an entry cannot approve it (TE-FR-09). It becomes part of the
    | approval policy of the company in M5-04; until then it is one switch for the whole system.
    */
    'separation_of_duties' => false,

    /*
    | Entry number series created per transaction type on first use (TE-FR-16). Numbers are issued
    | when the entry is created. The journal number is a separate series owned by the ledger.
    */
    'number' => [
        'pattern' => 'ENT/{yyyy}/{mm}/{seq}',
        'padding' => 6,
        'reset' => 'monthly',
    ],

];
