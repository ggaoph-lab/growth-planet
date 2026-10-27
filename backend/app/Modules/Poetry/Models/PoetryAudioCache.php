<?php

namespace App\Modules\Poetry\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PoetryAudioCache extends Model
{
    protected $table = 'poetry_audio_cache';

    protected $fillable = [
        'poem_id',
        'clip_key',
        'provider',
        'voice',
        'model',
        'script_hash',
        'script',
        'disk',
        'path',
        'mime',
        'bytes',
        'status',
        'error',
    ];

    public function poem(): BelongsTo
    {
        return $this->belongsTo(PoetryPoem::class, 'poem_id');
    }
}
