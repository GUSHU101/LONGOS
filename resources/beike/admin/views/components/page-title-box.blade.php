@hasSection('title')
<div class="page-title-box d-flex align-items-center justify-content-between @hasSection('head-form-btns') head-edit-tool @endif">
  <div class="d-flex align-items-center page-title-left">
    @if(trim($__env->yieldContent('head-form-btns')) || trim($__env->yieldContent('page-title-back')))
      @if($backUrl && $backUrl !== '1')
        <a href="{{ $backUrl }}" class="btn btn-default btn-sm me-2" aria-label="{{ __('common.return') }}"><i class="bi bi-chevron-left" aria-hidden="true"></i></a>
      @else
        <button type="button" class="btn btn-default btn-sm me-2" onclick="history.back()" aria-label="{{ __('common.return') }}"><i class="bi bi-chevron-left" aria-hidden="true"></i></button>
      @endif
    @else
    <span class="btn btn-sm me-2 cursor-auto" aria-hidden="true"><i class="bi bi-list"></i></span>
    @endif
    <h5 class="page-title d-flex align-items-center">
      @yield('title')
    </h5>
    <div class="ms-4">@yield('page-title-after')</div>
  </div>

  @hasSection('head-form-btns')
    <div class="page-title-btn-wrap">
      @if (trim($__env->yieldContent('head-exit-hide')) !== '1')
      <button type="button" class="btn btn-default exit-edit">{{ __('admin/common.text_exit_edit') }}</button>
      @endif
      <button type="button" class="btn btn-primary submit-form" form="@yield('submit-form', 'form-app')">{{ __('common.save') }}</button>
    </div>
  @endif

  <div class="text-nowrap page-title-right">
    @yield('page-title-right')

    @hasSection('head-form-btns')
    <button type="button" class="close-form-btns ms-2 border-0 bg-transparent p-0" aria-label="{{ __('admin/common.text_exit_edit') }}"><i class="bi bi-x-circle" aria-hidden="true"></i></button>
    @endif
  </div>
</div>
@endif
