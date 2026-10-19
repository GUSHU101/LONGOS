<div class="header-content d-none d-lg-block">
  @hook('admin.header.before')
  <div class="header-wrap">
    <div class="header-left">
      @hook('admin.header.left.before')
      <div class="logo">
        <a href=""><img src="{{ image_origin(admin_brand_logo()) }}" class="img-fluid" alt="LONGOS"></a>
      </div>
      @hook('admin.header.left.after')
    </div>
    <div class="header-right">
      @hook('admin.header.right.before')

      @hookwrapper('admin.header.search')
      <div class="search-wrap">
        <div class="input-wrap">
          <div class="search-icon"><i class="bi bi-search"></i></div>
          <input type="text" id="header-search-input" autocomplete="off" class="form-control"
                 placeholder="{{ __('admin/common.header_search_input') }}">
          <button class="btn close-icon" type="button"><i class="bi bi-x-lg"></i></button>
        </div>

        <div class="dropdown-menu">
          <div class="search-ing"><i class="el-icon-loading"></i></div>
          <div class="dropdown-search">
            <div class="dropdown-header fw-bold">{{ __('admin/common.header_search_title') }}</div>
            <div class="common-links"></div>
            <div class="header-search-no-data"><i class="bi bi-file-earmark"></i> {{ __('common.no_data') }}</div>
          </div>
          <div class="dropdown-wrap">
            @if ($historyLinks)
              <div class="link-item recent-search">
                <div class="dropdown-header fw-bold mb-2">{{ __('admin/common.recent_view') }}</div>
                <div class="recent-search-links">
                  @foreach ($historyLinks as $link)
                    <a href="{{ $link['url'] }}"><i class="bi bi-search"></i> {{ $link['title'] }}</a>
                  @endforeach
                </div>
              </div>
            @endif
            <div class="link-item">
              <div class="dropdown-header fw-bold">{{ __('admin/common.common_link') }}</div>
              <div class="common-links">
                @foreach ($commonLinks as $link)
                  <a class="dropdown-item" href="{{ $link['url'] }}" target="{{ $link['blank'] ? '_blank' : '_self' }}">
                    <span><i class="{{ $link['icon'] }}"></i></span> {{ $link['title'] }}
                  </a>
                @endforeach
              </div>
            </div>
          </div>
        </div>
      </div>
      @endhookwrapper

      <ul class="navbar navbar-right">
        @hook('admin.header.navbar.before')

        <li
          class="nav-item mx-2 license-ok {{ !check_license() || Str::endsWith(config('app.url'), '.test') ? 'd-none' : '' }}">
          <div class="license-text">
            <img src="{{ asset('image/vip-icon.png') }}" class="img-fluid wh-30 me-1" alt="">
            @if (system_setting('base.license_expired_at'))
              <div>
                <div>{{ __('admin/common.license_bought_s') }}</div>
                @if (strtotime(system_setting('base.license_expired_at')) - time() <= 31 * 24 * 3600)
                  <div class="font-size-12 text-danger">{{ __('admin/common.expired_at') }}：<span class="license-expired-at">{{ date('Y-m-d', strtotime(system_setting('base.license_expired_at'))) }}</span></div>
                @endif
              </div>
            @else
              <span>{{ __('admin/common.license_bought') }}</span>
            @endif
          </div>
        </li>

        <li class="nav-item">
          <span class="nav-link text-nowrap">简体中文</span>
        </li>

        <li class="nav-item top-change-theme">
          <div class="dropdown">
            <a href="#" class="nav-link dropdown-toggle theme-icon" data-bs-toggle="dropdown">
              <i class="bi bi-circle-half fs-5"></i>
            </a>
            <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="bd-theme-text">
              <li class="dropdown-item py-2 cursor-pointer" data-bs-theme="light"><i class="bi bi-brightness-high"></i> {{ __('admin/common.text_light') }}</li>
              <li class="dropdown-item py-2 cursor-pointer" data-bs-theme="dark"><i class="bi bi-moon-stars-fill"></i> {{ __('admin/common.text_dark') }}</li>
              <li class="dropdown-item py-2 cursor-pointer" data-bs-theme="auto"><i class="bi bi-circle-half"></i> {{ __('admin/common.text_auto') }}</li>
            </ul>
          </div>
        </li>

        <li class="nav-item">
          <a target="_blank" rel="noopener noreferrer" href="{{ shop_route('home.index') }}" class="nav-link"><i class="bi bi-shop fs-5"></i></a>
        </li>

        @hookwrapper('admin.header.user')
        <li class="nav-item me-3">
          <div class="dropdown">
            <a href="#" class="nav-link dropdown-toggle" data-bs-toggle="dropdown">
              <span class="ml-2">{{ current_user()->name }}</span>
            </a>

            <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="dropdownMenuLink">
              <li>
                <a href="{{ admin_route('admin_users.index') }}" class="dropdown-item py-2">
                  <i class="bi bi-person-gear me-1"></i> {{ __('admin/common.admin_users_index') }}
                </a>
              </li>
              <li>
                <hr class="dropdown-divider">
              </li>
              <li>
                <a href="{{ admin_route('logout.index') }}" class="dropdown-item py-2">
                  <i class="bi bi-box-arrow-left me-1"></i> {{ __('common.sign_out') }}
                </a>
              </li>
            </ul>
          </div>
        </li>
        @endhookwrapper

        @hook('admin.header.navbar.after')
      </ul>

      @hook('admin.header.right.after')
    </div>
  </div>
  @hook('admin.header.after')
</div>

<div class="header-mobile d-lg-none">
  <div class="header-mobile-wrap">
    <div class="header-mobile-left">
      <div class="mobile-open-menu"><i class="bi bi-list"></i></div>
    </div>
    <div class="logo">
      <a href=""><img src="{{ image_origin(admin_brand_logo()) }}" class="img-fluid" alt="LONGOS"></a>
    </div>
    <div class="header-mobile-right">
      <div class="mobile-to-front">
        <a target="_blank" rel="noopener noreferrer" href="{{ shop_route('home.index') }}" class="nav-divnk"><i class="bi bi-send"></i></a>
      </div>
    </div>
  </div>
</div>

<div class="update-pop p-3" style="display: none">
  <div class="mb-4 fs-5 fw-bold text-center">{{ __('admin/common.update_title') }}</div>
  <div class="py-3 px-4 bg-light mx-3 lh-lg mb-4">
    <div>{{ __('admin/common.update_new_version') }}：<span class="new-version fs-5 fw-bold text-success"></span></div>
    <div>{{ __('admin/common.update_old_version') }}：<span class="fs-5">{{ config('beike.version') }}</span></div>
    <div>{{ __('admin/common.update_date') }}：<span class="update-date fs-5"></span></div>
  </div>

  <div class="d-flex justify-content-center mb-3">
    <button class="btn btn-outline-secondary me-3 ">{{ __('common.cancel') }}</button>
    <button type="button" class="btn btn-primary" onclick="layer.closeAll();">{{ __('admin/common.update_btn') }}</button>
  </div>
</div>
