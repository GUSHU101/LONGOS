<!doctype html>
<html lang="{{ locale() }}">
  <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <base href="{{ $shop_base_url }}">

    <!-- Title and Meta Description -->
    <title>@yield('title', site_brand_meta_title())</title>
    <meta name="keywords" content="@yield('keywords', system_setting('base.meta_keywords'))">
    <meta name="description" content="@yield('description', site_brand_meta_description())">
    <link rel="canonical" href="@yield('canonical', storefront_canonical_url())">

    <!-- Open Graph Meta Tags -->
    <meta property="og:site_name" content="{{ site_brand_name() }}">
    <meta property="og:url" content="@yield('canonical', storefront_canonical_url())">
    <meta property="og:title" content="@yield('title', site_brand_meta_title())">
    <meta property="og:type" content="@yield('og_type', 'website')">
    <meta property="og:description" content="@yield('description', site_brand_meta_description())">
    <meta property="og:image" content="@yield('og_image', image_origin(site_brand_asset('shop_logo')))">
    <meta property="og:image:secure_url" content="@yield('og_image', image_origin(site_brand_asset('shop_logo')))">
    <meta property="og:image:width" content="@yield('og_image_width', '300')">
    <meta property="og:image:height" content="@yield('og_image_height', '300')">
    <meta property="og:image:alt" content="@yield('title', site_brand_meta_title())">

    <!-- Twitter Card Meta Tags -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="@yield('title', site_brand_meta_title())">
    <meta name="twitter:description" content="@yield('description', site_brand_meta_description())">
    <meta name="twitter:image" content="@yield('og_image', image_origin(site_brand_asset('shop_logo')))">
    <meta name="twitter:image:alt" content="@yield('title', site_brand_meta_title())">

    <!-- Generator Meta -->
    <meta name="generator" content="{{ site_brand_name() }} Commerce">

    <!-- Favicon -->
    <link rel="shortcut icon" href="{{ image_origin(site_brand_asset('favicon')) }}">

    <!-- CSS and JS -->
    @php
      $activeTheme = (string) system_setting('base.theme', 'default');
      $themeBuild  = is_file(public_path("build/beike/shop/{$activeTheme}/css/app.css")) ? $activeTheme : 'default';
      $themeStyle  = $activeTheme !== 'default' && is_file(public_path("build/beike/shop/{$activeTheme}/css/theme.css"))
        ? asset("build/beike/shop/{$activeTheme}/css/theme.css")
        : null;
      $globalStyle = \Beike\Services\BuilderStateService::storefrontState()['design']['global_style'];
      // A 16px minimum preserves readability when a merchant uses an older
      // design preset that stored a smaller default font size.
      $storeFontSize  = max(16, (int) ($globalStyle['font_size'] ?? 16));
      $storeFontScale = number_format($storeFontSize / 15, 4, '.', '');
      $fontFamilies   = [
        // Jost is self-hosted by the default theme and gives the international
        // storefront a consistent editorial rhythm without a third-party CDN.
        'system'  => '"Jost","Avenir Next","Helvetica Neue",Arial,"PingFang SC","Microsoft YaHei",sans-serif',
        'serif'   => 'Iowan Old Style,"Noto Serif SC","Songti SC",serif',
        'rounded' => '"Jost",ui-rounded,"Arial Rounded MT Bold","PingFang SC",sans-serif',
      ];
      $storeFontFamily = $fontFamilies[$globalStyle['font_family'] ?? 'system'] ?? $fontFamilies['system'];
    @endphp
    <link rel="stylesheet" type="text/css" href="{{ mix('/build/beike/shop/'.$themeBuild.'/css/bootstrap.css') }}">
    <script src="{{ asset('vendor/jquery/jquery-3.6.0.min.js') }}"></script>
    <script src="{{ asset('vendor/layer/3.5.1/layer.js') }}"></script>
    <script src="{{ asset('vendor/lazysizes/lazysizes.min.js') }}" defer></script>
    <script src="{{ asset('vendor/bootstrap/js/bootstrap.bundle.min.js') }}" defer></script>
    <script src="{{ mix('/build/beike/shop/'.$themeBuild.'/js/app.js') }}"></script>
    <link rel="stylesheet" type="text/css" href="{{ mix('/build/beike/shop/'.$themeBuild.'/css/app.css') }}">
    @if ($themeStyle)
      <link rel="stylesheet" type="text/css" href="{{ $themeStyle }}">
    @endif
    <style id="storefront-global-typography">
      :root {
        --store-font-size: {{ $storeFontSize }}px;
        --store-font-scale: {{ $storeFontScale }};
        --store-font-family: {!! $storeFontFamily !!};
        --store-primary: {{ $globalStyle['primary_color'] }};
        --store-accent: {{ $globalStyle['accent_color'] }};
        --store-background: {{ $globalStyle['background_color'] }};
        --store-text: {{ $globalStyle['text_color'] }};
        --store-radius: {{ (int) $globalStyle['border_radius'] }}px;
        --store-page-width: {{ (int) $globalStyle['page_width'] }}px;
        --store-section-spacing: {{ (int) $globalStyle['section_spacing'] }}px;
        --store-grid-gap: {{ (int) $globalStyle['grid_gap'] }}px;
        --bs-primary: {{ $globalStyle['primary_color'] }};
      }
      body { background-color: var(--commerce-paper, var(--store-background)); color: var(--commerce-ink, var(--store-text)); font-family: var(--store-font-family); font-size: max(1rem, var(--store-font-size)); }
      body[data-store-card-style="card"] .product-wrap { background: #fff; border-radius: var(--store-radius); box-shadow: 0 8px 24px rgba(16,24,40,.08); overflow: hidden; }
      body[data-store-reduce-motion="true"] *, body[data-store-reduce-motion="true"] *::before, body[data-store-reduce-motion="true"] *::after { animation-duration: .01ms !important; animation-iteration-count: 1 !important; scroll-behavior: auto !important; transition-duration: .01ms !important; }
      a { color: var(--commerce-accent, var(--store-primary)); }
      .btn-primary { --bs-btn-bg: var(--commerce-accent, var(--store-primary)); --bs-btn-border-color: var(--commerce-accent, var(--store-primary)); --bs-btn-hover-bg: var(--commerce-accent-dark, color-mix(in srgb, var(--store-primary) 86%, #000)); --bs-btn-hover-border-color: var(--commerce-accent-dark, color-mix(in srgb, var(--store-primary) 86%, #000)); border-radius: var(--store-radius); }
    </style>
    @include('shared.branding-styles')

    @if (system_setting('base.head_code'))
      {!! system_setting('base.head_code') !!}
    @endif

    @hook('layout.header.code')
    @stack('header')

    @renderStyles
    @renderScripts
  </head>
  <body class="@yield('body-class')" data-store-card-style="{{ $globalStyle['card_style'] }}" data-store-reduce-motion="{{ $globalStyle['reduce_motion'] ? 'true' : 'false' }}">
  <a class="skip-to-content" href="#main-content">{{ __('common.view') }}</a>
  @if(trim($__env->yieldContent('bk-page-loading')))
    @include('shared.bk-page-loading')
  @endif
  @php
    $minimalCheckout = trim($__env->yieldContent('minimal-checkout')) === '1';
  @endphp
  @if (!request('iframe') && ! $minimalCheckout)
    @hook('layout.master.header.before')
    @include('layout.header')
    @hook('layout.master.header.after')
  @endif

  <main id="main-content" tabindex="-1">
    @hook('layout.master.content.before')
    @yield('content')
    @hook('layout.master.content.after')
  </main>

  @if (!request('iframe') && ! $minimalCheckout)
    @hook('layout.master.footer.before')
    @include('layout.footer')
    @hook('layout.master.footer.after')
  @endif

  @php
    $storefrontConfig = [
      'productImageOriginWidth' => (int) system_setting('base.product_image_origin_width', 800),
      'productImageOriginHeight' => (int) system_setting('base.product_image_origin_height', 800),
    ];
    $storefrontRoutes = [
      'cartsIndex' => shop_route('carts.index'),
      'cartsMini' => shop_route('carts.mini'),
      'cartsStore' => shop_route('carts.store'),
      'cartsSelect' => shop_route('carts.select'),
      'cartsUnselect' => shop_route('carts.unselect'),
      'checkoutIndex' => shop_route('checkout.index'),
      'checkoutUpdate' => shop_route('checkout.update'),
      'checkoutConfirm' => shop_route('checkout.confirm'),
      'loginIndex' => shop_route('login.index'),
      'analyticsVisit' => shop_route('analytics.visit'),
    ];
  @endphp
  <script>
    @hook('layout.master.script.before')

    const config = {
      isLogin: !!{{ current_customer()->id ?? 'null' }},
      guestCheckout: !!{{ system_setting('base.guest_checkout', 1) }},
      loginShowPrice: !!{{ system_setting('base.show_price_after_login', 0) }},
      productImageOriginWidth: {!! json_encode($storefrontConfig['productImageOriginWidth'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!},
      productImageOriginHeight: {!! json_encode($storefrontConfig['productImageOriginHeight'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!},
    }

    // Use generated URLs for state-changing storefront calls. These preserve the
    // active locale prefix instead of relying on the browser's root path.
    window.longosStorefrontRoutes = {!! json_encode($storefrontRoutes, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) !!};

    // 如果页面使用了ElementUI，且当前语言不是中文，则加载对应的语言包
    @if (locale() != 'zh_cn')
    if (typeof ELEMENT !== 'undefined') {
        const elLocale = '{{ asset('vendor/element-ui/language/'.locale().'.js') }}';
        document.write("<script src='" + elLocale + "'><\/script>")

        $(function () {
          setTimeout(() => {
            ELEMENT.locale(ELEMENT.lang['{{ locale() }}'])
          }, 0);
        })
      }
    @endif
  </script>

  @if (str_contains(strtolower((string) ($_SERVER['SERVER_SOFTWARE'] ?? '')), 'nginx'))
    <div class="nginx-alert d-none">{!! __('shop/common.nginx_alert') !!}</div>
  @endif

  <div id="storefront-feedback" class="visually-hidden" role="status" aria-live="polite" aria-atomic="true"></div>

  @stack('add-scripts')
  @hook('layout.master.footer.code')
</body>
<!-- {{ site_brand_name() }} Commerce -->
</html>
