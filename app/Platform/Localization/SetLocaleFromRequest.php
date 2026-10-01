<?php

namespace App\Platform\Localization;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Picks the response locale from Accept-Language among the supported locales (API-TR-06).
 * Only `message` texts follow the locale; codes, statuses and field names never do (API-AC-10).
 */
final class SetLocaleFromRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var list<string> $supported */
        $supported = config('localization.supported');
        $default = config('app.locale');

        $locale = $default;

        foreach ($request->getLanguages() as $language) {
            $candidate = strtolower(strtok(str_replace('_', '-', $language), '-') ?: '');

            if (in_array($candidate, $supported, true)) {
                $locale = $candidate;

                break;
            }
        }

        App::setLocale($locale);

        $response = $next($request);

        $response->headers->set('Content-Language', $locale);
        $response->headers->set('Vary', 'Accept-Language', false);

        return $response;
    }
}
