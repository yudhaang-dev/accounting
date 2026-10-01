<?php

// Messages of the chart of accounts (GL-FR-01) and its template import (X-AC-03).
return [

    'code_taken' => 'The account code :code is already used in this company.',
    'parent_not_found' => 'The parent account does not exist in this company.',
    'parent_inactive' => 'The parent account is inactive.',
    'parent_has_postings' => 'The parent account already has postings and must stay a leaf account.',
    'parent_class_mismatch' => 'A parent and its children must have the same account class.',
    'parent_cycle' => 'An account cannot be moved under itself or one of its descendants.',
    'template_unknown' => 'This chart of accounts template does not exist.',
    'chart_not_empty' => 'A template can only be imported into an empty chart of accounts.',
    'exclude_unknown' => 'The template has no account :code.',
    'exclude_not_optional' => 'The account :code is not optional and cannot be left out.',
    'missing_classes' => 'The chart of accounts needs at least one active postable account for: :classes.',

    'classes' => [
        'asset' => 'assets',
        'liability' => 'liabilities',
        'equity' => 'equity',
        'income' => 'income',
        'expense' => 'expenses',
    ],

];
