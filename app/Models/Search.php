<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Search extends Model
{
    protected $fillable = [
        'domain',
        'google_place_id',
        'business_name',
        'sources',
        'ratings',
        'total_reviews',
        'google_reviews',
        'trustpilot_reviews',
        'status',
    ];

    protected $casts = [
        'sources' => 'array',
        'ratings' => 'array',
    ];

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }
}
