<?php

namespace App\Modules\Poetry\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PoetryImageCache extends Model
{
    protected $table = 'poetry_image_cache';

    protected $fillable = [
        'poem_id',
        'provider',
        'model',
        'prompt',
        'image_url',
        'disk',
        'path',
        'status',
        'error',
    ];

    public function poem(): BelongsTo
    {
        return $this->belongsTo(PoetryPoem::class, 'poem_id');
    }
}
