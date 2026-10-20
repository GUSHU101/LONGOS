@php
  // A stale/partial plugin route cache must never turn the whole product page
  // into a 500. The deployment upgrade rebuilds the shared route cache; until
  // it has done so, hide only these optional actions and keep products usable.
  try {
      $shopifyManageUrl = admin_route('shopify_product_transfer.index');
      $shopifyExportUrl = admin_route('shopify_product_transfer.export');
      $shopifySelectedExportUrl = admin_route('shopify_product_transfer.export_selected');
  } catch (\Symfony\Component\Routing\Exception\RouteNotFoundException) {
      $shopifyManageUrl = $shopifyExportUrl = $shopifySelectedExportUrl = null;
  }
@endphp
@if ($shopifyManageUrl && $shopifyExportUrl && $shopifySelectedExportUrl)
<div class="d-flex flex-wrap align-items-center gap-2 mt-3 mb-2">
  <a class="btn btn-outline-primary btn-sm" href="{{ $shopifyManageUrl }}">
    <i class="bi bi-shop"></i> {{ trans('ShopifyProductTransfer::transfer.manage') }}
  </a>
  <a class="btn btn-outline-secondary btn-sm" href="{{ $shopifyExportUrl }}">
    <i class="bi bi-download"></i> {{ trans('ShopifyProductTransfer::transfer.export_all') }}
  </a>
  <button class="btn btn-outline-secondary btn-sm" type="button" data-shopify-export-selected data-url="{{ $shopifySelectedExportUrl }}">
    <i class="bi bi-download"></i> {{ trans('ShopifyProductTransfer::transfer.export_selected') }}
  </button>
</div>
<script>
document.addEventListener('click', function (event) {
  var button = event.target.closest('[data-shopify-export-selected]');
  if (!button) return;
  var ids = typeof app !== 'undefined' && Array.isArray(app.selectedIds)
    ? app.selectedIds
    : Array.from(document.querySelectorAll('#product-app input[type="checkbox"][v-model="selectedIds"]:checked'))
        .map(function (input) { return input.value; });
  ids = ids.map(String).filter(function (id) { return /^\d+$/.test(id); });
  if (!ids.length) {
    alert(@json(trans('ShopifyProductTransfer::transfer.select_products')));
    return;
  }
  var form = document.createElement('form');
  form.method = 'post';
  form.action = button.dataset.url;
  form.hidden = true;
  var token = document.createElement('input');
  token.type = 'hidden';
  token.name = '_token';
  token.value = @json(csrf_token());
  var selection = document.createElement('input');
  selection.type = 'hidden';
  selection.name = 'ids';
  selection.value = ids.slice(0, 2000).join(',');
  form.append(token, selection);
  document.body.appendChild(form);
  form.submit();
});
</script>
@endif
