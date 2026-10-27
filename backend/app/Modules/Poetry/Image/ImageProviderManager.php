<?php

namespace App\Modules\Poetry\Image;

use App\Modules\Core\Settings\Settings;
use InvalidArgumentException;

/**
 * Adapter registry — swap providers via poetry.image_provider setting (or config default).
 * Flux / DALL·E / Midjourney can be added as new ImageProvider implementations without
 * changing call sites.
 */
class ImageProviderManager
{
    /** @var array<string, ImageProvider> */
    private array $providers;

    public function __construct(
        MockImageProvider $mock,
        WanxImageProvider $wanx,
    ) {
        $this->providers = [
            $mock->name() => $mock,
            $wanx->name() => $wanx,
        ];
    }

    public function resolve(?int $familyId = null): ImageProvider
    {
        $name = $familyId !== null
            ? (string) app(Settings::class)->get('poetry.image_provider', $familyId)
            : (string) config('poetry.image.default_provider', 'mock');

        return $this->get($name);
    }

    public function get(string $name): ImageProvider
    {
        $provider = $this->providers[$name] ?? null;
        if ($provider === null) {
            throw new InvalidArgumentException("Unknown image provider [{$name}]");
        }

        return $provider;
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->providers);
    }
}
