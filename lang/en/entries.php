<?php

// Messages of transaction types and entries (TE-FR-01, TE-FR-07).
return [

    'type_key_taken' => 'The transaction type key :key is already used in this company.',
    'type_unknown' => 'The transaction type :key does not exist in this company.',
    'version_unknown' => 'Version :version of this transaction type does not exist.',
    'version_unpublished' => 'Version :version of this transaction type is not published.',
    'fields_required' => 'A structured version needs at least one field.',
    'fields_not_allowed' => 'A free-lines version has no fields.',
    'field_name_taken' => 'The field name :name is used more than once.',
    'field_account_unknown' => 'The account :id does not exist in this company.',
    'field_dimension_type_unknown' => 'The dimension type does not exist or is not active in this company.',
    'structured_not_supported' => 'Entries for structured versions are not available yet.',
    'invalid_transition' => 'The action does not apply to an entry that is :status.',
    'line_outside_scope' => 'This line is outside your data scope: it needs a value of each dimension you are limited to, within your scope.',

];
