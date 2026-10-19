<nav class="christmas-mobile-menu" aria-labelledby="mobile-navigation-label">
  <div class="accordion accordion-flush" id="christmas-menu-accordion">
    @hook('header.menu.mobile.before')
    @foreach ($menu_content as $key => $menu)
      @if (!empty($menu['name']))
        @php
          $hasChildren = !empty($menu['children_group']);
          $hasLink = !empty($menu['link']);
          $panelId = 'christmas-mobile-panel-' . $key;
        @endphp
        <div class="christmas-mobile-menu__item">
          <div class="christmas-mobile-menu__row">
            @if ($hasLink)
              <a href="{{ $menu['link'] }}" target="{{ !empty($menu['new_window']) ? '_blank' : '_self' }}" @if(!empty($menu['new_window'])) rel="noopener noreferrer" @endif>
                <span>{{ $menu['name'] }}</span>
                @if (!empty($menu['badge']['name']))<small style="--badge-bg: {{ $menu['badge']['bg_color'] }}; --badge-color: {{ $menu['badge']['text_color'] }}">{{ $menu['badge']['name'] }}</small>@endif
              </a>
            @else
              <span>{{ $menu['name'] }}</span>
            @endif
            @if ($hasChildren)
              <button type="button" class="collapsed" data-bs-toggle="collapse" data-bs-target="#{{ $panelId }}" aria-controls="{{ $panelId }}" aria-expanded="false" aria-label="{{ __('shop/header.open_submenu', ['name' => $menu['name']]) }}"><i class="bi bi-plus-lg" aria-hidden="true"></i></button>
            @endif
          </div>
          @if ($hasChildren)
            <div id="{{ $panelId }}" class="collapse christmas-mobile-menu__panel" data-bs-parent="#christmas-menu-accordion">
              @foreach ($menu['children_group'] as $group)
                <section>
                  @if (!empty($group['name']))<h3>{{ $group['name'] }}</h3>@endif
                  @if (($group['type'] ?? 'link') === 'image')
                    <a class="christmas-mobile-menu__image" href="{{ $group['image']['link'] ?: '#' }}"><img src="{{ $group['image']['image'] }}" alt="{{ $group['name'] }}"></a>
                  @else
                    @foreach (($group['children'] ?? []) as $child)
                      @if (!is_array($child['link']['text'] ?? null) && !empty($child['link']['text']))<a class="christmas-mobile-menu__child" href="{{ $child['link']['link'] ?: '#' }}">{{ $child['link']['text'] }}<i class="bi bi-arrow-right" aria-hidden="true"></i></a>@endif
                    @endforeach
                  @endif
                </section>
              @endforeach
            </div>
          @endif
        </div>
      @endif
    @endforeach
    @hook('header.menu.mobile.after')
  </div>
</nav>
