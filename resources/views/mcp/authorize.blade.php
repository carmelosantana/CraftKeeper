<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <script>
        (function () {
            var prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            document.documentElement.setAttribute('data-theme', prefersDark ? 'dark' : 'light');
        })();
    </script>

    <title>Authorize MCP connection - {{ config('app.name', 'CraftKeeper') }}</title>

    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">

    @vite(['resources/css/app.css'])
</head>
<body style="margin: 0; font-family: system-ui, -apple-system, sans-serif; background-color: var(--ck-bg); color: var(--ck-text);">
<div style="min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px;">
    <div style="width: 100%; max-width: 480px; border-radius: 12px; border: 1px solid var(--ck-border); background-color: var(--ck-surface); box-shadow: var(--ck-shadow-e2); padding: 24px;">
        <h1 style="margin: 0 0 4px; font-size: 20px; font-weight: 700; color: var(--ck-text);">
            Authorize &quot;{{ $client->name }}&quot;
        </h1>
        <p style="margin: 0 0 16px; font-size: 12.5px; color: var(--ck-text-2);">
            An MCP client is requesting access to this CraftKeeper server on behalf of
            <strong style="color: var(--ck-text);">{{ $user->email }}</strong>.
            It can only PROPOSE the changes listed below — every proposal still requires a
            human to separately review and approve it in CraftKeeper before anything changes.
            It can never approve or apply anything itself.
        </p>

        @if (count($scopes) > 0)
            <div style="margin-bottom: 16px;">
                <p style="margin: 0 0 8px; font-size: 12.5px; font-weight: 700; color: var(--ck-text);">
                    This connection will be able to:
                </p>
                <ul style="margin: 0; padding: 0; list-style: none; display: grid; gap: 8px;">
                    @foreach ($scopes as $scope)
                        <li style="display: flex; gap: 8px; align-items: flex-start; border: 1px solid var(--ck-border); border-radius: 8px; padding: 10px;">
                            <span style="display: inline-block; width: 6px; height: 6px; margin-top: 6px; border-radius: 50%; background-color: var(--ck-accent); flex-shrink: 0;"></span>
                            <span style="font-size: 12px; color: var(--ck-text-2);">{{ $scope->description }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        <p style="margin: 0 0 16px; font-size: 11px; color: var(--ck-text-3);">
            CraftKeeper additionally enforces a separate scope ceiling configured on the
            Integrations &gt; MCP page for this connection — approving the request above can
            never grant this client more than that ceiling allows.
        </p>

        <div style="display: flex; gap: 12px;">
            <form method="POST" action="{{ route('passport.authorizations.deny') }}" style="flex: 1;">
                @csrf
                @method('DELETE')
                <input type="hidden" name="state" value="{{ $request->query('state') }}">
                <input type="hidden" name="client_id" value="{{ $client->id }}">
                <input type="hidden" name="auth_token" value="{{ $authToken }}">
                <button type="submit" style="width: 100%; padding: 10px 16px; border-radius: 8px; border: 1px solid var(--ck-border); background-color: var(--ck-surface); color: var(--ck-text); font-size: 13px; font-weight: 600; cursor: pointer;">
                    Deny
                </button>
            </form>

            <form method="POST" action="{{ route('passport.authorizations.approve') }}" style="flex: 1;">
                @csrf
                <input type="hidden" name="state" value="{{ $request->query('state') }}">
                <input type="hidden" name="client_id" value="{{ $client->id }}">
                <input type="hidden" name="auth_token" value="{{ $authToken }}">
                <button type="submit" style="width: 100%; padding: 10px 16px; border-radius: 8px; border: none; background-color: var(--ck-accent); color: var(--ck-accent-fg); font-size: 13px; font-weight: 600; cursor: pointer;">
                    Authorize
                </button>
            </form>
        </div>
    </div>
</div>
</body>
</html>
