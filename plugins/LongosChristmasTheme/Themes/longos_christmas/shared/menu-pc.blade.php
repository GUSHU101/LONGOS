<ul class="christmas-menu">
  @hook('header.menu.before')
  @foreach ($menu_content as $menu)
    @if (!empty($menu['name']))
      @php
        $hasChildren = !empty($menu['children_group']);
        $menuDropdownId = 'christmas-menu-' . $loop->index;
      @endphp
      <li class="christmas-menu__item {{ $hasChildren ? 'has-children' : '' }} {{ !empty($menu['isFull']) ? 'is-wide' : '' }}">
        <a class="christmas-menu__link" href="{{ $menu['link'] ?: '#' }}" target="{{ !empty($menu['new_window']) ? '_blank' : '_self' }}" @if(!empty($menu['new_window'])) rel="noopener noreferrer" @endif @if($hasChildren) aria-haspopup="true" aria-expanded="false" aria-controls="{{ $menuDropdownId }}" @endif>
          <span>{{ $menu['name'] }}</span>
          @if (!empty($menu['badge']['name']))<small style="--badge-bg: {{ $menu['badge']['bg_color'] }}; --badge-color: {{ $menu['badge']['text_color'] }}">{{ $menu['badge']['name'] }}</small>@endif
          @if ($hasChildren)<i class="bi bi-chevron-down" aria-hidden="true"></i>@endif
        </a>
        @if ($hasChildren)
          <div id="{{ $menuDropdownId }}" class="christmas-mega-menu" aria-label="{{ $menu['name'] }}">
            <div class="christmas-mega-menu__inner">
              @foreach ($menu['children_group'] as $group)
                <section class="christmas-mega-menu__group">
                  @if (!empty($group['name']))<h3>{{ $group['name'] }}</h3>@endif
                  @if (($group['type'] ?? 'link') === 'image')
                    <a class="christmas-mega-menu__image" href="{{ $group['image']['link'] ?: '#' }}" target="{{ !empty($group['image']['link']['new_window']) ? '_blank' : '_self' }}" @if(!empty($group['image']['link']['new_window'])) rel="noopener noreferrer" @endif><img src="{{ $group['image']['image'] }}" alt="{{ $group['name'] }}"></a>
                  @else
                    <ul>
                      @foreach (($group['children'] ?? []) as $child)
                        @if (!is_array($child['link']['text'] ?? null) && !empty($child['link']['text']))
                          <li><a href="{{ $child['link']['link'] ?: '#' }}" target="{{ !empty($child['link']['new_window']) ? '_blank' : '_self' }}" @if(!empty($child['link']['new_window'])) rel="noopener noreferrer" @endif>{{ $child['link']['text'] }}</a></li>
                        @endif
                      @endforeach
                    </ul>
                  @endif
                </section>
              @endforeach
            </div>
          </div>
        @endif
      </li>
    @endif
  @endforeach
  <li class="christmas-menu__item christmas-menu__tracking"><a class="christmas-menu__link" href="{{ shop_route('tracking.index') }}"><i class="bi bi-truck" aria-hidden="true"></i><span>{{ __('shop/tracking.title') }}</span></a></li>
  @hook('header.menu.after')
</ul>
