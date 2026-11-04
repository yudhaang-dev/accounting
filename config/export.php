<?php

return [

    /*
    | The most rows a PDF or Excel export holds (ADR-0018). A bigger report is refused with
    | EXPORT_TOO_LARGE; CSV streams and has no such limit.
    */

    'max_rows' => (int) env('EXPORT_MAX_ROWS', 5000),

];
