<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow,noarchive">
  <title>{{ trans('LongosBotShield::shield.blocked_title') }}</title>
  <style nonce="{{ $cspNonce }}">
    :root { color-scheme: light dark; font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
    * { box-sizing: border-box; }
    body { min-height: 100vh; margin: 0; display: grid; place-items: center; padding: 24px; background: #f4f7fb; color: #172033; }
    main { width: min(100%, 560px); padding: 36px; border: 1px solid #dfe6ef; border-radius: 20px; background: #fff; box-shadow: 0 24px 70px rgba(30, 50, 80, .12); }
    .icon { width: 52px; height: 52px; display: grid; place-items: center; border-radius: 14px; background: #fff0f0; color: #b42318; font-size: 25px; font-weight: 800; }
    h1 { margin: 22px 0 10px; font-size: clamp(24px, 5vw, 34px); line-height: 1.15; }
    p { margin: 0; color: #5f6b7b; line-height: 1.7; }
    code { display: inline-block; margin-top: 22px; padding: 8px 11px; border-radius: 8px; background: #f0f3f7; color: #344054; font: 600 13px/1.2 ui-monospace, SFMono-Regular, Consolas, monospace; letter-spacing: .08em; }
    @media (prefers-color-scheme: dark) { body { background: #0c111b; color: #edf2f7; } main { border-color: #263244; background: #141c29; } p { color: #aab5c5; } code { background: #202b3b; color: #d9e2ef; } }
  </style>
</head>
<body>
  <main role="main" aria-labelledby="shield-title">
    <div class="icon" aria-hidden="true">!</div>
    <h1 id="shield-title">{{ trans('LongosBotShield::shield.blocked_title') }}</h1>
    <p>{{ trans('LongosBotShield::shield.blocked_message') }}</p>
    <code>{{ $reference }}</code>
  </main>
</body>
</html>
