<?php

namespace App\Modules\Poetry\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PoetryPoemEnrichment extends Model
{
    protected $table = 'poetry_poem_enrichments';

    protected $fillable = [
        'poem_id',
        'story_zh',
        'line_meanings',
        'image_prompt',
        'ai_provider',
        'ai_model',
    ];

    protected $casts = [
        'line_meanings' => 'array',
    ];

    public function poem(): BelongsTo
    {
        return $this->belongsTo(PoetryPoem::class, 'poem_id');
    }
}
