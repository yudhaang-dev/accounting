<?php

// Messages of the chart of accounts (GL-FR-01) and its template import (X-AC-03).
return [

    'code_taken' => 'The account code :code is already used in this company.',
    'parent_not_found' => 'The parent account does not exist in this company.',
    'parent_inactive' => 'The parent account is inactive.',
    'parent_has_postings' => 'The parent account already has postings and must stay a leaf account.',
    'parent_class_mismatch' => 'A parent and its children must have the same account class.',
    'parent_cycle' => 'An account cannot be moved under itself or one of its descendants.',
    'dimension_type_unknown' => 'The dimension type :key does not exist or is inactive.',
    'currency_required' => 'A restriction to a specific currency needs the restricted currency.',
    'currency_not_specific' => 'A restricted currency can only be set with the restriction "specific".',
    'currency_unknown' => 'The currency :code is not an ISO 4217 currency.',
    'currency_inactive' => 'The currency :code is not activated for this company.',
    'currency_is_functional' => 'The functional currency :code cannot be the foreign currency of an account; use the restriction "functional_only".',
    'revaluable_functional_only' => 'An account limited to the functional currency cannot be marked for revaluation.',
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
