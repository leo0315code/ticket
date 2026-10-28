<?php

namespace App\Http\Resources;

use App\Models\Role;
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
            'has_phone' => filled($this->phone),
            'has_wechat' => filled($this->wechat_openid),
            'wechat_nickname' => $this->whenNotNull($this->wechat_nickname),
            'email' => $this->whenNotNull($this->email),
            'role' => $this->whenNotNull($this->role),
            'role_label' => Role::allCached()[$this->role]->label ?? $this->role,
            'permissions' => $this->permissions(),
            'ticket_scope' => $this->isStaff() ? $this->ticketScope() : null,
            'is_active' => $this->isActive(),
            'notify_email' => (bool) $this->notify_email,
            'last_login_at' => $this->last_login_at?->format('Y-m-d H:i'),
            'last_seen_at' => $this->last_seen_at?->format('Y-m-d H:i'),
            'is_online' => $this->isOnline(),
            'tickets_count' => $this->whenCounted('tickets'),
            'assigned_tickets_count' => $this->whenCounted('assignedTickets'),
        ];
    }
}
