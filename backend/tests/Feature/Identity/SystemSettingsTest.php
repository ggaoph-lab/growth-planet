<?php

namespace Tests\Feature\Identity;

use App\Modules\Core\Settings\Settings;
use App\Modules\Identity\Enums\MemberRole;
use App\Modules\Identity\Enums\MemberStatus;
use App\Modules\Identity\Models\FamilyMember;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SystemSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_parent_can_update_default_locale(): void
    {
        ['parent' => $parent] = $this->makeFamily();

        $this->actingAsParent($parent)
            ->patchJson('/api/v1/parent/family', ['locale' => 'en'])
            ->assertOk()
            ->assertJsonPath('data.locale', 'en');
    }

    public function test_parent_can_add_and_remove_another_parent(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();

        $this->actingAsParent($parent, sudo: true)
            ->postJson('/api/v1/parent/parents', [
                'display_name' => '爸爸',
                'username' => 'baba',
                'password' => 'another-long-password',
            ])
            ->assertCreated()
            ->assertJsonPath('data.username', 'baba')
            ->assertJsonPath('data.display_name', '爸爸');

        $this->assertTrue(Hash::check('another-long-password', User::query()->where('username', 'baba')->firstOrFail()->password));

        $other = FamilyMember::query()
            ->where('family_id', $family->id)
            ->where('role', MemberRole::Parent)
            ->where('display_name', '爸爸')
            ->firstOrFail();

        $this->actingAsParent($parent, sudo: true)
            ->deleteJson('/api/v1/parent/parents/'.$other->id)
            ->assertOk();

        $other->refresh()->load('user');
        $this->assertSame(MemberStatus::Disabled, $other->status);
        $this->assertNull($other->user?->username);
    }

    public function test_cannot_delete_self_or_last_parent(): void
    {
        ['parent' => $parent] = $this->makeFamily();
        $memberId = $parent->member->id;

        $this->actingAsParent($parent, sudo: true)
            ->deleteJson('/api/v1/parent/parents/'.$memberId)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_FAILED');
    }

    public function test_parent_can_change_own_password(): void
    {
        ['parent' => $parent] = $this->makeFamily();

        $this->actingAsParent($parent, sudo: true)
            ->putJson('/api/v1/parent/parents/me/password', [
                'current_password' => self::PARENT_PASSWORD,
                'password' => 'brand-new-password',
                'password_confirmation' => 'brand-new-password',
            ])
            ->assertOk();

        $this->assertTrue(Hash::check('brand-new-password', $parent->fresh()->password));
    }

    public function test_ai_settings_store_encrypted_family_keys(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        config(['services.gemini.api_key' => '', 'services.qwen.api_key' => '']);

        $this->actingAsParent($parent, sudo: true)
            ->putJson('/api/v1/parent/ai-settings', [
                'provider' => 'gemini',
                'gemini_api_key' => 'AIza-family-secret-key',
                'gemini_model' => 'gemini-2.5-flash',
            ])
            ->assertOk()
            ->assertJsonPath('data.provider', 'gemini')
            ->assertJsonPath('data.gemini.configured', true)
            ->assertJsonPath('data.gemini.source', 'family')
            ->assertJsonMissingPath('data.gemini.api_key');

        $stored = app(Settings::class)->get('ai.gemini_api_key', $family->id);
        $this->assertSame('AIza-family-secret-key', $stored);

        $this->actingAsParent($parent)
            ->getJson('/api/v1/parent/ai-settings')
            ->assertOk()
            ->assertJsonPath('data.gemini.configured', true)
            ->assertJsonMissingPath('data.gemini.api_key');
    }

    public function test_ai_settings_require_sudo_to_update(): void
    {
        ['parent' => $parent] = $this->makeFamily();

        $this->actingAsParent($parent)
            ->putJson('/api/v1/parent/ai-settings', ['provider' => 'qwen'])
            ->assertStatus(423);
    }
}
