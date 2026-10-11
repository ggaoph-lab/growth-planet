<?php

namespace Tests\Feature\Growth;

use App\Modules\ScreenTime\Models\ScreenTimeEntry;
use App\Modules\ScreenTime\Services\ScreenTimeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScreenTimeUniqueSourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_screen_time_entry_is_unique_per_source(): void
    {
        ['family' => $family] = $this->makeFamily();
        $child = $this->makeChild($family);
        $service = app(ScreenTimeService::class);

        $first = $service->recordCounted($child, 'english', 'english_watch_log', 42, 600, '2026-10-11');
        $second = $service->recordCounted($child, 'english', 'english_watch_log', 42, 600, '2026-10-11');

        $this->assertNotNull($first);
        $this->assertSame($first->id, $second?->id);
        $this->assertSame(1, ScreenTimeEntry::query()->count());
    }
}
