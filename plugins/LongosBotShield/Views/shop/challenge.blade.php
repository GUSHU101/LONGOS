<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow,noarchive">
  <title>{{ trans('LongosBotShield::shield.challenge_title') }}</title>
  <style nonce="{{ $cspNonce }}">
    :root { color-scheme: light dark; font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
    * { box-sizing: border-box; }
    body { min-height: 100vh; margin: 0; display: grid; place-items: center; padding: 24px; background: #f4f7fb; color: #172033; }
    main { width: min(100%, 560px); padding: 36px; border: 1px solid #dfe6ef; border-radius: 20px; background: #fff; box-shadow: 0 24px 70px rgba(30, 50, 80, .12); }
    .spinner { width: 48px; height: 48px; border: 4px solid #dce6f3; border-top-color: #246bfd; border-radius: 50%; animation: spin .9s linear infinite; }
    h1 { margin: 22px 0 10px; font-size: clamp(24px, 5vw, 34px); line-height: 1.15; }
    p { margin: 0; color: #5f6b7b; line-height: 1.7; }
    code { display: inline-block; margin-top: 22px; padding: 8px 11px; border-radius: 8px; background: #f0f3f7; color: #344054; font: 600 13px/1.2 ui-monospace, SFMono-Regular, Consolas, monospace; letter-spacing: .08em; }
    @keyframes spin { to { transform: rotate(360deg); } }
    @media (prefers-reduced-motion: reduce) { .spinner { animation-duration: 2s; } }
    @media (prefers-color-scheme: dark) { body { background: #0c111b; color: #edf2f7; } main { border-color: #263244; background: #141c29; } p { color: #aab5c5; } code { background: #202b3b; color: #d9e2ef; } }
  </style>
</head>
<body>
  <main role="main" aria-labelledby="shield-title">
    <div class="spinner" aria-hidden="true"></div>
    <h1 id="shield-title">{{ trans('LongosBotShield::shield.challenge_title') }}</h1>
    <p id="shield-message">{{ trans('LongosBotShield::shield.challenge_message') }}</p>
    <code>{{ $reference }}</code>
    <noscript><p>{{ trans('LongosBotShield::shield.challenge_failed') }}</p></noscript>
  </main>
  <script nonce="{{ $cspNonce }}">
    (() => {
      const ua = navigator.userAgent.toLowerCase();
      const hardSignal = navigator.webdriver === true
        || Boolean(window.callPhantom)
        || Boolean(window._phantom)
        || Boolean(window.__nightmare)
        || /headlesschrome|phantomjs|slimerjs|playwright|puppeteer|selenium/.test(ua);
      const softSignals = [
        !navigator.languages || navigator.languages.length === 0,
        screen.width === 0 || screen.height === 0,
        window.outerWidth === 0 || window.outerHeight === 0,
        /chrome\//.test(ua) && !window.chrome,
      ].filter(Boolean).length;
      const automated = hardSignal || softSignals >= 2;
      if (automated) {
        document.querySelector('.spinner').style.display = 'none';
        document.getElementById('shield-message').textContent = @json(trans('LongosBotShield::shield.challenge_failed'));
        return;
      }
      const token = @json($challengeToken);
      const fail = () => {
        document.querySelector('.spinner').style.display = 'none';
        document.getElementById('shield-message').textContent = @json(trans('LongosBotShield::shield.challenge_failed'));
      };
      window.setTimeout(async () => {
        try {
          const controller = new AbortController();
          const timeout = window.setTimeout(() => controller.abort(), 3000);
          let response;
          try {
            response = await fetch(location.href, {
              method: 'GET',
              headers: {
                'X-Longos-Bot-Challenge-Proof': token,
                'Accept': 'application/json'
              },
              credentials: 'same-origin',
              cache: 'no-store',
              redirect: 'error',
              signal: controller.signal,
            });
          } finally {
            window.clearTimeout(timeout);
          }
          if (response.status !== 204) return fail();
          location.reload();
        } catch (_) {
          fail();
        }
      }, 50);
    })();
  </script>
</body>
</html>
