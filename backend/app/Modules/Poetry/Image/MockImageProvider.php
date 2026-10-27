<?php

namespace App\Modules\Poetry\Image;

/**
 * Offline scene illustrator — unique SVG per poem wording, no network / API cost.
 */
class MockImageProvider implements ImageProvider
{
    public function __construct(
        private readonly PoetrySceneSvg $scenes,
    ) {}

    public function name(): string
    {
        return 'mock';
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function generate(string $prompt): array
    {
        $rendered = $this->scenes->render($prompt);

        return [
            'url' => $rendered['url'],
            'model' => 'mock-scene-svg:'.$rendered['scene'],
        ];
    }
}
