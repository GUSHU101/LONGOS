<nav class="mobile-nav" aria-labelledby="mobile-navigation-label">
  <div class="accordion accordion-flush mobile-nav__list" id="menu-accordion">
    @hook('header.menu.mobile.before')
    @foreach ($menu_content as $key => $menu)
      @if (!empty($menu['name']))
        @php
          $hasChildren = !empty($menu['children_group']);
          $hasLink = !empty($menu['link']);
          $menuPanelId = 'flush-menu-' . $key;
        @endphp
        <div class="accordion-item mobile-nav__item">
          <div class="mobile-nav__row">
            @if ($hasLink)
              <a
                class="mobile-nav__link"
                href="{{ $menu['link'] }}"
                target="{{ !empty($menu['new_window']) ? '_blank' : '_self' }}"
                @if (!empty($menu['new_window'])) rel="noopener noreferrer" @endif>
                <span class="mobile-nav__label">{{ $menu['name'] }}</span>
                @if (!empty($menu['badge']['name']))
                  <span class="mobile-nav__badge" style="--badge-bg: {{ $menu['badge']['bg_color'] }}; --badge-color: {{ $menu['badge']['text_color'] }};">
                    {{ $menu['badge']['name'] }}
                  </span>
                @endif
              </a>
            @elseif ($hasChildren)
              <button
                type="button"
                class="mobile-nav__link mobile-nav__link--disclosure collapsed"
                data-bs-toggle="collapse"
                data-bs-target="#{{ $menuPanelId }}"
                aria-controls="{{ $menuPanelId }}"
                aria-expanded="false">
                <span class="mobile-nav__label">{{ $menu['name'] }}</span>
                @if (!empty($menu['badge']['name']))
                  <span class="mobile-nav__badge" style="--badge-bg: {{ $menu['badge']['bg_color'] }}; --badge-color: {{ $menu['badge']['text_color'] }};">
                    {{ $menu['badge']['name'] }}
                  </span>
                @endif
                <i class="bi bi-chevron-down mobile-nav__disclosure-icon" aria-hidden="true"></i>
              </button>
            @else
              <span class="mobile-nav__link mobile-nav__link--static">
                <span class="mobile-nav__label">{{ $menu['name'] }}</span>
              </span>
            @endif

            @if ($hasChildren && $hasLink)
              <button
                type="button"
                class="mobile-nav__toggle collapsed"
                data-bs-toggle="collapse"
                data-bs-target="#{{ $menuPanelId }}"
                aria-controls="{{ $menuPanelId }}"
                aria-expanded="false"
                aria-label="{{ __('shop/header.open_submenu', ['name' => $menu['name']]) }}">
                <i class="bi bi-chevron-down" aria-hidden="true"></i>
              </button>
            @endif
          </div>

          @if ($hasChildren)
            <div class="accordion-collapse collapse mobile-nav__subpanel" id="{{ $menuPanelId }}" data-bs-parent="#menu-accordion">
              @foreach ($menu['children_group'] as $group)
                @php
                  $groupType = $group['type'] ?? 'link';
                @endphp
                <section class="mobile-nav__group">
                  @if (!empty($group['name']))
                    <h3 class="mobile-nav__group-title">{{ $group['name'] }}</h3>
                  @endif

                  @if ($groupType === 'image')
                    <a
                      class="mobile-nav__image-link"
                      href="{{ $group['image']['link'] ?: '#' }}"
                      target="{{ isset($group['image']['link']['new_window']) && $group['image']['link']['new_window'] ? '_blank' : '_self' }}"
                      @if (isset($group['image']['link']['new_window']) && $group['image']['link']['new_window']) rel="noopener noreferrer" @endif>
                      <img src="{{ $group['image']['image'] }}" alt="{{ $group['name'] }}" class="img-fluid">
                    </a>
                  @else
                    <ul class="mobile-nav__children">
                      @foreach (($group['children'] ?? []) as $children)
                        @if (!is_array($children['link']['text'] ?? null) && !empty($children['link']['text']))
                          <li>
                            <a
                              class="mobile-nav__child-link"
                              href="{{ $children['link']['link'] ?: '#' }}"
                              target="{{ !empty($children['link']['new_window']) ? '_blank' : '_self' }}"
                              @if (!empty($children['link']['new_window'])) rel="noopener noreferrer" @endif>
                              <span>{{ $children['link']['text'] }}</span>
                              <i class="bi bi-chevron-right" aria-hidden="true"></i>
                            </a>
                          </li>
                        @endif
                      @endforeach
                    </ul>
                  @endif
                </section>
              @endforeach
            </div>
          @endif
        </div>
      @endif
    @endforeach

    <div class="accordion-item mobile-nav__item mobile-nav__item--tracking header-tracking-nav">
      <div class="mobile-nav__row">
        <a
          class="mobile-nav__link header-tracking-link"
          href="{{ shop_route('tracking.index') }}"
          @if (equal_route('shop.tracking.index')) aria-current="page" @endif>
          <i class="bi bi-truck mobile-nav__utility-icon" aria-hidden="true"></i>
          <span class="mobile-nav__label">{{ __('shop/tracking.title') }}</span>
          <i class="bi bi-chevron-right mobile-nav__link-arrow" aria-hidden="true"></i>
        </a>
      </div>
    </div>
    @hook('header.menu.mobile.after')
  </div>
</nav>
