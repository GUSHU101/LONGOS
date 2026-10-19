@php
  $brandStyle = site_brand_style();
@endphp

<section id="brand-assets" class="border-top pt-4 mt-4" aria-labelledby="brand-assets-title">
  <div class="mb-4">
    <h2 class="h5 mb-1" id="brand-assets-title">品牌与图片</h2>
    <p class="text-muted mb-0">统一设置店铺标志、网站图标、占位图和全局品牌视觉。后台产品标志固定使用 LONGOS。</p>
  </div>

  @hook('admin.setting.picture.content.before')
  @hook('admin.setting.image.before')

  <x-admin::form.row title="{{ __('admin/setting.shop_logo') }}">
    <button type="button" class="open-brand-crop bg-light wh-80 border d-flex justify-content-center align-items-center me-2 mb-2 position-relative" data-ratio="380/100" data-width="760" data-height="200" aria-label="上传前台 Logo">
      <img src="{{ image_resize(old('logo', site_brand_asset('shop_logo'))) }}" class="img-fluid" alt="{{ site_brand_name() }}">
    </button>
    <input type="hidden" value="{{ old('logo', site_brand_asset('shop_logo')) }}" name="logo">
    <div class="help-text font-size-12 lh-base">{{ __('common.recommend_size') }} 380*100</div>
  </x-admin::form.row>

  <x-admin::form.row title="{{ __('admin/setting.admin_logo') }}">
    <div class="bg-light wh-80 border d-flex justify-content-center align-items-center me-2 mb-2 position-relative">
      <img src="{{ image_origin(admin_brand_logo()) }}" class="img-fluid" alt="LONGOS">
    </div>
    <div class="help-text font-size-12 lh-base">后台标志属于 LONGOS 平台身份，不随租户店铺品牌替换。</div>
  </x-admin::form.row>

  <x-admin::form.row title="Favicon">
    <button type="button" class="open-brand-crop bg-light wh-80 border d-flex justify-content-center align-items-center me-2 mb-2 position-relative" data-ratio="1/1" data-width="128" data-height="128" aria-label="上传网站图标">
      <img src="{{ image_resize(old('favicon', site_brand_asset('favicon'))) }}" class="img-fluid" alt="">
    </button>
    <input type="hidden" value="{{ old('favicon', site_brand_asset('favicon')) }}" name="favicon">
    <div class="help-text font-size-12 lh-base">{{ __('admin/setting.favicon_info') }}</div>
  </x-admin::form.row>

  <x-admin::form.row :title="__('admin/setting.placeholder_image')">
    <button type="button" class="open-brand-crop bg-light wh-80 border d-flex justify-content-center align-items-center me-2 mb-2 position-relative" data-ratio="1/1" data-width="1000" data-height="1000" aria-label="上传商品占位图">
      <img src="{{ image_resize(old('placeholder', system_setting('base.placeholder', ''))) }}" class="img-fluid" alt="">
    </button>
    <input type="hidden" value="{{ old('placeholder', system_setting('base.placeholder', '')) }}" name="placeholder">
    <div class="help-text font-size-12 lh-base">{{ __('admin/setting.placeholder_image_info') }}</div>
  </x-admin::form.row>

  <x-admin-form-select title="前台视觉预设" name="storefront_preset" :value="old('storefront_preset', $brandStyle['preset'])" :options="[['value' => 'minimal', 'label' => '极简陈列'], ['value' => 'soft', 'label' => '柔和生活方式'], ['value' => 'editorial', 'label' => '编辑感品牌']]" key="value" label="label" />
  <x-admin-form-input name="brand_primary_color" type="color" title="品牌主色" value="{{ old('brand_primary_color', $brandStyle['primary_color']) }}">
    <div class="help-text font-size-12 lh-base">用于后台主要操作和前台品牌强调。</div>
  </x-admin-form-input>
  <x-admin-form-input name="brand_accent_color" type="color" title="品牌强调色" value="{{ old('brand_accent_color', $brandStyle['accent_color']) }}" />
  <x-admin-form-input name="brand_border_radius" type="number" min="0" max="32" step="1" title="全局圆角" value="{{ old('brand_border_radius', $brandStyle['border_radius']) }}" group-right="px" />

  @hook('admin.setting.image.after')
  @hook('admin.setting.picture.content.after')
