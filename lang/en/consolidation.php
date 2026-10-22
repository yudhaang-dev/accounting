<?php

// Messages of consolidation groups (CO-FR-02, CO-FR-04).
return [

    'parent_basis' => 'Parent company of the group.',
    'currency_unknown' => 'The currency is not an ISO 4217 currency.',
    'company_unknown' => 'The company does not exist.',
    'group_account_code_taken' => 'The group already has an account with this code.',
    'already_member' => 'The company is already in the perimeter of this group, or waiting for approval.',
    'approver_must_differ' => 'The control assessment must be approved by someone other than who proposed it.',
    'not_pending' => 'Only a member waiting for approval can be approved.',
    'already_ended' => 'The member has already left the perimeter.',
    'parent_cannot_leave' => 'The parent company cannot leave the perimeter of its group.',
    'end_before_start' => 'A member cannot leave before the date it joined.',
    'mapping_company_not_member' => 'The company is not an approved member of this group.',
    'mapping_account_unknown' => 'The account is not an active, postable account of the company.',
    'mapping_group_account_unknown' => 'The group account does not exist in this group or is not active.',
    'mapping_class_mismatch' => 'The account is of class :account, the group account of class :group.',
    'mapping_duplicate' => 'This account is mapped more than once.',
    'mapping_difference_account' => 'This group account takes the translation difference and cannot be mapped to.',
    'group_account_in_policy' => 'The translation policy takes its difference to this account. Save a policy that names another account first.',
    'rate_type_code_taken' => 'The group already has a rate type with this code.',
    'rate_type_in_policy' => 'The translation policy uses this rate type. Save a policy that names another type first.',
    'rate_type_inactive' => 'The rate type is not active.',
    'policy_rate_type_unknown' => 'The rate type does not exist in this group or is not active.',
    'policy_difference_not_equity' => 'The translation difference goes to an equity account.',
    'rate_presentation_currency' => 'The currency :code is the presentation currency of the group and needs no rate.',
    'perimeter_changes_in_range' => 'The company joined or left the perimeter inside this range. Choose a range it belongs to entirely.',
    'no_entity_in_range' => 'No approved member of the group is in the perimeter for this range.',
    'translation_required' => 'The functional currency :currency differs from the presentation currency :presentation. Currencies are not added directly: translation needs a policy and rates, which are not supported yet.',
    'package_not_closed' => 'The books of the company are not closed for this range (:status).',
    'package_coverage' => 'The ledger of the company does not cover this range completely (:status).',

];
