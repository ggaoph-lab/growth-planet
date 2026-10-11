<?php

namespace App\Modules\Movie\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MovieCollectionPreference extends Model
{
    protected $fillable = [
        'family_id',
        'child_id',
        'collection_id',
        'kind',
        'note',
    ];

    /** @return BelongsTo<MovieCollection, $this> */
    public function collection(): BelongsTo
    {
        return $this->belongsTo(MovieCollection::class, 'collection_id');
    }
}
