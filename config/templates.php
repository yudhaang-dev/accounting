<?php

return [

    /*
    | Where the manifests of the template packs live (ADR-0019). A pack not yet reviewed by an accountant
    | is not offered.
    */

    'packs_path' => env('TEMPLATE_PACKS_PATH', app_path('Orchestration/Setup/packs')),

];
