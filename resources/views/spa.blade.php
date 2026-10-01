<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @class(['dark' => ($appearance ?? 'system') === 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ config('app.name') }}</title>

        {{-- Apply the stored appearance before first paint to avoid a light flash in dark mode. --}}
        <script>
            (function () {
                var appearance = '{{ $appearance ?? 'system' }}';
                var dark = appearance === 'dark' || (appearance === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
                document.documentElement.classList.toggle('dark', dark);
                document.documentElement.style.colorScheme = dark ? 'dark' : 'light';
            })();
        </script>

        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.tsx'])
    </head>
    <body class="min-h-svh bg-background font-sans antialiased">
        <div id="root"></div>
        <noscript>{{ config('app.name') }} needs JavaScript to run.</noscript>
    </body>
</html>
