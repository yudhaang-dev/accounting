<?php

namespace App\Platform\Export;

use Illuminate\Http\Request;

/** The file formats a report can be downloaded in, chosen by the `Accept` header (API-TR-09). */
enum ExportFormat: string
{
    case Csv = 'text/csv';
    case Xlsx = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    case Pdf = 'application/pdf';

    public static function wanted(Request $request): ?self
    {
        $accept = (string) $request->header('Accept');

        foreach (self::cases() as $format) {
            if (str_contains($accept, $format->value)) {
                return $format;
            }
        }

        return null;
    }

    public function extension(): string
    {
        return match ($this) {
            self::Csv => 'csv',
            self::Xlsx => 'xlsx',
            self::Pdf => 'pdf',
        };
    }
}