</section>

<div class="modal fade" id="brand-image-crop-modal" tabindex="-1" data-bs-backdrop="static" aria-labelledby="brand-image-crop-title" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <div><h5 class="modal-title" id="brand-image-crop-title">{{ __('shop/account/edit.crop') }}</h5><small class="text-muted">裁剪尺寸：<span data-crop-size></span></small></div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body"><div class="img-container"><img id="brand-crop-image" src="{{ image_resize('/') }}" class="img-fluid" alt=""></div></div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">{{ __('shop/common.cancel') }}</button>
        <button type="button" class="btn btn-primary" data-brand-crop-confirm>{{ __('shop/common.confirm') }}</button>
      </div>
    </div>
  </div>
</div>

@push('header')
  <script src="{{ asset('vendor/cropper/cropper.min.js') }}"></script>
  <link rel="stylesheet" href="{{ asset('vendor/cropper/cropper.min.css') }}">
@endpush

@push('footer')
<script>
  $(function () {
    var cropper = null;
    var target = null;
    var outputWidth = 800;
    var outputHeight = 800;
    var modal = document.getElementById('brand-image-crop-modal');
    var image = document.getElementById('brand-crop-image');

    $('.open-brand-crop').on('click', function () {
      target = $(this);
      outputWidth = Number(target.data('width')) || 800;
      outputHeight = Number(target.data('height')) || 800;
      var input = $('<input type="file" accept="image/jpeg,image/png,image/webp" class="d-none">');
      input.on('change', function () {
        var file = this.files && this.files[0];
        if (!file) return;
        if (file.size > 10 * 1024 * 1024) {
          layer.msg('图片不能超过 10MB');
          return;
        }
        var reader = new FileReader();
        reader.onload = function (event) {
          image.src = event.target.result;
          bootstrap.Modal.getOrCreateInstance(modal).show();
        };
        reader.readAsDataURL(file);
      });
      input.trigger('click');
    });

    modal.addEventListener('shown.bs.modal', function () {
      var parts = String(target && target.data('ratio') || '1/1').split('/');
      cropper = new Cropper(image, {
        aspectRatio: Number(parts[0]) / Number(parts[1]),
        autoCropArea: 1,
        viewMode: 1,
        crop: function (event) {
          $('[data-crop-size]').text(Math.round(event.detail.width) + ' × ' + Math.round(event.detail.height));
        }
      });
    });

    modal.addEventListener('hidden.bs.modal', function () {
      if (cropper) cropper.destroy();
      cropper = null;
      image.removeAttribute('src');
    });

    $('[data-brand-crop-confirm]').on('click', function () {
      if (!cropper || !target) return;
      var button = $(this).prop('disabled', true);
      cropper.getCroppedCanvas({ width: outputWidth, height: outputHeight, imageSmoothingQuality: 'high' }).toBlob(function (blob) {
        if (!blob) {
          button.prop('disabled', false);
          layer.msg('图片裁剪失败，请换一张图片重试');
          return;
        }
        var formData = new FormData();
        formData.append('file', blob, 'brand-image.webp');
        formData.append('type', 'image');
        $http.post('{{ admin_route('file.store') }}', formData).then(function (res) {
          target.find('img').attr('src', res.data.url);
          target.next('input[type="hidden"]').val(res.data.value);
          bootstrap.Modal.getOrCreateInstance(modal).hide();
        }).catch(function () {
          layer.msg('图片上传失败，请稍后重试');
        }).finally(function () {
          button.prop('disabled', false);
        });
      }, 'image/webp', .9);
    });
  });
</script>
@endpush
