<?php

return [

    /*
    | Rate type to look rates up in for an entry whose version names none (TE-FR-06). A company does not
    | have a default rate type of its own yet; the type must exist among its rate types.
    */
    'default_rate_type' => 'spot',

    /*
    | Number prefix of the transaction types the code defines. A type without an entry here is
    | prefixed with its key, so the types of a company never share a prefix: the numbers of an
    | entry are unique across the types of a company.
    */
    'system_number_prefixes' => ['system.reversal' => 'REV'],

    /*
    | Entry number series created per transaction type on first use (TE-FR-16), as
    | "<prefix>/{yyyy}/{mm}/{seq}". A company changes the series of a type through the API. The
    | journal number is a separate series owned by the ledger.
    */
    'number' => [
        'padding' => 6,
        'reset' => 'monthly',
    ],

];
