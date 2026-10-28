<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FaqSearchLog extends Model
{
    const UPDATED_AT = null;

    protected $fillable = ['keyword', 'results_count', 'user_id'];

    protected $casts = ['results_count' => 'integer'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
