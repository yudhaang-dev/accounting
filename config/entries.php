<?php

return [

    /*
    | Rate type to look rates up in for an entry whose version names none (TE-FR-06). A company does not
    | have a default rate type of its own yet; the type must exist among its rate types.
    */
    'default_rate_type' => 'spot',

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
