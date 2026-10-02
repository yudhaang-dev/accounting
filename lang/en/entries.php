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
    'rules_required' => 'A structured version needs at least one posting rule.',
    'rules_not_allowed' => 'A free-lines version has no posting rules.',
    'rule_side_required' => 'A rule posts to the debit or the credit side.',
    'rule_amount_field_unknown' => 'The amount field of a rule must be an amount field of this version.',
    'rule_account_field_unknown' => 'The account field of a rule must be an account field of this version.',
    'rule_account_required' => 'A rule needs an account: a fixed account or an account field.',
    'rule_fx_once' => 'A version has at most one exchange difference rule.',
    'rule_reuse_undeclared' => 'This rule uses a field that another rule also uses; every rule sharing a field must say so with reuses_field.',
    'currency_not_allowed' => 'This version does not accept the currency :currency.',
    'currency_inactive' => 'The currency :currency is not active in this company.',
    'currency_per_line' => 'A free-lines entry has a currency on each line, not on the entry.',
    'rate_not_applicable' => 'An entry in the functional currency has no exchange rate.',
    'rate_input_not_allowed' => 'This version does not let an entry bring its own exchange rate.',
    'rate_invalid' => 'The exchange rate must be a positive decimal with at most 12 decimals.',
    'lines_required' => 'A free-lines entry needs its lines.',
    'lines_not_allowed' => 'A structured entry has values, not lines; its lines come from the posting rules.',
    'values_not_allowed' => 'A free-lines entry has lines, not field values.',
    'value_required' => 'The field :field is required.',
    'value_invalid' => 'The value of the field :field is not valid.',
    'value_unknown' => 'The version has no field :field.',
    'value_account_not_allowed' => 'The account of the field :field is not one of the accounts allowed for it.',
    'invalid_transition' => 'The action does not apply to an entry that is :status.',
    'line_outside_scope' => 'This line is outside your data scope: it needs a value of each dimension you are limited to, within your scope.',

];
