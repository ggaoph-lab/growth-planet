<?php

namespace Tests\Feature\Growth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FamilyReminderTest extends TestCase
{
    use RefreshDatabase;

    public function test_paired_device_can_read_its_family_dashboard(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $this->makeChild($family);
        [, $token] = $this->makeDevice($family, $parent);

        $this->getJson('/api/v1/device/family-dashboard')->assertUnauthorized();
        $this->withCookie('gp_device', $token)->getJson('/api/v1/device/family-dashboard')
            ->assertOk()->assertJsonStructure(['data' => ['date', 'tasks']]);
    }

    public function test_parent_edits_weekly_multi_time_reminder_and_only_paired_family_device_sees_it(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        ['family' => $otherFamily, 'parent' => $otherParent] = $this->makeFamily('other-mama');
        [, $token] = $this->makeDevice($family, $parent);
        [, $otherToken] = $this->makeDevice($otherFamily, $otherParent);

        $id = $this->actingAsParent($parent)->postJson('/api/v1/parent/family-reminders', [
            'title' => '整理书包', 'weekdays' => [1, 3, 5], 'times' => ['07:30', '19:00'],
        ])->assertCreated()->assertJsonPath('data.times.1', '19:00')->json('data.id');

        $this->withCookie('gp_device', $token)->getJson('/api/v1/device/family-reminders')
            ->assertOk()->assertJsonPath('data.0.id', $id);
        $this->withCookie('gp_device', $otherToken)->getJson('/api/v1/device/family-reminders')
            ->assertOk()->assertJsonCount(0, 'data');

        $this->actingAsParent($parent)->putJson('/api/v1/parent/family-reminders/'.$id, [
            'title' => '整理书包', 'weekdays' => [2, 4], 'times' => ['07:20', '19:20'],
        ])->assertOk()->assertJsonPath('data.weekdays.0', 2);
        $this->actingAsParent($parent)->postJson('/api/v1/parent/family-reminders', [
            'title' => '重复时间', 'weekdays' => [1], 'times' => ['08:00', '08:00'],
        ])->assertUnprocessable();
    }
}
