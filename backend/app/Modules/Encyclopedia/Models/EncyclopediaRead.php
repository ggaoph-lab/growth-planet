<?php

namespace App\Modules\Encyclopedia\Models;

use Illuminate\Database\Eloquent\Model;

class EncyclopediaRead extends Model
{
    protected $table = 'encyclopedia_reads';

    protected $fillable = [
        'family_id',
        'child_id',
        'topic_id',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
        ];
    }
}
