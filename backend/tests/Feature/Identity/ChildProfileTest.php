<?php

namespace Tests\Feature\Identity;

use App\Modules\Core\Models\AuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ChildProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_parent_creates_child_and_birth_date_is_encrypted_at_rest(): void
    {
        ['parent' => $parent] = $this->makeFamily();
        $this->travelTo(CarbonImmutable::parse('2026-10-11 04:00:00 UTC'));

        $id = $this->actingAsParent($parent)->postJson('/api/v1/parent/children', [
            'nickname' => '小月亮',
            'avatar' => 'moon',
            'birth_date' => '2020-03-15',
            'grade' => 1,
            'theme_color' => '#FF9F43',
            'pin' => '2468',
        ])->assertCreated()
            ->assertJsonPath('data.age', 6)
            ->assertJsonPath('data.birth_date', '2020-03-15')
            ->assertJsonPath('data.pin_enabled', true)
            ->assertJsonPath('data.preferences', ['animations' => true, 'sounds' => true])
            ->json('data.id');

        $raw = DB::table('child_profiles')->where('id', $id)->value('birth_date');
        $this->assertStringNotContainsString('2020', $raw);

        $log = AuditLog::where('action', 'child.created')->sole();
        $this->assertSame('[已隐藏]', $log->changes['birth_date']);
        $this->assertSame('[已隐藏]', $log->changes['pin']);
    }

    public function test_validation_rejects_bad_input(): void
    {
        ['parent' => $parent] = $this->makeFamily();

        $this->actingAsParent($parent)->postJson('/api/v1/parent/children', [
            'nickname' => '',
            'avatar' => 'not-an-avatar',
            'birth_date' => '2999-01-01',
            'grade' => 9,
            'theme_color' => 'red',
            'pin' => '12',
        ])->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonValidationErrors(['nickname', 'avatar', 'birth_date', 'grade', 'theme_color', 'pin'], 'error.details.fields');
    }

    public function test_age_follows_family_timezone(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family, ['birth_date' => '2019-10-12']);

        $this->travelTo(CarbonImmutable::parse('2026-10-11 15:59:00 UTC'));
        $this->actingAsParent($parent)->getJson("/api/v1/parent/children/{$child->id}")->assertJsonPath('data.age', 6);

        $this->travelTo(CarbonImmutable::parse('2026-10-11 16:00:00 UTC'));
        $this->getJson("/api/v1/parent/children/{$child->id}")->assertJsonPath('data.age', 7);
    }

    public function test_plain_update_cannot_change_birth_date(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);

        $this->actingAsParent($parent)
            ->patchJson("/api/v1/parent/children/{$child->id}", ['nickname' => '大星星', 'birth_date' => '2010-01-01'])
            ->assertOk()
            ->assertJsonPath('data.nickname', '大星星')
            ->assertJsonPath('data.birth_date', '2019-09-01');

        $this->assertSame('大星星', $child->member->refresh()->display_name);
    }

    public function test_update_with_status_and_profile_fields_succeeds(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);

        // Mirrors the parent UI payload (nickname + avatar + grade + theme + status).
        $this->actingAsParent($parent)
            ->patchJson("/api/v1/parent/children/{$child->id}", [
                'nickname' => '小火箭',
                'avatar' => 'rocket',
                'grade' => 2,
                'theme_color' => '#FF6B9A',
                'status' => 'active',
            ])
            ->assertOk()
            ->assertJsonPath('data.nickname', '小火箭')
            ->assertJsonPath('data.avatar', 'rocket')
            ->assertJsonPath('data.grade', 2)
            ->assertJsonPath('data.theme_color', '#FF6B9A')
            ->assertJsonPath('data.status', 'active');

        $this->assertSame('小火箭', $child->fresh()->nickname);
        $this->assertSame('小火箭', $child->member->refresh()->display_name);
    }

    public function test_birth_date_change_requires_sudo_and_is_audited(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);

        $this->actingAsParent($parent)
            ->putJson("/api/v1/parent/children/{$child->id}/birth-date", ['birth_date' => '2019-05-01'])
            ->assertStatus(423);

        $this->actingAsParent($parent, sudo: true)
            ->putJson("/api/v1/parent/children/{$child->id}/birth-date", ['birth_date' => '2019-05-01'])
            ->assertOk()
            ->assertJsonPath('data.birth_date', '2019-05-01');

        $this->assertTrue(AuditLog::where('action', 'child.birth_date_changed')->exists());
    }

    public function test_pin_can_be_disabled(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);

        $this->actingAsParent($parent, sudo: true)
            ->putJson("/api/v1/parent/children/{$child->id}/pin", ['pin' => null])
            ->assertOk()
            ->assertJsonPath('data.pin_enabled', false);
    }

    public function test_audit_logs_are_append_only_in_the_database(): void
    {
        ['family' => $family] = $this->makeFamily();
        $this->makeChild($family);
        $id = AuditLog::query()->value('id');

        try {
            DB::table('audit_logs')->where('id', $id)->update(['action' => 'tampered']);
            $this->fail('UPDATE on audit_logs should be rejected');
        } catch (QueryException $e) {
            $this->assertStringContainsString('append-only', $e->getMessage());
        }

        $this->expectException(QueryException::class);
        DB::table('audit_logs')->where('id', $id)->delete();
    }

    public function test_dashboard_summarises_family(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $this->makeChild($family);
        $this->makeDevice($family, $parent);

        $this->actingAsParent($parent)->getJson('/api/v1/parent/dashboard')
            ->assertOk()
            ->assertJsonCount(1, 'data.children')
            ->assertJsonPath('data.active_devices', 1)
            ->assertJsonPath('data.family.timezone', 'Asia/Shanghai');
    }
}
