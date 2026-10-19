@extends('layout.master')
@section('body-class', 'page-home')
@section('content')

<div class="modules-box" id="home-modules-box">

  @hook('home.modules.before')

  @foreach($modules as $module)
    {!! $module['html'] ?? '' !!}
  @endforeach

  @if (($design ?? false) && empty($modules))
    <div class="builder-empty-home" data-builder-empty-state>
      <strong>首页还没有模块</strong>
      <span>从左侧组件库添加模块；保存草稿后可在此预览，发布后顾客才会看到。</span>
    </div>
  @endif

  @hook('home.modules.after')

</div>

@endsection

@if ($design ?? false)
@push('add-scripts')
<style>
  .builder-empty-home { align-items: center; background: #f8fafc; border: 2px dashed #b8c7d9; color: #475467; display: flex; flex-direction: column; gap: 8px; justify-content: center; margin: 32px auto; min-height: 260px; max-width: 960px; padding: 32px; text-align: center; }
  .builder-empty-home strong { color: #172b4d; font-size: 22px; }
</style>
<script>
  const hiddenDesignModuleIds = @json(collect($modules)->filter(fn ($module) => ($module['visible'] ?? true) === false)->pluck('module_id')->values());
  document.addEventListener('click', function(event) {
    event.preventDefault();
  });
  document.addEventListener('DOMContentLoaded', function() {
    hiddenDesignModuleIds.forEach(function(id) {
      document.getElementById('module-' + id)?.classList.add('module-item-hidden');
    });
  });
</script>
@endpush
@endif
