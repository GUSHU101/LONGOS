<header class="christmas-header" data-christmas-header>
  @php
    $headerAds = \Beike\Services\BuilderStateService::storefrontState()['header']['header_ads'];
  @endphp

  @if ($headerAds['active'] && $headerAds['items'])
    <div class="christmas-announcement" style="--announcement-background: {{ $headerAds['bg_color'] }}; --announcement-color: {{ $headerAds['color'] }};">
      <div class="christmas-shell christmas-announcement__track">
        @foreach ($headerAds['items'] as $announcement)
          @php
            $announcementTitle = is_array($announcement['title'] ?? null) ? ($announcement['title'][locale()] ?? $announcement['title']['en'] ?? reset($announcement['title']) ?: '') : '';
            $announcementUrl = '';
            try { $announcementUrl = handle_link($announcement['link'] ?? [])['link'] ?? ''; } catch (\Throwable) { $announcementUrl = ''; }
          @endphp
          @if ($announcementTitle !== '')
            @if ($announcementUrl)
              <a href="{{ $announcementUrl }}" @if(($announcement['link']['new_window'] ?? false) === true) target="_blank" rel="noopener noreferrer" @endif>{{ $announcementTitle }}</a>
            @else
              <span>{{ $announcementTitle }}</span>
            @endif
          @endif
        @endforeach
      </div>
    </div>
  @endif

  @hook('header.before')

  <div class="header-content christmas-header__desktop d-none d-lg-block">
    <div class="christmas-shell christmas-header__main">
      @hookwrapper('header.menu.logo')
      <a class="christmas-header__logo" href="{{ shop_route('home.index') }}" aria-label="{{ site_brand_name() }}">
        <img src="{{ image_origin(site_brand_asset('shop_logo')) }}" alt="{{ site_brand_name() }}">
      </a>
      @endhookwrapper

      <nav class="christmas-header__navigation" aria-label="{{ trans('LongosChristmasTheme::theme.shop') }}">
        @include('shared.menu-pc')
      </nav>

      <div class="christmas-header__actions">
        @hookwrapper('header.menu.icon')
        <button type="button" class="christmas-icon-button" data-bs-toggle="offcanvas" data-bs-target="#offcanvas-search-top" aria-label="{{ trans('LongosChristmasTheme::theme.search') }}">
          <img src="{{ asset('image/icons/search.svg') }}" alt="">
        </button>
        <div class="dropdown account-menu-wrap">
          <a href="{{ shop_route('account.index') }}" class="christmas-icon-button account-menu-trigger" aria-label="{{ trans('LongosChristmasTheme::theme.account') }}" aria-haspopup="true" aria-expanded="false" aria-controls="christmas-account-menu">
            <img src="{{ asset('image/icons/account.svg') }}" alt="">
          </a>
          <ul id="christmas-account-menu" class="dropdown-menu account-menu">
            @auth('web_shop')
              <li class="christmas-account-name"><small>{{ __('shop/account.index') }}</small><strong>{{ current_customer()->name }}</strong></li>
              <li><a class="dropdown-item" href="{{ shop_route('account.index') }}">{{ __('shop/account.index') }}</a></li>
              <li><a class="dropdown-item" href="{{ shop_route('account.order.index') }}">{{ __('shop/account/order.index') }}</a></li>
              <li><a class="dropdown-item" href="{{ shop_route('logout') }}">{{ __('common.sign_out') }}</a></li>
            @else
              <li><a class="dropdown-item" href="{{ shop_route('login.index') }}">{{ __('shop/login.login_and_sign') }}</a></li>
            @endauth
          </ul>
        </div>
        @endhookwrapper
        <button type="button" class="christmas-icon-button btn-right-cart {{ equal_route('shop.carts.index') ? 'page-cart' : '' }}" aria-label="{{ trans('LongosChristmasTheme::theme.cart') }}">
          <img src="{{ asset('image/icons/cart.svg') }}" alt="">
          <span class="cart-badge-quantity"></span>
        </button>
      </div>
    </div>
  </div>

  <div class="header-mobile christmas-header__mobile d-lg-none">
    <div class="christmas-header__mobile-bar">
      <button type="button" class="christmas-icon-button mobile-open-menu" aria-label="{{ __('shop/header.open_menu') }}" aria-controls="offcanvas-mobile-menu" aria-expanded="false">
        <img src="{{ asset('image/icons/menu.svg') }}" alt="">
      </button>
      <a class="christmas-header__logo" href="{{ shop_route('home.index') }}" aria-label="{{ site_brand_name() }}">
        <img src="{{ image_origin(site_brand_asset('shop_logo')) }}" alt="{{ site_brand_name() }}">
      </a>
      <div class="christmas-header__mobile-actions">
        <button type="button" class="christmas-icon-button" data-bs-toggle="offcanvas" data-bs-target="#offcanvas-search-top" aria-label="{{ trans('LongosChristmasTheme::theme.search') }}">
          <img src="{{ asset('image/icons/search.svg') }}" alt="">
        </button>
        <a href="{{ shop_route('carts.index') }}" class="christmas-icon-button m-cart" aria-label="{{ trans('LongosChristmasTheme::theme.cart') }}">
          <img src="{{ asset('image/icons/cart.svg') }}" alt=""><span class="cart-badge-quantity"></span>
        </a>
      </div>
    </div>
  </div>

  <div class="offcanvas offcanvas-start christmas-drawer" tabindex="-1" id="offcanvas-mobile-menu" role="dialog" aria-modal="true" aria-labelledby="christmas-mobile-menu-title">
    <div class="christmas-drawer__header">
      <a class="christmas-drawer__brand" href="{{ shop_route('home.index') }}"><img src="{{ image_origin(site_brand_asset('shop_logo')) }}" alt="{{ site_brand_name() }}"></a>
      <h2 id="christmas-mobile-menu-title">{{ trans('LongosChristmasTheme::theme.menu') }}</h2>
      <button type="button" class="christmas-drawer__close" data-bs-dismiss="offcanvas" aria-label="{{ __('shop/header.close_menu') }}"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
    </div>
    <div class="offcanvas-body christmas-drawer__body mobile-menu-wrap">
      <span id="mobile-navigation-label" class="christmas-drawer__eyebrow">{{ trans('LongosChristmasTheme::theme.season') }}</span>
      @include('shared.menu-mobile')
    </div>
    <div class="christmas-drawer__footer">
      <a href="{{ shop_route('tracking.index') }}"><i class="bi bi-truck" aria-hidden="true"></i>{{ __('shop/tracking.title') }}</a>
      <a href="{{ shop_route('account.index') }}"><i class="bi bi-person" aria-hidden="true"></i>{{ trans('LongosChristmasTheme::theme.account') }}</a>
    </div>
  </div>

  <div class="offcanvas offcanvas-end" tabindex="-1" id="offcanvas-right-cart" aria-labelledby="offcanvasRightLabel"></div>
  <x-shop-search-popover />
  @hook('header.after')
</header>

@push('add-scripts')
  <script src="{{ asset('build/beike/shop/longos_christmas/js/theme.js') }}" defer></script>
@endpush
