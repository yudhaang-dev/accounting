<?php

// Messages of the fiscal calendar, fiscal years and periods (GL-FR-03, GL-FR-04).
return [

    'calendar_exists' => 'This company already has a fiscal calendar.',
    'calendar_locked' => 'The fiscal calendar cannot change once fiscal years have been created.',
    'calendar_required' => 'Set up the fiscal calendar before creating fiscal years.',
    'start_date_required' => 'The first fiscal year needs a start date.',
    'start_date_first_day' => 'The first fiscal year must start on the 1st of month :month.',
    'start_date_continue' => 'A new fiscal year continues the previous one and starts on :date.',
    'name_taken' => 'A fiscal year named :name already exists.',
    'name_with_count' => 'A name can only be given when creating a single fiscal year.',
    'not_ready' => 'The fiscal calendar is not ready: :problems.',

    'problems' => [
        'calendar_missing' => 'the fiscal calendar has not been set up',
        'no_fiscal_year' => 'no fiscal year has been created',
        'no_open_period' => 'there is no open period',
    ],

];
