<header>
  @php
    // Keep this as a full Blade PHP block. A compact directive
    // cannot safely parse the nested system_setting() call plus array access
    // and previously produced malformed cached PHP on the storefront.
    $headerAds = \Beike\Services\BuilderStateService::storefrontState()['header']['header_ads'];
  @endphp
  @if ($headerAds['active'] && $headerAds['items'])
    <div class="store-announcement-bar" style="--announcement-background: {{ $headerAds['bg_color'] }}; --announcement-color: {{ $headerAds['color'] }};">
      <div class="container-fluid store-announcement-bar__items">
        @foreach ($headerAds['items'] as $announcement)
          @php
            $announcementTitle = is_array($announcement['title'] ?? null) ? ($announcement['title'][locale()] ?? $announcement['title']['en'] ?? reset($announcement['title']) ?: '') : '';
            $announcementUrl = '';
            try { $announcementUrl = handle_link($announcement['link'] ?? [])['link'] ?? ''; } catch (\Throwable) { $announcementUrl = ''; }
          @endphp
          @if ($announcementTitle !== '')
            @if ($announcementUrl)
              <a class="store-announcement-bar__item" href="{{ $announcementUrl }}" @if(($announcement['link']['new_window'] ?? false) === true) target="_blank" rel="noopener noreferrer" @endif>{{ $announcementTitle }}</a>
            @else
              <span class="store-announcement-bar__item">{{ $announcementTitle }}</span>
            @endif
          @endif
        @endforeach
      </div>
    </div>
  @endif
  @hook('header.before')

  <div class="header-content d-none d-lg-block">
    <div class="container-fluid navbar-expand-lg">
      @hookwrapper('header.menu.logo')
      <div class="logo"><a href="{{ shop_route('home.index') }}">
          <img src="{{ image_origin(site_brand_asset('shop_logo')) }}" class="img-fluid" alt="{{ site_brand_name() }}"></a>
      </div>
      @endhookwrapper
      <div class="menu-wrap">
        @include('shared.menu-pc')
      </div>
      <div class="right-btn">
        <ul class="navbar-nav flex-row">
          @hookwrapper('header.menu.icon')
          <li class="nav-item"><a href="#offcanvas-search-top" data-bs-toggle="offcanvas" class="nav-link" aria-label="{{ __('common.input') }}"><img src="{{ asset('image/icons/search.svg') }}" class="img-fluid" alt=""></a></li>
          <li class="nav-item dropdown account-menu-wrap">
            <a href="{{ shop_route('account.index') }}" class="nav-link account-menu-trigger" aria-label="{{ __('shop/account.index') }}" aria-haspopup="true" aria-expanded="false" aria-controls="header-account-menu"><img src="{{ asset('image/icons/account.svg') }}" class="img-fluid" alt=""></a>
            <ul id="header-account-menu" class="dropdown-menu account-menu" aria-label="{{ __('shop/account.index') }}">
              @auth('web_shop')
                <li class="account-menu__identity">
                  <span class="account-menu__eyebrow">{{ __('shop/account.index') }}</span>
                  <a href="{{ shop_route('account.index') }}" class="account-menu__name">{{ current_customer()->name }}</a>
                </li>
                <li><a href="{{ shop_route('account.index') }}" class="dropdown-item"><i class="bi bi-person" aria-hidden="true"></i>
                    {{ __('shop/account.index') }}</a></li>
                <li><a href="{{ shop_route('account.order.index') }}" class="dropdown-item"><i
                      class="bi bi-clipboard-check" aria-hidden="true"></i> {{ __('shop/account/order.index') }}</a></li>
                @hook('header.menu.icon.dropdown.menu.order.after')
                <li class="account-menu__separator" aria-hidden="true"></li>
                <li><a href="{{ shop_route('logout') }}" class="dropdown-item account-menu__signout"><i class="bi bi-box-arrow-left" aria-hidden="true"></i>
                    {{ __('common.sign_out') }}</a></li>
              @else
                <li><a href="{{ shop_route('login.index') }}" class="dropdown-item account-menu__login"><i
                      class="bi bi-box-arrow-right" aria-hidden="true"></i>{{ __('shop/login.login_and_sign') }}</a></li>
              @endauth
            </ul>
          </li>
          @endhookwrapper
          <li class="nav-item">
            <button
              type="button"
              class="nav-link position-relative btn-right-cart {{ equal_route('shop.carts.index') ? 'page-cart' : '' }}"
              aria-label="{{ __('shop/carts.index') }}">
              <img src="{{ asset('image/icons/cart.svg') }}" class="img-fluid" alt="">
              <span class="cart-badge-quantity"></span>
            </button>
          </li>
        </ul>
      </div>
    </div>
  </div>

  <div class="header-mobile d-lg-none">
    <div class="mobile-content">
      <div class="left">
        <button
          type="button"
          class="mobile-open-menu border-0 bg-transparent p-0"
          aria-label="{{ __('shop/header.open_menu') }}"
          aria-controls="offcanvas-mobile-menu"
          aria-expanded="false">
          <img src="{{ asset('image/icons/menu.svg') }}" alt="" class="img-fluid">
        </button>
        <button type="button" class="mobile-open-search border-0 bg-transparent p-0" data-bs-toggle="offcanvas" data-bs-target="#offcanvas-search-top" aria-label="{{ __('common.input') }}">
             <img src="{{ asset('image/icons/search.svg') }}" class="img-fluid" alt="">
        </button>
      </div>
      <div class="center"><a href="{{ shop_route('home.index') }}">
          <img src="{{ image_origin(site_brand_asset('shop_logo')) }}" class="img-fluid" alt="{{ site_brand_name() }}"></a>
      </div>
      <div class="right">
        <a href="{{ shop_route('account.index') }}" class="nav-link mb-account-icon" aria-label="{{ __('shop/account.index') }}">
          <img src="{{ asset('image/icons/account.svg') }}" class="img-fluid" alt="">
          @if (strstr(current_route(), 'shop.account'))
            <span></span>
          @endif
        </a>
        <a href="{{ shop_route('carts.index') }}" class="nav-link ms-3 m-cart position-relative" aria-label="{{ __('shop/carts.index') }}"><img src="{{ asset('image/icons/cart.svg') }}" alt="" class="img-fluid">
          <span class="cart-badge-quantity"></span></a>
      </div>
    </div>
  </div>
  <div
    class="offcanvas offcanvas-start mobile-nav-drawer"
    tabindex="-1"
    id="offcanvas-mobile-menu"
    role="dialog"
    aria-modal="true"
    aria-labelledby="offcanvas-mobile-menu-title"
    data-bs-scroll="false">
    <div class="mobile-nav-drawer__topbar">
      <div class="mobile-nav-drawer__brand-wrap">
        <a href="{{ shop_route('home.index') }}" class="mobile-nav-drawer__brand" aria-label="{{ site_brand_name() }}">
          <img src="{{ image_origin(site_brand_asset('shop_logo')) }}" alt="{{ site_brand_name() }}">
        </a>
        <h2 id="offcanvas-mobile-menu-title">{{ __('shop/header.menu_title') }}</h2>
      </div>
      <button type="button" class="mobile-nav-drawer__close" data-bs-dismiss="offcanvas" aria-label="{{ __('shop/header.close_menu') }}">
        <i class="bi bi-x-lg" aria-hidden="true"></i>
      </button>
    </div>

    <div class="offcanvas-body mobile-menu-wrap mobile-nav-drawer__body">
      <div class="mobile-nav-drawer__section-heading">
        <span id="mobile-navigation-label">{{ __('shop/header.shop_navigation') }}</span>
      </div>
      @include('shared.menu-mobile')
    </div>

    <div class="mobile-nav-drawer__footer">
      @auth('web_shop')
        <a href="{{ shop_route('account.index') }}" class="mobile-nav-account">
          <span class="mobile-nav-account__icon"><i class="bi bi-person" aria-hidden="true"></i></span>
          <span class="mobile-nav-account__copy">
            <small>{{ __('shop/header.signed_in_as') }}</small>
            <strong>{{ current_customer()->name }}</strong>
          </span>
          <i class="bi bi-arrow-up-right" aria-hidden="true"></i>
        </a>
      @else
        <a href="{{ shop_route('login.index') }}" class="mobile-nav-account">
          <span class="mobile-nav-account__icon"><i class="bi bi-person" aria-hidden="true"></i></span>
          <span class="mobile-nav-account__copy">
            <small>{{ __('shop/header.customer_account') }}</small>
            <strong>{{ __('shop/header.sign_in') }}</strong>
          </span>
          <i class="bi bi-arrow-up-right" aria-hidden="true"></i>
        </a>
      @endauth
    </div>
  </div>

  <div class="offcanvas offcanvas-end" tabindex="-1" id="offcanvas-right-cart" aria-labelledby="offcanvasRightLabel"></div>

  <x-shop-search-popover />

  @hook('header.after')
</header>
