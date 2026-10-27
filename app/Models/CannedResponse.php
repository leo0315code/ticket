<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** 快捷回复模板（客服共用的常用话术） */
class CannedResponse extends Model
{
    protected $fillable = ['title', 'body', 'category_id', 'created_by', 'uses'];

    protected function casts(): array
    {
        return ['uses' => 'integer'];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
