<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Faq extends Model
{
    protected $fillable = ['question', 'answer', 'sort', 'category', 'is_public', 'views', 'helpful_yes', 'helpful_no'];

    protected function casts(): array
    {
        return [
            'is_public' => 'boolean',
            'views' => 'integer',
            'helpful_yes' => 'integer',
            'helpful_no' => 'integer',
        ];
    }
}
