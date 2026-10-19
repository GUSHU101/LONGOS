@if ($paginator->hasPages())
  @if(is_mobile())
    <div class="mobile-paginator">
      @if ($paginator->previousPageUrl())
        <a class="btn bg-white" href="{{ $paginator->previousPageUrl() }}" rel="prev"><i class="bi bi-chevron-left" aria-hidden="true"></i> @lang('pagination.previous')</a>
      @else
        <span class="btn bg-white disabled" aria-disabled="true"><i class="bi bi-chevron-left" aria-hidden="true"></i> @lang('pagination.previous')</span>
      @endif
      <div class="input-group">
        <input type="text" inputmode="numeric" pattern="[0-9]*" aria-label="{{ __('common.pagination') }}" class="form-control" id="mb-page-input" onkeyup="this.value=this.value.replace(/\D/g,'')" value="{{ $paginator->currentPage() }}">
        <span class="input-group-text">{{ $paginator->lastPage() }}</span>
      </div>
      @if ($paginator->nextPageUrl())
        <a class="btn bg-white" href="{{ $paginator->nextPageUrl() }}" rel="next">@lang('pagination.next') <i class="bi bi-chevron-right" aria-hidden="true"></i></a>
      @else
        <span class="btn bg-white disabled" aria-disabled="true">@lang('pagination.next') <i class="bi bi-chevron-right" aria-hidden="true"></i></span>
      @endif
    </div>

    <script>
      $('#mb-page-input').on('change', function() {
        let page = $(this).val();
        let lastPage = @json($paginator->lastPage());
        let url = @json($paginator->url(0));
        url = url.replace(/&amp;/g, '&');
        if (page > lastPage) {
          $(this).val(lastPage);
          page = lastPage;
        }

        if (page) {
          url = bk.updateQueryStringParameter(url, 'page', page)
          window.location.href = url;
        }
      })
    </script>
  @else
    <nav class="d-flex justify-content-center">
      <ul class="pagination">
        {{-- Previous Page Link --}}
        @if ($paginator->onFirstPage())
        <li class="page-item disabled" aria-disabled="true" aria-label="@lang('pagination.previous')">
          <span class="page-link" aria-hidden="true"><i class="bi bi-chevron-left"></i></span>
        </li>
        @else
        <li class="page-item">
          <a class="page-link" href="{{ $paginator->previousPageUrl() }}" rel="prev" aria-label="@lang('pagination.previous')"><i class="bi bi-chevron-left"></i></a>
        </li>
        @endif
        {{-- Pagination Elements --}}
        @foreach ($elements as $element)
        {{-- "Three Dots" Separator --}}
        @if (is_string($element))
        <li class="page-item disabled" aria-disabled="true"><span class="page-link">{{ $element }}</span></li>
        @endif
        {{-- Array Of Links --}}
        @if (is_array($element))
        @foreach ($element as $page => $url)
        @if ($page == $paginator->currentPage())
        <li class="page-item active" aria-current="page"><span class="page-link">{{ $page }}</span></li>
        @else
        <li class="page-item"><a class="page-link" href="{{ $url }}">{{ $page }}</a></li>
        @endif
        @endforeach
        @endif
        @endforeach
        {{-- Next Page Link --}}
        @if ($paginator->hasMorePages())
        <li class="page-item">
          <a class="page-link" href="{{ $paginator->nextPageUrl() }}" rel="next" aria-label="@lang('pagination.next')"><i class="bi bi-chevron-right"></i></a>
        </li>
        @else
        <li class="page-item disabled" aria-disabled="true" aria-label="@lang('pagination.next')">
          <span class="page-link" aria-hidden="true"><i class="bi bi-chevron-right"></i></span>
        </li>
        @endif
      </ul>
    </nav>
  @endif
@endif
