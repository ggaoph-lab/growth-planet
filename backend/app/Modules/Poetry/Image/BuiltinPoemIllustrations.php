<?php

namespace App\Modules\Poetry\Image;

use App\Modules\Poetry\Models\PoetryPoem;

/**
 * Curated textbook-style 水墨 illustrations for builtin grade-1 poems.
 * Files live in Modules/Poetry/data/illustrations and are mirrored to
 * frontend/public/poetry-art for static serving.
 */
final class BuiltinPoemIllustrations
{
    public function directory(): string
    {
        return dirname(__DIR__).'/data/illustrations';
    }

    public function absolutePath(PoetryPoem $poem): ?string
    {
        $key = trim((string) ($poem->external_id ?? ''));
        if ($key === '' || $poem->source !== 'builtin') {
            return null;
        }
        $path = $this->directory().'/'.$key.'.jpg';

        return is_file($path) ? $path : null;
    }

    /**
     * Browser URL (same-origin static asset under the SPA).
     */
    public function publicUrl(PoetryPoem $poem): ?string
    {
        if ($this->absolutePath($poem) === null) {
            return null;
        }

        return '/poetry-art/'.rawurlencode((string) $poem->external_id).'.jpg';
    }
}
