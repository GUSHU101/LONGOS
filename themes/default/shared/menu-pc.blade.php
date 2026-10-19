<ul class="navbar-nav mx-auto">
  @hook('header.menu.before')
  @foreach ($menu_content as $menu)
    @if ($menu['name'])
      @php
        $hasChildren = isset($menu['children_group']) && $menu['children_group'];
        $menuDropdownId = 'desktop-menu-' . $loop->index;
        $menuColumnCount = $hasChildren ? min(4, max(1, count($menu['children_group']))) : 1;
        $menuWidth = 2.5 + ($menuColumnCount * 13.5);
        $menuCompactWidth = 2 + ($menuColumnCount * 11.5);
      @endphp
      <li
        class="nav-item {{ $hasChildren ? 'dropdown' : '' }} {{ isset($menu['isFull']) && $menu['isFull'] ? 'position-static' : '' }}">
        <a class="nav-link fw-bold {{ $hasChildren ? 'dropdown-toggle' : '' }}"
          target="{{ isset($menu['new_window']) && $menu['new_window'] ? '_blank' : '_self' }}"
          @if (isset($menu['new_window']) && $menu['new_window']) rel="noopener noreferrer" @endif
          href="{{ $menu['link'] ?: '#' }}"
          @if ($hasChildren) aria-haspopup="true" aria-expanded="false" aria-controls="{{ $menuDropdownId }}" @endif>
          {{ $menu['name'] }}
          @if (isset($menu['badge']) && $menu['badge']['name'])
            <span class="badge"
              style="background-color: {{ $menu['badge']['bg_color'] }}; color: {{ $menu['badge']['text_color'] }}; border-color: {{ $menu['badge']['bg_color'] }}">
              {{ $menu['badge']['name'] }}
            </span>
          @endif
          @if ($hasChildren)
            <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" xmlns="http://www.w3.org/2000/svg"><path d="m6 9 6 6 6-6"/></svg>
          @endif
        </a>
        @if ($hasChildren)
          <div id="{{ $menuDropdownId }}" class="dropdown-menu {{ !empty($menu['isFull']) ? 'w-100' : '' }}"
            role="group" aria-label="{{ $menu['name'] }}"
            style="--longos-menu-columns: {{ $menuColumnCount }}; --longos-menu-width: {{ $menuWidth }}rem; --longos-menu-compact-width: {{ $menuCompactWidth }}rem;">
            <div class="card card-lg">
              <div class="card-body">
                <div class="container desktop-menu__container">
                  <div class="desktop-menu__grid">
                    @forelse ($menu['children_group'] as $group)
                      <div class="desktop-menu__group">
                        @if ($group['name'])
                          <div class="mb-3 fw-bold group-name">{{ $group['name'] }}</div>
                        @endif
                        @if ($group['type'] == 'image')
                          <a
                          target="{{ isset($group['image']['link']['new_window']) && $group['image']['link']['new_window'] ? '_blank' : '_self' }}"
                          @if (isset($group['image']['link']['new_window']) && $group['image']['link']['new_window']) rel="noopener noreferrer" @endif
                          href="{{ $group['image']['link'] ?: '#' }}"><img src="{{ $group['image']['image'] }}" alt="{{ $group['name'] }}"
                              class="img-fluid"></a>
                        @else
                          <ul class="nav flex-column ul-children">
                            @foreach ($group['children'] as $children)
                              @if (!is_array($children['link']['text']) && $children['link']['text'])
                                <li class="nav-item">
                                  <a
                                  target="{{ isset($children['link']['new_window']) && $children['link']['new_window'] ? '_blank' : '_self' }}"
                                  @if (isset($children['link']['new_window']) && $children['link']['new_window']) rel="noopener noreferrer" @endif
                                  class="nav-link px-0"
                                    href="{{ $children['link']['link'] ?: '#' }}">{{ $children['link']['text'] }}</a>
                                </li>
                              @endif
                            @endforeach
                          </ul>
                        @endif
                      </div>
                    @endforeach
                  </div>
                </div>
              </div>
            </div>
          </div>
        @endif
      </li>
    @endif
  @endforeach
  <li class="nav-item header-tracking-nav">
    <a class="nav-link fw-bold header-tracking-link" href="{{ shop_route('tracking.index') }}"
       @if (equal_route('shop.tracking.index')) aria-current="page" @endif>
      <i class="bi bi-truck" aria-hidden="true"></i>
      <span>{{ __('shop/tracking.title') }}</span>
    </a>
  </li>
  @hook('header.menu.after')
</ul>
