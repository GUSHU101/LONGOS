<div class="category-filter-heading">
  <h2>{{ __('shop/categories.filters') }}</h2>
  @if (request()->hasAny(['attr', 'price']))
    <button type="button" class="category-filter-reset delete-all">{{ __('shop/categories.clear_filters') }}</button>
  @endif
</div>

<section class="module-category-wrap category-filter-section" aria-labelledby="category-filter-categories">
  <h3 id="category-filter-categories"><span>{{ __('product.category') }}</span></h3>
  <ul class="sidebar-widget mb-0" id="category-one">
    @foreach ($all_categories as $key_a => $category_all)
    @if (!$category_all['active']) @continue @endif
    <li class="{{ $category_all['id'] == $category->id ? 'active' : ''}}">
      <a href="{{ $category_all['url'] }}" title="{{ $category_all['name'] }}" class="category-href">{{ $category_all['name'] }}</a>
      @if ($category_all['children'] ?? false)
        <button type="button" class="toggle-icon btn {{ $category_all['id'] == $category->id ? '' : 'collapsed'}}" data-bs-toggle="collapse" data-bs-target="#category-{{ $key_a }}" aria-controls="category-{{ $key_a }}" aria-expanded="{{ $category_all['id'] == $category->id ? 'true' : 'false' }}" aria-label="{{ __('common.view') }} {{ $category_all['name'] }}"><i class="bi bi-chevron-up" aria-hidden="true"></i></button>
        <ul id="category-{{ $key_a }}" class="accordion-collapse collapse {{ $category_all['id'] == $category->id ? 'show' : ''}}" data-bs-parent="#category-one">
          @foreach ($category_all['children'] as $key_b => $child)
          @if (!$child['active']) @continue @endif
          <li class="{{ $child['id'] == $category->id ? 'active' : ''}} child-category">
            <a href="{{ $child['url'] }}" title="{{ $child['name'] }}" class="category-href">{{ $child['name'] }}</a>
            @if ($child['children'] ?? false)
              <button type="button" class="toggle-icon btn {{ $child['id'] == $category->id ? '' : 'collapsed'}}" data-bs-toggle="collapse" data-bs-target="#category-{{ $key_a }}-{{ $key_b }}" aria-controls="category-{{ $key_a }}-{{ $key_b }}" aria-expanded="{{ $child['id'] == $category->id ? 'true' : 'false' }}" aria-label="{{ __('common.view') }} {{ $child['name'] }}"><i class="bi bi-chevron-up" aria-hidden="true"></i></button>
              <ul id="category-{{ $key_a }}-{{ $key_b }}" class="accordion-collapse collapse {{ $child['id'] == $category->id ? 'show' : ''}}" data-bs-parent="#category-{{ $key_a }}">
                @foreach ($child['children'] as $key_c => $sub_child)
                <li class="{{ $sub_child['id'] == $category->id ? 'active' : ''}} child-category">
                  <a href="{{ $sub_child['url'] }}" title="{{ $sub_child['name'] }}" class="category-href">{{ $sub_child['name'] }}</a>
                </li>
                @endforeach
              </ul>
            @endif
          </li>
          @endforeach
        </ul>
      @endif
    </li>
    @endforeach
  </ul>
</section>

<div class="filter-box">
  @if ($filter_data['price']['min'] != $filter_data['price']['max'])
    @hookwrapper('category.filter.sidebar.price')
    @push('header')
      <script src="{{ asset('vendor/jquery/jquery-ui/jquery-ui.min.js') }}"></script>
      <link rel="stylesheet" href="{{ asset('vendor/jquery/jquery-ui/jquery-ui.min.css') }}">
    @endpush

    @if (system_setting('base.multi_filter.price_filter', 1))
      <section class="card category-filter-section" aria-labelledby="category-filter-price">
        <div class="card-header">
          <h3 id="category-filter-price">{{ __('product.price') }}</h3>
        </div>
        <div class="card-body">
          <div id="price-slider" class="mb-2"><div class="slider-bg"></div></div>
          <div class="text-secondary price-range d-flex justify-content-between">
            <div class="d-flex align-items-center wp-100">
              {{ __('common.text_form') }}
              <span class="min ms-1 input-group-sm"><input type="text" inputmode="decimal" aria-label="{{ __('common.text_form') }} {{ __('product.price') }}" value="{{ $filter_data['price']['select_min'] }}" class="form-control price-select-min"></span>
            </div>
            <div class="d-flex align-items-center wp-100">
              {{ __('common.text_to') }}
              <span class="max ms-1 input-group-sm"><input type="text" inputmode="decimal" aria-label="{{ __('common.text_to') }} {{ __('product.price') }}" value="{{ $filter_data['price']['select_max'] }}" class="form-control price-select-max"></span>
            </div>
          </div>
          <input type="hidden" value="{{ $filter_data['price']['min'] }}" class="price-min">
          <input type="hidden" value="{{ $filter_data['price']['max'] }}" class="price-max">
        </div>
      </section>
    @endif
    @endhookwrapper
  @endif

  @hookwrapper('category.filter.sidebar.attr')
  @foreach ($filter_data['attr'] as $index => $attr)
  <section class="card category-filter-section">
    <div class="card-header">
      <h3>{{ $attr['name'] }}</h3>
    </div>
    <ul class="list-group list-group-flush attribute-item" data-attribute-id="{{ $attr['id'] }}">
      @foreach ($attr['values'] as $value_index => $value)
      <li class="list-group-item">
        <label class="form-check-label" for="category-filter-{{ $attr['id'] }}-{{ $value['id'] }}">
          <input id="category-filter-{{ $attr['id'] }}-{{ $value['id'] }}" class="form-check-input attr-value-check" data-attr="{{ $index }}" data-attrval="{{ $value_index }}" {{ $value['selected'] ? 'checked' : '' }} name="filter_attr[]" type="checkbox" value="{{ $value['id'] }}">
          <span>{{ $value['name'] }}</span>
        </label>
      </li>
      @endforeach
    </ul>
  </section>
  @endforeach
  @endhookwrapper
</div>

@push('add-scripts')
<script>
  $(document).ready(function() {
    if (!$('#price-slider').length) {
      return;
    }

    $("#price-slider").slider({
      range: true,
      step: 0.01,
      min: {{ $filter_data['price']['min'] ?? 0 }},
      max: {{ $filter_data['price']['max'] ?? 0 }},
      values: [{{ $filter_data['price']['select_min'] }}, {{ $filter_data['price']['select_max'] }}],
      change: function(event, ui) {
        filterProductData();
      },
      slide: function(event, ui) {
        $('.price-select-min').val((ui.values[0]).toFixed(2));
        $('.price-select-max').val((ui.values[1]).toFixed(2));
      }
    });

    $('.price-select-min, .price-select-max').change(function(event) {
      filterProductData()
    });

    $('.price-select-min, .price-select-max').on('input', function() {
      this.value = this.value.replace(/[^0-9.]/g, '');
    });
  })

  $('.child-category').each(function(index, el) {
    if ($(this).hasClass('active')) {
      $(this).parents('ul').addClass('show').siblings('button').removeClass('collapsed')
      $(this).parents('li').addClass('active')
    }
  });
</script>
@endpush
