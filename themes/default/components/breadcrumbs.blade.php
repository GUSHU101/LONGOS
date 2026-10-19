@hook('components.breadcrumbs.before')
@unless ($breadcrumbs->isEmpty())
<div class="breadcrumb-wrap" data-breadcrumb-count="{{ $breadcrumbs->count() }}">
  <div class="container{{ $isFull ?? false ? '-fluid' : '' }}">
    <nav class="breadcrumb-nav" aria-label="breadcrumb">
      <ol class="breadcrumb breadcrumb-list">
        @foreach ($breadcrumbs as $breadcrumb)
          @if (isset($breadcrumb['url']) && $breadcrumb['url'] && ! $loop->last)
          <li class="breadcrumb-item"><a href="{{ $breadcrumb['url'] }}">{{ $breadcrumb['title'] }}</a></li>
          @else
          <li class="breadcrumb-item breadcrumb-item--current active" aria-current="page">
            <span class="breadcrumb-current" title="{{ $breadcrumb['title'] }}">{{ $breadcrumb['title'] }}</span>
          </li>
          @endif
        @endforeach
      </ol>
    </nav>
  </div>
</div>
@endunless
@hook('components.breadcrumbs.after')
