<?php

namespace App\Modules\English\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class EnglishShow extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'family_id',
        'title',
        'emoji',
        'note',
        'active',
        'sort',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }
}
