<?php

namespace App\Modules\Poetry\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class PoetryPoem extends Model
{
    protected $table = 'poetry_poems';

    protected $fillable = [
        'source',
        'external_id',
        'title',
        'author',
        'dynasty',
        'content',
        'grade_tag',
    ];

    public function enrichment(): HasOne
    {
        return $this->hasOne(PoetryPoemEnrichment::class, 'poem_id');
    }

    public function imageCache(): HasOne
    {
        return $this->hasOne(PoetryImageCache::class, 'poem_id');
    }

    /** @return list<string> */
    public function lines(): array
    {
        $raw = preg_replace("/\r\n?/", "\n", (string) $this->content) ?? '';
        $parts = preg_split("/\n+/", trim($raw)) ?: [];

        return array_values(array_filter(array_map('trim', $parts), fn ($line) => $line !== ''));
    }

    public function plainText(): string
    {
        $joined = implode('', $this->lines());

        return preg_replace('/[，。！？、；：,.!?;:\s]/u', '', $joined) ?? '';
    }
}
