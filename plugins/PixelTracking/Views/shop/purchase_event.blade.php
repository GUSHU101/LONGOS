@if ($order)
@php
  $order->loadMissing('orderProducts');
  $trackingContents = $order->orderProducts->map(fn ($product) => [
    'id' => (string) ($product->product_id ?: $product->product_sku),
    'quantity' => (int) $product->quantity,
    'item_price' => (float) currency_format(
      $product->price,
      (string) $order->currency_code,
      (string) $order->currency_value,
      false,
    ),
  ])->values()->all();
@endphp
<script>
  window.bkTrackEvent?.('Purchase', {
    value: Number(@json($order->settlement_total)),
    currency: @json($order->currency_code),
    content_ids: @json(array_column($trackingContents, 'id')),
    contents: @json($trackingContents),
    content_type: 'product',
    order_id: @json($order->number)
  }, @json('purchase_' . $order->id));
</script>
@endif
