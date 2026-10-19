<?php

namespace Beike\Admin\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class CategoryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param \Illuminate\Http\Request $request
     * @return array|\Illuminate\Contracts\Support\Arrayable|\JsonSerializable
     */
    public function toArray($request)
    {
        $data = [
            'id'                    => $this->id,
            'name'                  => $this->description->name ?? '',
            'parent_id'             => $this->parent_id,
            'position'              => $this->position,
            'active'                => $this->active,
            'auto_assign_enabled'   => (bool) $this->auto_assign_enabled,
            'auto_assign_keywords'  => (string) $this->auto_assign_keywords,
            'auto_assign_brand_ids' => array_values(array_map('intval', (array) $this->auto_assign_brand_ids)),
            'url_edit'              => admin_route('categories.edit', $this),
            'children'              => self::collection($this->children),
        ];

        return $data;
    }
}
