<?php

namespace App\Modules\Ielts\Models;

use Illuminate\Database\Eloquent\Model;

class IeltsLearningState extends Model
{
    protected $table = 'ielts_learning_states';

    protected $fillable = [
        'child_id',
        'settings',
        'progress',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'progress' => 'array',
        ];
    }
}
