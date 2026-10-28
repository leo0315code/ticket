<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    protected $fillable = ['name', 'description', 'default_agent_id'];

    /** 该分类下新工单的默认受理人（自动分配优先用） */
    public function defaultAgent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'default_agent_id');
    }

    /** @return HasMany<Ticket, $this> */
    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }
}
