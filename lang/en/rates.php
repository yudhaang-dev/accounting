<?php

// Messages of rate types and exchange rates (MD-FR-07, MD-FR-08).
return [

    'key_taken' => 'The rate type :key already exists in this company.',
    'type_unknown' => 'The rate type :key does not exist in this company.',
    'type_inactive' => 'The rate type :type is inactive, so no new rates can be recorded for it.',
    'functional_currency' => 'The functional currency :code needs no exchange rate.',
    'currency_not_active' => 'The currency :code is not activated for this company.',
    'not_found' => 'No :type rate for :currency on or before :date.',
    'too_old' => 'The latest :type rate for :currency on :date is from :effective, older than the limit of :days days.',
    'csv_required' => 'Send the CSV as a file or as a text/csv body.',
    'csv_header' => 'The first line must name the columns :columns.',
    'csv_empty' => 'The file has no rows.',
    'csv_too_many' => 'The file has more than :max rows.',
    'csv_too_large' => 'The file is larger than 1 MB.',
    'csv_duplicate' => 'This row repeats the rate of line :line.',

];
