<?php

namespace Tests\Feature\Growth;

use App\Modules\Task\Enums\TaskInstanceStatus;
use App\Modules\Task\Models\TaskInstance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ParentTaskApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_parent_can_create_task_via_api(): void
    {
        ['parent' => $parent] = $this->makeFamily();
        $this->actingAsParent($parent)->postJson('/api/v1/parent/tasks', [
            'title' => '阅读',
            'kind' => 'standard',
            'start_date' => '2026-10-01',
            'schedules' => [['frequency' => 'daily']],
        ])->assertCreated()
            ->assertJsonPath('data.title', '阅读')
            ->assertJsonPath('data.requires_approval', false)
            ->assertJsonPath('data.with_parent', false);
    }

    public function test_parent_can_mark_task_with_parent(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);

        $created = $this->actingAsParent($parent)->postJson('/api/v1/parent/tasks', [
            'title' => '一起整理书包',
            'kind' => 'standard',
            'start_date' => '2026-10-12',
            'end_date' => '2026-10-12',
            'child_id' => $child->id,
            'with_parent' => true,
            'schedules' => [['frequency' => 'once']],
        ])->assertCreated()
            ->assertJsonPath('data.with_parent', true)
            ->json('data');

        $this->actingAsParent($parent)->getJson('/api/v1/parent/task-instances?date=2026-10-12&child_id='.$child->id)
            ->assertOk();

        $instance = TaskInstance::query()->where('task_id', $created['id'])->first();
        $this->assertNotNull($instance);
        $this->assertTrue((bool) ($instance->snapshot['with_parent'] ?? false));
    }

    public function test_parent_can_create_weekly_task_with_time_and_end_date(): void
    {
        ['parent' => $parent] = $this->makeFamily();

        $response = $this->actingAsParent($parent)->postJson('/api/v1/parent/tasks', [
            'title' => '钢琴练习',
            'kind' => 'standard',
            'start_date' => '2026-10-12',
            'end_date' => '2026-12-31',
            'xp_reward' => 8,
            'coin_reward' => 4,
            'schedules' => [[
                'frequency' => 'weekly',
                'weekdays' => [1],
                'due_time' => '13:20',
            ]],
        ])->assertCreated()
            ->assertJsonPath('data.title', '钢琴练习')
            ->assertJsonPath('data.schedules.0.frequency', 'weekly')
            ->assertJsonPath('data.schedules.0.weekdays.0', 1);

        $endDate = (string) $response->json('data.end_date');
        $this->assertTrue(str_starts_with($endDate, '2026-12-31'), 'end_date should be 2026-12-31, got '.$endDate);
        $dueTime = (string) $response->json('data.schedules.0.due_time');
        $this->assertTrue(str_starts_with($dueTime, '13:20'), 'due_time should start with 13:20, got '.$dueTime);
    }

    public function test_parent_can_update_and_delete_task_including_steps(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);

        $created = $this->actingAsParent($parent)->postJson('/api/v1/parent/tasks', [
            'title' => '旧标题',
            'kind' => 'stepped',
            'start_date' => '2026-10-12',
            'end_date' => '2026-10-12',
            'child_id' => $child->id,
            'steps' => [
                ['title' => '步骤一'],
                ['title' => '步骤二'],
            ],
            'schedules' => [['frequency' => 'once', 'due_time' => '09:00']],
        ])->assertCreated()->json('data');

        $this->actingAsParent($parent)->getJson('/api/v1/parent/task-instances?date=2026-10-12&child_id='.$child->id)
            ->assertOk();

        $updated = $this->actingAsParent($parent)->putJson('/api/v1/parent/tasks/'.$created['id'], [
            'title' => '新标题',
            'xp_reward' => 20,
            'steps' => [
                ['title' => '新步骤A'],
                ['title' => '新步骤B'],
                ['title' => '新步骤C'],
            ],
            'schedules' => [['frequency' => 'once', 'due_time' => '15:30']],
        ])->assertOk()
            ->assertJsonPath('data.title', '新标题')
            ->assertJsonPath('data.xp_reward', 20)
            ->assertJsonCount(3, 'data.steps')
            ->assertJsonPath('data.steps.0.title', '新步骤A')
            ->json('data');

        $dueTime = (string) data_get($updated, 'schedules.0.due_time');
        $this->assertTrue(str_starts_with($dueTime, '15:30'), 'due_time should start with 15:30, got '.$dueTime);

        $instance = TaskInstance::query()->where('task_id', $created['id'])->first();
        $this->assertNotNull($instance);
        $this->assertSame(TaskInstanceStatus::Pending, $instance->status);
        $this->assertSame('新标题', $instance->snapshot['title']);
        $this->assertSame(20, $instance->snapshot['xp_reward']);
        $this->assertCount(3, $instance->snapshot['steps']);
        $this->assertSame($updated['version'], $instance->task_version);

        $this->actingAsParent($parent)->deleteJson('/api/v1/parent/tasks/'.$created['id'])
            ->assertOk()
            ->assertJsonPath('data.deleted', true);

        $this->assertSoftDeleted('tasks', ['id' => $created['id']]);
    }
}
