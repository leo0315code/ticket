<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TicketResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'no' => $this->no,
            'title' => $this->title,
            'content' => $this->content,
            'status' => $this->status,
            'priority' => $this->priority,
            'user' => new UserResource($this->whenLoaded('user')),
            'agent' => new UserResource($this->whenLoaded('agent')),
            'category' => new CategoryResource($this->whenLoaded('category')),
            'replies_count' => $this->whenCounted('replies'),
            'replies' => TicketReplyResource::collection($this->whenLoaded('replies')),
            'logs' => TicketLogResource::collection($this->whenLoaded('logs')),
            'attachments' => AttachmentResource::collection($this->whenLoaded('attachments')),
            'rating' => $this->rating,
            'rating_comment' => $this->rating_comment,
            'resolved_at' => $this->resolved_at?->format('Y-m-d H:i'),
            'closed_at' => $this->closed_at?->format('Y-m-d H:i'),
            'sla_due_at' => $this->sla_due_at?->format('Y-m-d H:i'),
            'first_response_at' => $this->first_response_at?->format('Y-m-d H:i'),
            'closed_reason' => $this->closed_reason,
            'reopen_count' => (int) ($this->reopen_count ?? 0),
            'last_activity_at' => $this->last_activity_at?->format('Y-m-d H:i'),
            'overdue' => $this->isOverdue(),
            'created_at' => $this->created_at->format('Y-m-d H:i'),
            'updated_at' => $this->updated_at->format('Y-m-d H:i'),
        ];
    }
}
