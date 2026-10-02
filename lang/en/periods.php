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
    'start_month_required' => 'A monthly calendar needs the month a fiscal year starts.',
    'start_month_monthly_only' => 'Only a monthly calendar has a start month; the other patterns start on the date of the first fiscal year.',
    'periods_required' => 'A free calendar needs the periods of the fiscal year.',
    'periods_only_free' => 'Periods can only be given for a free calendar.',
    'free_single_year' => 'A free calendar creates one fiscal year at a time, with the periods you give.',
    'extra_week_only' => 'An extra week is only for a single 4-4-5 fiscal year.',
    'period_end_before_start' => 'A period cannot end before it starts.',
    'period_not_contiguous' => 'Periods must follow each other without gaps; this one must start on :date.',
    'invalid_transition' => 'A period that is :from cannot become :to.',
    'not_ready' => 'The fiscal calendar is not ready: :problems.',

    'problems' => [
        'calendar_missing' => 'the fiscal calendar has not been set up',
        'no_fiscal_year' => 'no fiscal year has been created',
        'no_open_period' => 'there is no open period',
    ],

];
