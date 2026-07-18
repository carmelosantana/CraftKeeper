<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @class(['dark' => ($appearance ?? 'system') == 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        {{-- Inline script to detect system dark mode preference and apply it immediately --}}
        <script>
            (function() {
                const appearance = '{{ $appearance ?? "system" }}';

                if (appearance === 'system') {
                    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

                    if (prefersDark) {
                        document.documentElement.classList.add('dark');
                    }
                }
            })();
        </script>

        {{-- Inline style to set the HTML background color based on our theme in app.css --}}
        <style>
            html {
                background-color: oklch(1 0 0);
            }

            html.dark {
                background-color: oklch(0.145 0 0);
            }
        </style>

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        @fonts

        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.tsx', "resources/js/pages/{$page['component']}.tsx"])
        <x-inertia::head>
            <title>{{ config('app.name', 'Laravel') }}</title>
        </x-inertia::head>

        {{--
            Task 19's optional Umami analytics tag (App\Support\UmamiScript).
            Resolved and echoed directly here — never through a controller
            prop or an Inertia shared prop — so it renders identically
            whether the request is a full page load or an Inertia partial
            reload, and so that disabling it is a one-line `@if` with
            nothing else in this template to keep in sync. When disabled
            (the default), this emits nothing at all: no <script>, no
            comment mentioning analytics, nothing an
            `assertDontSee('umami')`-style test could ever catch.
        --}}
        {!! app(\App\Support\UmamiScript::class)->tag() !!}
    </head>
    <body class="font-sans antialiased">
        <x-inertia::app />
    </body>
</html>
