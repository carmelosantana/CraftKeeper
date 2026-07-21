<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @class(['dark' => ($appearance ?? 'system') == 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        {{--
            Task 20: `$cspNonce` is shared into every view by
            App\Http\Middleware\ContentSecurityPolicy (the 'web' group
            only — see bootstrap/app.php) and matched against the
            `script-src 'nonce-...'` directive in the Content-Security-
            Policy response header. Falls back to an empty string so this
            template still renders (with a nonce attribute CSP simply
            won't match) on the rare path that isn't covered by that
            middleware.
        --}}
        {{-- Inline script to detect system dark mode preference and apply it immediately --}}
        <script nonce="{{ $cspNonce ?? '' }}">
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

        {{--
            The Reverb app key the browser needs to open its websocket.

            Emitted here, at RUNTIME, rather than through VITE_REVERB_APP_KEY.
            Vite inlines VITE_* at BUILD time, so a published image can only
            ever carry whatever key existed on the machine that built it —
            which is nobody's real key. Realtime therefore could not work in
            the published image at all, no matter how the operator configured
            the container. Rendered directly in this template (not via an
            Inertia shared prop) for the same reason the Umami tag below is:
            it must be identical on a full page load and on any Inertia
            partial reload, and `resources/js/lib/echo.ts` reads it during
            module boot, before Inertia has resolved a page.

            The app KEY is not a secret — it identifies a websocket client
            exactly as a Pusher app key does (see .env.example's own note
            beside REVERB_APP_KEY). REVERB_APP_SECRET is never exposed and is
            not referenced here.

            Absent unless Reverb is the active broadcaster AND a key exists,
            so its absence is the honest signal that realtime is off; echo.ts
            falls back to an inert connector and the UI reports "unavailable".
        --}}
        @if (config('broadcasting.default') === 'reverb' && filled(config('broadcasting.connections.reverb.key')))
            <meta name="craftkeeper-reverb-key" content="{{ config('broadcasting.connections.reverb.key') }}">
        @endif

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
