<?php

// Messages of journal posting (GL-FR-05).
return [

    'lines_required' => 'A journal needs at least two lines.',
    'one_side' => 'A line must have either a debit or a credit greater than zero, not both.',
    'amount_invalid' => 'The amount is not a valid positive decimal number.',
    'amount_scale' => 'The amount has more than :scale decimal places.',
    'currency_unsupported' => 'Only the functional currency :currency can be posted for now.',
    'policy_key_unknown' => 'The company has no transaction type with the key :key.',
    'policy_permission_unknown' => 'The permission :permission does not exist.',
    'period_missing' => 'No fiscal period covers :date.',
    'period_soft_closed' => 'Period :period is soft-closed and only accepts the postings named by the ledger policy.',
    'period_closed' => 'Period :period is :status and does not accept postings.',
    'account_unknown' => 'The account does not exist in this company.',
    'account_inactive' => 'The account is inactive.',
    'account_parent' => 'The account is a parent account; post to one of its child accounts.',
    'source_not_allowed' => 'Account :account does not accept postings from the source ":source".',
    'currency_not_allowed' => 'Account :account does not accept the currency :currency.',
    'dimension_required' => 'Account :account requires a value for the dimension :type.',
    'dimension_forbidden' => 'Account :account does not allow the dimension :type.',
    'dimension_set_unknown' => 'The dimension set does not exist in this company.',
    'unbalanced' => 'The journal is not balanced: debit :debit, credit :credit.',

];
