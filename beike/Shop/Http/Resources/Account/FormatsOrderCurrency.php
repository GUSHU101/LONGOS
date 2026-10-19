<?php

namespace Beike\Shop\Http\Resources\Account;

trait FormatsOrderCurrency
{
    private function formatOrderAmount($amount): string
    {
        return currency_format($amount, (string) $this->currency_code, (string) $this->currency_value);
    }

    private function formattedOrderProducts(): array
    {
        $products = OrderProductSimple::collection($this->orderProducts)->jsonSerialize();

        foreach ($products as $index => $product) {
            $price                            = (float) ($product['total'] ?? 0) / max(1, (int) ($product['quantity'] ?? 0));
            $products[$index]['price']        = $this->formatOrderAmount($price);
            $products[$index]['total_format'] = $this->formatOrderAmount($product['total'] ?? 0);
        }

        return $products;
    }

    private function formattedOrderTotals(): array
    {
        return $this->orderTotals->map(function ($total): array {
            $data                 = $total->toArray();
            $data['value_format'] = $this->formatOrderAmount($total->value);

            return $data;
        })->all();
    }
}
