<?php

namespace App\Modules\Poetry\Image;

interface ImageProvider
{
    public function name(): string;

    public function isConfigured(): bool;

    /**
     * Generate an image and return a publicly reachable URL (or data URI for mock).
     *
     * @return array{url: string, model: ?string}
     */
    public function generate(string $prompt): array;
}
