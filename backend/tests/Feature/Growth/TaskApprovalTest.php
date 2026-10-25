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
use App\Modules\Task\Services\TaskInstanceGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TaskApprovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_kid_submit_completes_and_pays_without_an_approval(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        [$device, $token] = $this->makeDevice($family, $parent);
        $this->seedTask($family, $parent, [
            'xp_reward' => 15,
            'coin_reward' => 8,
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
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.can_withdraw', true);

        $this->assertSame(0, ApprovalRequest::query()->where('subject_id', $instanceId)->count());
        $this->assertSame(15, XpAccount::query()->where('child_id', $child->id)->value('balance'));
        $this->assertSame(8, CoinAccount::query()->where('child_id', $child->id)->value('balance'));
    }

    public function test_kid_can_submit_directly_from_pending_without_ready(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        [$device, $token] = $this->makeDevice($family, $parent);
        $this->seedTask($family, $parent, [
            'xp_reward' => 15,
            'coin_reward' => 8,
        ]);

        $this->loginKid($child->fresh(['member.user']), $device, $token);

        $instanceId = $this->getJson('/api/v1/kid/tasks?date=2026-10-11')
            ->assertOk()
            ->json('data.0.id');

        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/kid/task-instances/{$instanceId}/submit")
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.can_withdraw', true);

        $this->assertSame(15, XpAccount::query()->where('child_id', $child->id)->value('balance'));
        $this->assertSame(8, CoinAccount::query()->where('child_id', $child->id)->value('balance'));
    }

    public function test_pending_task_approvals_are_finished_and_hidden_from_the_approval_center(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        $this->seedTask($family, $parent, [
            'title' => '整理书包',
            'xp_reward' => 15,
            'coin_reward' => 8,
        ]);

        $instance = app(TaskInstanceGenerator::class)
            ->ensureForChildOnDate($family, $child, '2026-10-11')
            ->first();
        $instance->forceFill([
            'status' => TaskInstanceStatus::Submitted,
            'submitted_at' => now(),
        ])->save();
        app(ApprovalService::class)->createPending(
            $family->id,
            $child->id,
            ApprovalType::TaskCompletion,
            'task_instance',
            $instance->id,
            $child->member->user_id,
        );

        $this->actingAsParent($parent)
            ->getJson('/api/v1/parent/approvals?status=PENDING')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $instance->refresh();
        $this->assertSame(TaskInstanceStatus::Completed, $instance->status);
        $this->assertSame(ApprovalStatus::Cancelled, ApprovalRequest::query()->where('subject_id', $instance->id)->firstOrFail()->status);
        $this->assertSame(15, XpAccount::query()->where('child_id', $child->id)->value('balance'));
        $this->assertSame(8, CoinAccount::query()->where('child_id', $child->id)->value('balance'));
    }

    public function test_kid_can_withdraw_a_completion_and_submit_again(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        [$device, $token] = $this->makeDevice($family, $parent);
        $this->seedTask($family, $parent, [
            'title' => '整理书包',
            'xp_reward' => 15,
            'coin_reward' => 8,
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
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.can_withdraw', true);

        $this->assertSame(15, XpAccount::query()->where('child_id', $child->id)->value('balance'));

        $this->loginKid($child->fresh(['member.user']), $device, $token)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/kid/task-instances/{$instanceId}/withdraw")
            ->assertOk()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.can_withdraw', false);

        $this->assertSame(0, XpAccount::query()->where('child_id', $child->id)->value('balance'));
        $this->assertSame(0, CoinAccount::query()->where('child_id', $child->id)->value('balance'));

        $this->loginKid($child->fresh(['member.user']), $device, $token)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/kid/task-instances/{$instanceId}/ready")
            ->assertOk();
        $this->loginKid($child->fresh(['member.user']), $device, $token)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/kid/task-instances/{$instanceId}/submit")
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');

        $this->assertSame(15, XpAccount::query()->where('child_id', $child->id)->value('balance'));
        $this->assertSame(8, CoinAccount::query()->where('child_id', $child->id)->value('balance'));
        $this->assertSame(0, ApprovalRequest::query()->where('subject_id', $instanceId)->count());
    }

    public function test_kid_can_undo_a_completion_that_was_paid_immediately(): void
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

    public function test_kid_can_withdraw_a_completion_that_caused_a_level_up(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        [$device, $token] = $this->makeDevice($family, $parent);
        // Level 1 needs 25 XP; 30 XP → level 2 with 5 remaining. Withdraw used to fail here.
        $this->seedTask($family, $parent, [
            'xp_reward' => 30,
            'coin_reward' => 8,
        ]);

        $this->loginKid($child->fresh(['member.user']), $device, $token);
        $instanceId = $this->getJson('/api/v1/kid/tasks?date=2026-10-11')
            ->assertOk()
            ->json('data.0.id');

        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/kid/task-instances/{$instanceId}/submit")
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');

        $xp = XpAccount::query()->where('child_id', $child->id)->firstOrFail();
        $this->assertSame(2, $xp->level);
        $this->assertSame(5, $xp->balance);

        $this->loginKid($child->fresh(['member.user']), $device, $token)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/kid/task-instances/{$instanceId}/withdraw")
            ->assertOk()
            ->assertJsonPath('data.status', 'pending');

        $xp = $xp->fresh();
        $this->assertSame(1, $xp->level);
        $this->assertSame(0, $xp->balance);
        $this->assertSame(0, CoinAccount::query()->where('child_id', $child->id)->value('balance'));
    }

    public function test_kid_cannot_withdraw_a_completion_the_parent_already_approved(): void
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
        ApprovalRequest::query()->create([
            'family_id' => $family->id,
            'child_id' => $child->id,
            'type' => ApprovalType::TaskCompletion,
            'subject_type' => 'task_instance',
            'subject_id' => $instance->id,
            'status' => ApprovalStatus::Approved,
            'requested_by' => $child->member->user_id,
            'requested_at' => now(),
            'decided_by' => $parent->id,
            'decided_at' => now(),
            'lock_version' => 1,
        ]);

        $this->loginKid($child->fresh(['member.user']), $device, $token)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/kid/task-instances/{$instance->id}/withdraw")
            ->assertStatus(409);

        $this->assertSame(15, XpAccount::query()->where('child_id', $child->id)->value('balance'));
    }
}
