<?php

namespace Tests\Feature\Growth;

use App\Modules\Approval\Enums\ApprovalStatus;
use App\Modules\Approval\Enums\ApprovalType;
use App\Modules\Approval\Models\ApprovalRequest;
use App\Modules\Approval\Services\ApprovalService;
use App\Modules\Ledger\DTO\RewardIntent;
use App\Modules\Ledger\Enums\SourceModule;
use App\Modules\Ledger\Models\CoinAccount;
use App\Modules\Ledger\Models\XpAccount;
use App\Modules\Ledger\Services\RewardGrantService;
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

    public function test_kid_can_withdraw_a_submitted_task_and_submit_again(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        [$device, $token] = $this->makeDevice($family, $parent);
        $this->seedTask($family, $parent, [
            'title' => '整理书包',
            'xp_reward' => 15,
            'coin_reward' => 8,
            'requires_approval' => false,
        ]);

        $this->loginKid($child->fresh(['member.user']), $device, $token);
        $instanceId = $this->getJson('/api/v1/kid/tasks?date=2026-10-11')
            ->assertOk()
            ->json('data.0.id');

        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/kid/task-instances/{$instanceId}/ready")
            ->assertOk();
        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/kid/task-instances/{$instanceId}/submit")
            ->assertOk()
            ->assertJsonPath('data.can_withdraw', true);

        $this->loginParent($parent)
            ->getJson('/api/v1/parent/approvals?status=PENDING')
            ->assertOk()
            ->assertJsonPath('data.0.type', 'task_completion')
            ->assertJsonPath('data.0.child_name', '小星星')
            ->assertJsonPath('data.0.summary.title', '整理书包')
            ->assertJsonPath('data.0.summary.xp_reward', 15)
            ->assertJsonPath('data.0.summary.coin_reward', 8);

        $this->loginKid($child->fresh(['member.user']), $device, $token)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/kid/task-instances/{$instanceId}/withdraw")
            ->assertOk()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.can_withdraw', false);

        $this->assertSame(
            ApprovalStatus::Cancelled,
            ApprovalRequest::query()->where('subject_id', $instanceId)->firstOrFail()->status,
        );
        $this->loginParent($parent)
            ->getJson('/api/v1/parent/approvals?status=PENDING')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->loginKid($child->fresh(['member.user']), $device, $token)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/kid/task-instances/{$instanceId}/ready")
            ->assertOk();
        $this->loginKid($child->fresh(['member.user']), $device, $token)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/kid/task-instances/{$instanceId}/submit")
            ->assertOk()
            ->assertJsonPath('data.status', 'submitted');

        $this->assertSame(1, ApprovalRequest::query()
            ->where('subject_id', $instanceId)
            ->where('status', ApprovalStatus::Pending)
            ->count());
    }

    public function test_kid_can_undo_a_legacy_completion_that_was_paid_immediately(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        [$device, $token] = $this->makeDevice($family, $parent);
        $this->seedTask($family, $parent, [
            'xp_reward' => 15,
            'coin_reward' => 8,
        ]);

        $instance = app(TaskInstanceGenerator::class)
            ->ensureForChildOnDate($family, $child, '2026-10-11')
            ->first();
        $instance->forceFill([
            'status' => TaskInstanceStatus::Completed,
            'completed_at' => now(),
        ])->save();

        app(RewardGrantService::class)->grant(new RewardIntent(
            familyId: $family->id,
            childId: $child->id,
            policyCode: 'task.complete',
            grantKey: 'task:instance:'.$instance->id.':complete',
            sourceModule: SourceModule::Task,
            sourceType: 'task_instance',
            sourceId: $instance->id,
            occurredAt: now()->toImmutable(),
            xpAmount: 15,
            coinAmount: 8,
            postImmediately: true,
        ));

        $this->loginKid($child->fresh(['member.user']), $device, $token)
            ->getJson('/api/v1/kid/tasks?date=2026-10-11')
            ->assertOk()
            ->assertJsonPath('data.0.can_withdraw', true);

        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/kid/task-instances/{$instance->id}/withdraw")
            ->assertOk()
            ->assertJsonPath('data.status', 'pending');

        $this->assertSame(0, XpAccount::query()->where('child_id', $child->id)->value('balance'));
        $this->assertSame(0, CoinAccount::query()->where('child_id', $child->id)->value('balance'));
    }

    public function test_kid_cannot_withdraw_after_parent_approval(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        [$device, $token] = $this->makeDevice($family, $parent);
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
            ->postJson("/api/v1/parent/approvals/{$approval->id}/approve", ['lock_version' => $approval->lock_version])
            ->assertOk();

        $this->loginKid($child->fresh(['member.user']), $device, $token)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/kid/task-instances/{$instance->id}/withdraw")
            ->assertStatus(409);

        $this->assertSame(15, XpAccount::query()->where('child_id', $child->id)->value('balance'));
    }
}
