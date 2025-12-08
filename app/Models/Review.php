<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Review extends Model
{
    protected $fillable = [
        'search_id',
        'source',
        'rating',
        'text',
        'date',
        'author',
    ];

    protected $casts = [
        'date' => 'date',
    ];

    public function search(): BelongsTo
    {
        return $this->belongsTo(Search::class);
    }
}
