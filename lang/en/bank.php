<?php

// Messages of bank statement import and matching (SL-FR-13).
return [

    'mapping_amount_columns' => 'Give one amount column, or both a money in column and a money out column.',
    'mapping_missing' => 'Save the column mapping of this cash account before importing a statement.',
    'statement_already_imported' => 'This file has already been imported into this cash account.',
    'csv_unreadable' => 'The file cannot be read.',
    'csv_empty' => 'The file has no statement lines.',
    'csv_too_many_rows' => 'A statement can have at most :max lines.',
    'csv_columns_missing' => 'The line has fewer than :columns columns; check the delimiter and the mapping.',
    'csv_date_invalid' => 'The date ":value" does not match the format :format.',
    'csv_amount_invalid' => 'The amount ":value" is not a non-zero number.',
    'csv_in_out_invalid' => 'Exactly one of money in (":in") and money out (":out") must hold a non-zero number.',
    'line_already_matched' => 'This statement line is already matched.',
    'line_not_matched' => 'This statement line is not matched.',
    'transaction_other_account' => 'The cash transaction does not belong to the cash account of this statement.',
    'transaction_differs' => 'The cash transaction must be a :direction transaction of :amount.',
    'transaction_already_matched' => 'The cash transaction is already matched to another statement line.',

];
