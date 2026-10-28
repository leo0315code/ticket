<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'default_agent_id' => $this->default_agent_id,
            'default_agent' => $this->whenLoaded('defaultAgent', fn () => $this->defaultAgent?->only(['id', 'name'])),
        ];
    }
}
