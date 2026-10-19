<?php

namespace Beike\Shop\Http\Resources\Checkout;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentMethodItem extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param Request $request
     * @return array
     * @throws \Exception
     */
    public function toArray($request): array
    {
        $plugin = $this->plugin;
        $name   = is_object($plugin) && method_exists($plugin, 'getLocaleName')
            ? $plugin->getLocaleName(locale())
            : '';
        $description = is_object($plugin) && method_exists($plugin, 'getLocaleDescription')
            ? $plugin->getLocaleDescription(locale())
            : '';
        $icon = is_object($plugin)
            ? plugin_resize((string) $this->code, (string) ($plugin->icon ?? ''), 200, 200)
            : '';

        return [
            'type'        => (string) $this->type,
            'code'        => (string) $this->code,
            'name'        => trim($name) ?: (string) $this->code,
            'description' => $description,
            'icon'        => $icon,
        ];
    }
}
