<?php

namespace Tests\Feature\Growth;

use App\Modules\Task\Enums\TaskInstanceStatus;
use App\Modules\Task\Models\TaskCategory;
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

    public function test_subtasks_fold_under_one_title_and_keep_their_own_times(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);

        $categoryId = $this->actingAsParent($parent)->postJson('/api/v1/parent/task-categories', [
            'name' => '学习',
        ])->assertCreated()->json('data.id');

        $created = $this->actingAsParent($parent)->postJson('/api/v1/parent/tasks', [
            'title' => '下午安排',
            'kind' => 'standard',
            'start_date' => '2026-10-12',
            'end_date' => '2026-10-12',
            'child_id' => $child->id,
            'category_id' => $categoryId,
            'xp_reward' => 0,
            'coin_reward' => 0,
            'subtasks' => [
                [
                    'title' => '练琴',
                    'start_date' => '2026-10-12',
                    'end_date' => '2026-10-12',
                    'xp_reward' => 6,
                    'coin_reward' => 2,
                    'with_parent' => true,
                    'schedules' => [['frequency' => 'once', 'due_time' => '14:00']],
                ],
                [
                    'title' => '阅读',
                    'start_date' => '2026-10-12',
                    'end_date' => '2026-12-31',
                    'xp_reward' => 8,
                    'coin_reward' => 3,
                    'with_parent' => false,
                    'schedules' => [[
                        'frequency' => 'weekly',
                        'weekdays' => [1],
                        'due_time' => '15:30',
                    ]],
                ],
            ],
            'schedules' => [['frequency' => 'once']],
        ])->assertCreated()
            ->assertJsonPath('data.title', '下午安排')
            ->assertJsonPath('data.category_id', $categoryId)
            ->assertJsonPath('data.task_category.name', '学习')
            ->assertJsonCount(2, 'data.children')
            ->json('data');

        $piano = collect($created['children'])->firstWhere('title', '练琴');
        $reading = collect($created['children'])->firstWhere('title', '阅读');
        $this->assertNotNull($piano);
        $this->assertNotNull($reading);
        $this->assertSame(6, $piano['xp_reward']);
        $this->assertSame(2, $piano['coin_reward']);
        $this->assertTrue((bool) $piano['with_parent']);
        $this->assertSame(8, $reading['xp_reward']);
        $this->assertSame('weekly', $reading['schedules'][0]['frequency'] ?? null);

        $this->actingAsParent($parent)->getJson('/api/v1/parent/tasks')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', '下午安排')
            ->assertJsonPath('data.0.children.0.title', '练琴');

        $this->actingAsParent($parent)
            ->getJson('/api/v1/parent/task-instances?date=2026-10-12&child_id='.$child->id)
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $instances = TaskInstance::query()->orderBy('id')->get();
        $this->assertCount(2, $instances);
        $this->assertFalse($instances->contains('task_id', $created['id']));
        $titles = $instances->map(fn ($instance) => $instance->snapshot['title'])->sort()->values()->all();
        $this->assertSame(['练琴', '阅读'], $titles);
        $this->assertTrue($instances->every(fn ($instance) => ($instance->snapshot['group_title'] ?? null) === '下午安排'));
        $this->assertTrue($instances->every(fn ($instance) => ($instance->snapshot['group_task_id'] ?? null) === $created['id']));

        $pianoInstance = $instances->firstWhere(fn ($instance) => $instance->snapshot['title'] === '练琴');
        $this->assertNotNull($pianoInstance);
        $this->assertTrue((bool) ($pianoInstance->snapshot['with_parent'] ?? false));
        $this->assertSame(6, $pianoInstance->snapshot['xp_reward'] ?? null);

        $this->actingAsParent($parent)->putJson('/api/v1/parent/tasks/'.$created['id'], [
            'title' => '放学后',
            'category_id' => $categoryId,
            'subtasks' => [
                [
                    'id' => $reading['id'],
                    'title' => '阅读',
                    'start_date' => '2026-10-12',
                    'end_date' => '2026-12-31',
                    'xp_reward' => 8,
                    'coin_reward' => 3,
                    'with_parent' => false,
                    'schedules' => [['frequency' => 'once', 'due_time' => '16:00']],
                ],
                [
                    'title' => '运动',
                    'start_date' => '2026-10-12',
                    'end_date' => '2026-10-12',
                    'xp_reward' => 5,
                    'coin_reward' => 1,
                    'schedules' => [['frequency' => 'once', 'due_time' => '17:00']],
                ],
            ],
            'schedules' => [['frequency' => 'once']],
        ])->assertOk()
            ->assertJsonPath('data.title', '放学后')
            ->assertJsonCount(2, 'data.children');

        $this->assertSoftDeleted('tasks', ['id' => $piano['id']]);

        $readingInstance = TaskInstance::query()->where('task_id', $reading['id'])->first();
        $this->assertNotNull($readingInstance);
        $this->assertSame(TaskInstanceStatus::Pending, $readingInstance->status);
        $this->assertSame('阅读', $readingInstance->snapshot['title']);
        $this->assertSame('放学后', $readingInstance->snapshot['group_title']);
        $this->assertTrue(str_starts_with((string) $readingInstance->snapshot['due_time'], '16:00'));

        $this->actingAsParent($parent)->deleteJson('/api/v1/parent/tasks/'.$created['id'])->assertOk();
        $this->assertSoftDeleted('tasks', ['id' => $created['id']]);
        $this->assertSoftDeleted('tasks', ['id' => $reading['id']]);
    }

    public function test_task_categories_can_be_renamed_and_deleted(): void
    {
        ['parent' => $parent] = $this->makeFamily('mama_cat');

        $id = $this->actingAsParent($parent)->postJson('/api/v1/parent/task-categories', [
            'name' => '家务',
        ])->assertCreated()
            ->assertJsonPath('data.name', '家务')
            ->json('data.id');

        $this->actingAsParent($parent)->putJson('/api/v1/parent/task-categories/'.$id, [
            'name' => '劳动',
        ])->assertOk()->assertJsonPath('data.name', '劳动');

        $this->actingAsParent($parent)->postJson('/api/v1/parent/task-categories', [
            'name' => '劳动',
        ])->assertStatus(422);

        $this->actingAsParent($parent)->getJson('/api/v1/parent/task-categories')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->actingAsParent($parent)->postJson('/api/v1/parent/tasks', [
            'title' => '扫地',
            'kind' => 'standard',
            'start_date' => '2026-10-12',
            'category_id' => $id,
            'schedules' => [['frequency' => 'once']],
        ])->assertCreated()->assertJsonPath('data.task_category.name', '劳动');

        $this->actingAsParent($parent)->deleteJson('/api/v1/parent/task-categories/'.$id)
            ->assertOk()
            ->assertJsonPath('data.deleted', true);

        $this->actingAsParent($parent)->getJson('/api/v1/parent/tasks')
            ->assertOk()
            ->assertJsonPath('data.0.category_id', null)
            ->assertJsonPath('data.0.task_category', null);

        $this->assertDatabaseMissing('task_categories', ['id' => $id]);
    }

    public function test_another_family_cannot_use_a_task_category(): void
    {
        ['family' => $familyA] = $this->makeFamily('mama_cat_a');
        ['parent' => $parentB] = $this->makeFamily('mama_cat_b');

        $category = TaskCategory::query()->create([
            'family_id' => $familyA->id,
            'name' => '家务',
            'sort' => 1,
        ]);

        $this->actingAsParent($parentB)->getJson('/api/v1/parent/task-categories')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAsParent($parentB)->putJson('/api/v1/parent/task-categories/'.$category->id, [
            'name' => '偷看',
        ])->assertNotFound();

        $this->actingAsParent($parentB)->postJson('/api/v1/parent/tasks', [
            'title' => '越权',
            'kind' => 'standard',
            'start_date' => '2026-10-12',
            'category_id' => $category->id,
            'schedules' => [['frequency' => 'once']],
        ])->assertStatus(422);
    }
}
