<?php

namespace Tests\Feature\Growth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ParentTaskApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_parent_can_create_task_via_api(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $this->actingAsParent($parent)->postJson('/api/v1/parent/tasks', [
            'title' => '阅读',
            'kind' => 'standard',
            'start_date' => '2026-10-01',
            'schedules' => [['frequency' => 'daily']],
        ])->assertCreated()
            ->assertJsonPath('data.title', '阅读');
    }
}
