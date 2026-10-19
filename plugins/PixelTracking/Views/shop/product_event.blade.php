@if (!empty($product))
@php
  $trackingSku = collect($product['skus'] ?? [])->firstWhere('is_default', true)
    ?? collect($product['skus'] ?? [])->firstWhere('active', true)
    ?? collect($product['skus'] ?? [])->first();
  $trackingContentId = (string) ($product['id'] ?? '');
  $trackingPrice = (float) ($trackingSku['price_converted'] ?? 0);
  $trackingCurrency = (string) ($trackingSku['currency_code'] ?? current_currency_code());
@endphp
<script>
  window.bkTrackEvent?.('ViewContent', {
    content_ids: [@json($trackingContentId)],
    contents: [{id: @json($trackingContentId), quantity: 1, item_price: @json($trackingPrice)}],
    content_type: 'product',
    value: Number(@json($trackingPrice)),
    currency: @json(strtoupper($trackingCurrency))
  });
</script>
@endif
