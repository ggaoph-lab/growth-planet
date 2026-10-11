<?php

namespace Tests\Feature\Growth;

use App\Modules\Approval\Enums\ApprovalType;
use App\Modules\Approval\Models\ApprovalRequest;
use App\Modules\Approval\Services\ApprovalService;
use App\Modules\Ledger\Models\CoinAccount;
use App\Modules\Ledger\Models\XpAccount;
use App\Modules\Task\Enums\TaskInstanceStatus;
use App\Modules\Task\Models\TaskInstance;
use App\Modules\Task\Services\TaskInstanceGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TaskApprovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_kid_submit_creates_pending_approval_without_paying(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        [$device, $token] = $this->makeDevice($family, $parent);
        $this->seedTask($family, $parent, [
            'xp_reward' => 15,
            'coin_reward' => 8,
            'requires_approval' => true,
        ]);

        $this->loginKid($child->fresh(['member.user']), $device, $token);

        $instanceId = $this->getJson('/api/v1/kid/tasks?date=2026-10-11')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->json('data.0.id');

        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/kid/task-instances/{$instanceId}/ready")
            ->assertOk();
        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/kid/task-instances/{$instanceId}/submit")
            ->assertOk();

        ApprovalRequest::query()->where('subject_id', $instanceId)->firstOrFail();

        $wallet = $this->getJson('/api/v1/kid/wallet')->json('data');
        $this->assertSame(0, $wallet['xp']['balance']);
        $this->assertSame(0, $wallet['coin']['balance']);
    }

    public function test_parent_approve_grants_xp_and_coins(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        $this->seedTask($family, $parent, [
            'xp_reward' => 15,
            'coin_reward' => 8,
            'requires_approval' => true,
        ]);

        $instance = app(TaskInstanceGenerator::class)
            ->ensureForChildOnDate($family, $child, '2026-10-11')
            ->first();
        $instance->forceFill([
            'status' => TaskInstanceStatus::Submitted,
            'submitted_at' => now(),
        ])->save();

        $approval = app(ApprovalService::class)->createPending(
            $family->id,
            $child->id,
            ApprovalType::TaskCompletion,
            'task_instance',
            $instance->id,
            $child->member->user_id,
        );

        $this->actingAsParent($parent)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/parent/task-instances/{$instance->id}/approve", ['lock_version' => $approval->lock_version])
            ->assertOk();

        $instance->refresh();
        $this->assertSame(TaskInstanceStatus::Completed, $instance->status);
        $this->assertSame(15, XpAccount::query()->where('child_id', $child->id)->value('balance'));
        $this->assertSame(8, CoinAccount::query()->where('child_id', $child->id)->value('balance'));
    }
}
