<?php

namespace Tests\Feature\Identity;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SudoTest extends TestCase
{
    use RefreshDatabase;

    public function test_sensitive_action_requires_recent_password_confirmation(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        $this->actingAsParent($parent);

        $this->putJson("/api/v1/parent/children/{$child->id}/pin", ['pin' => '5678'])
            ->assertStatus(423)
            ->assertJsonPath('error.code', 'SUDO_REQUIRED');

        $this->postJson('/api/v1/auth/confirm-password', ['password' => 'wrong-password'])->assertStatus(422);

        $this->postJson('/api/v1/auth/confirm-password', ['password' => self::PARENT_PASSWORD])
            ->assertOk()
            ->assertJsonStructure(['data' => ['sudo_until']]);

        $this->putJson("/api/v1/parent/children/{$child->id}/pin", ['pin' => '5678'])->assertOk();
    }

    public function test_sudo_expires_after_ttl(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        $this->actingAsParent($parent);

        $this->postJson('/api/v1/auth/confirm-password', ['password' => self::PARENT_PASSWORD])->assertOk();
        $this->travel(11)->minutes();

        $this->putJson("/api/v1/parent/children/{$child->id}/pin", ['pin' => '5678'])->assertStatus(423);
    }

    public function test_timezone_change_needs_sudo_but_rename_does_not(): void
    {
        ['parent' => $parent] = $this->makeFamily();
        $this->actingAsParent($parent);

        $this->patchJson('/api/v1/parent/family', ['name' => '星星之家'])->assertOk()->assertJsonPath('data.name', '星星之家');
        $this->patchJson('/api/v1/parent/family', ['timezone' => 'Asia/Tokyo'])->assertStatus(423);
        $this->patchJson('/api/v1/parent/family', ['timezone' => 'Not/AZone'])->assertStatus(422);

        $this->actingAsParent($parent, sudo: true);
        $this->patchJson('/api/v1/parent/family', ['timezone' => 'Asia/Tokyo'])->assertOk()->assertJsonPath('data.timezone', 'Asia/Tokyo');
    }

    public function test_confirm_password_is_throttled(): void
    {
        ['parent' => $parent] = $this->makeFamily();
        $this->actingAsParent($parent);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/confirm-password', ['password' => 'wrong'.$i])->assertStatus(422);
        }
        $this->postJson('/api/v1/auth/confirm-password', ['password' => self::PARENT_PASSWORD])->assertStatus(429);
    }
}
