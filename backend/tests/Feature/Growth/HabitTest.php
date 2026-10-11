<?php

namespace Tests\Feature\Growth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class HabitTest extends TestCase
{
    use RefreshDatabase;

    public function test_habit_check_in_once_per_day(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        [$device, $token] = $this->makeDevice($family, $parent);
        $habit = $this->seedHabit($family, $child);

        $kid = fn () => $this->loginKid($child->fresh(['member.user']), $device, $token);

        $kid()->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/kid/habits/{$habit->id}/records")
            ->assertCreated();
        $kid()->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/kid/habits/{$habit->id}/records")
            ->assertCreated();

        $wallet = $kid()->getJson('/api/v1/kid/wallet')->json('data');
        $this->assertSame(3, $wallet['xp']['balance']);
        $this->assertSame(1, $wallet['coin']['balance']);
    }
}
