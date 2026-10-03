<?php

return [

    /* Disk the files of attachments are kept on (X-FR-04). It must be private: files leave only through the API. */
    'disk' => env('ATTACHMENTS_DISK', 'local'),

    /* Largest file accepted, in kilobytes. */
    'max_kilobytes' => (int) env('ATTACHMENTS_MAX_KB', 10240),

    /* File types accepted, by extension; the content must match. */
    'types' => ['pdf', 'png', 'jpg', 'jpeg', 'csv', 'txt', 'xlsx', 'docx'],

];
