<!doctype html>
<html lang="{{ locale() }}">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>@yield('title', __('common.error_page'))</title>
  <style>
    :root { color-scheme: light; }
    * { box-sizing: border-box; }
    body { min-width: 320px; margin: 0; background: radial-gradient(circle at 0 0, #eaf1ff, transparent 34rem), #f8fafc; color: #111827; font-family: Jost, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
    .error-shell { display: grid; min-height: 100vh; place-items: center; padding: 24px; }
    .error-card { width: min(100%, 620px); padding: clamp(28px, 7vw, 64px); border: 1px solid #e4e7ec; border-radius: 24px; background: rgba(255,255,255,.96); box-shadow: 0 20px 50px rgba(16,24,40,.1); text-align: center; }
    .error-brand { display: inline-block; margin-bottom: 2rem; color: #155eef; font-size: .9rem; font-weight: 800; letter-spacing: .08em; text-decoration: none; text-transform: uppercase; }
    .error-code { margin: 0; color: #155eef; font-size: clamp(3.25rem, 14vw, 6.5rem); font-weight: 800; letter-spacing: -.08em; line-height: .95; }
    .error-title { margin: 1.35rem 0 .7rem; font-size: clamp(1.4rem, 4vw, 2rem); line-height: 1.2; }
    .error-message { margin: 0 auto 2rem; max-width: 42ch; color: #667085; font-size: 1rem; line-height: 1.65; }
    .error-action { display: inline-flex; min-height: 46px; align-items: center; justify-content: center; border-radius: 12px; background: #155eef; color: #fff; font-size: .95rem; font-weight: 700; padding: .7rem 1.15rem; text-decoration: none; transition: background-color .18s ease, transform .18s ease; }
    .error-action:hover { background: #004eeb; transform: translateY(-1px); }
    .error-action:focus-visible { outline: 3px solid rgba(21,94,239,.4); outline-offset: 4px; }
  </style>
</head>
<body>
  <main class="error-shell">
    <section class="error-card" aria-labelledby="error-title">
      @if (request()->is('installer') || request()->is('installer/*'))
        <span class="error-brand">LONGOS</span>
      @else
        <a class="error-brand" href="{{ shop_route('home.index') }}">{{ site_brand_name() }}</a>
      @endif
      <p class="error-code">@yield('code', '404')</p>
      <h1 id="error-title" class="error-title">@yield('title', __('common.error_page'))</h1>
      <div class="error-message">@yield('message', __('common.error_page'))</div>
      @if (request()->is('installer') || request()->is('installer/*'))
        <a class="error-action" href="/installer">Return to installer</a>
      @else
        <a class="error-action" href="{{ shop_route('home.index') }}" onclick="if (window.history.length > 1) { window.history.back(); return false; }">{{ __('common.error_page_btn') }}</a>
      @endif
    </section>
  </main>
</body>
</html>
