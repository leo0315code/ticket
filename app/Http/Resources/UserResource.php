<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'username' => $this->whenNotNull($this->username),
            'phone' => $this->whenNotNull($this->phone),
            'email' => $this->whenNotNull($this->email),
            'role' => $this->whenNotNull($this->role),
            'tickets_count' => $this->whenCounted('tickets'),
            'assigned_tickets_count' => $this->whenCounted('assignedTickets'),
        ];
    }
}
