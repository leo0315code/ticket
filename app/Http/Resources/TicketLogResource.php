<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TicketLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action,
            'detail' => $this->detail,
            'user' => new UserResource($this->whenLoaded('user')),
            'ticket' => $this->whenLoaded('ticket', fn () => [
                'id' => $this->ticket->id,
                'no' => $this->ticket->no,
                'title' => $this->ticket->title,
            ]),
            'created_at' => $this->created_at->format('Y-m-d H:i'),
        ];
    }
}
