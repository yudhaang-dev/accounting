<?php

// Messages of plans, plan versions and plan lines (PL-FR-01, PL-FR-02, PL-FR-03).
return [

    'fiscal_year_unknown' => 'The fiscal year does not exist in this company.',
    'name_taken' => 'A plan with this name already exists.',
    'period_reversed' => 'The end date cannot be before the start date.',
    'period_fixed' => 'The period and the scope cannot change once a version has been published.',
    'period_lines_outside' => 'The plan has lines outside the new period.',
    'revision_unknown' => 'The version is not a published version of this plan.',
    'scope_unknown' => 'The dimension value does not exist in this company.',
    'version_name_taken' => 'A version with this name already exists in this plan.',

    'period_unknown' => 'The period does not exist in this company.',
    'period_outside_year' => 'The period is outside the period of the plan.',
    'account_unknown' => 'The account does not exist in this company.',
    'account_inactive' => 'Account :code is inactive.',
    'account_parent' => 'Account :code is a parent account; plan a leaf account.',
    'account_class' => 'Account :code is not an income or expense account.',
    'amount_invalid' => 'The amount must be a decimal number with at most :scale decimals.',
    'line_duplicate' => 'The same period, account and dimensions already appear in row :row.',

    'copy_same_version' => 'A version cannot be copied into itself.',

    'csv_required' => 'Send the CSV as a multipart "file" or a text/csv body.',
    'csv_too_large' => 'The CSV file is larger than 2 MB.',
    'csv_empty' => 'The CSV file has no rows.',
    'csv_too_many' => 'The CSV file has more than :max rows.',
    'csv_header' => 'The first line must name the columns :columns; dimensions are columns named dimension.<type key>.',
    'csv_column_unknown' => 'Unknown column ":column".',
    'csv_period_unknown' => 'No period contains the date ":value".',
    'csv_account_unknown' => 'No account has the code ":value".',
    'csv_dimension_unknown' => 'No value with the code ":value" exists for the dimension type.',

    'publisher_must_differ' => 'The version was last changed by you; another user must publish it.',
    'publish_empty' => 'A version without lines cannot be published.',
    'version_unknown' => 'The version does not exist in this company.',
    'default_not_published' => 'Only a published version can be the default comparison.',
    'default_other_year' => 'The plan does not overlap the fiscal year.',
    'default_scoped' => 'A plan with a scope cannot be the default comparison of the whole company.',
    'default_year_repeated' => 'The fiscal year appears more than once.',

];
