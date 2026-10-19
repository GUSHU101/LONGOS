<?php

namespace Beike\Shop\Http\Resources\Account;

use Illuminate\Http\Resources\Json\JsonResource;

class AddressResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param \Illuminate\Http\Request $request
     * @return array|\Illuminate\Contracts\Support\Arrayable|\JsonSerializable
     */
    public function toArray($request)
    {
        $customer = $this->customer;
        // The legacy addresses table contains a `zone` text column and the
        // model also exposes a zone() relationship. Read the relationship
        // explicitly so the text attribute cannot shadow it.
        $zoneModel = $this->resource->relationLoaded('zone')
            ? $this->resource->getRelation('zone')
            : $this->resource->zone()->first();
        $data     = [
            'id'           => $this->id,
            'name'         => $this->name,
            'calling_code' => $this->calling_code ?? '',
            'phone'        => $this->phone,
            'country_id'   => $this->country_id,
            'country'      => $this->country?->name ?? '',
            'zone_id'      => $this->zone_id,
            'zone'         => $zoneModel?->name ?? (string) ($this->resource->getRawOriginal('zone') ?? ''),
            'city'         => $this->city,
            'zipcode'      => $this->zipcode,
            'address_1'    => $this->address_1,
            'address_2'    => $this->address_2,
            'default'      => ($customer?->address_id == $this->id),
        ];

        $rs   = hook_filter('account.address.resource.after', [
            'data'    => $data,
            'address' => $this,
        ]);
        $data = $rs['data'];

        return $data;
    }
}
