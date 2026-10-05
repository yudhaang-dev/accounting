<?php

// Messages of subledger documents and open items (SL-FR-04).
return [

    'party_unknown' => 'The party does not exist in this company.',
    'party_inactive' => 'The party :name is inactive.',
    'party_not_customer' => 'The party :name is not a customer.',
    'party_not_supplier' => 'The party :name is not a supplier.',
    'cash_account_required' => 'A document of the kind :kind needs a cash account.',
    'cash_account_unknown' => 'The cash account does not exist in this company.',
    'cash_account_inactive' => 'The cash account :name is inactive.',
    'cash_account_not_allowed' => 'A document of the kind :kind does not move cash and takes no cash account.',
    'due_before_document' => 'The due date cannot be before the date of the document.',
    'control_line_missing' => 'A document of the kind :kind needs a line to the control account of the party.',
    'control_line_side' => 'A document of the kind :kind posts to the control account of the party on the :side side.',
    'control_currency_mixed' => 'The lines to the control account of the party must all be in one currency.',
    'cash_line_missing' => 'The document needs a line to the account of the cash account :name.',
    'cash_line_side' => 'A document of the kind :kind posts to the cash account on the :side side.',
    'cash_line_currency' => 'Lines to the cash account :name must be in its currency, :currency.',
    'stray_control_line' => 'Account :account is the control account of a posting profile; only the document of that party can post to it.',
    'stray_cash_line' => 'Account :account belongs to a cash account; only a document that moves its cash can post to it.',

];
