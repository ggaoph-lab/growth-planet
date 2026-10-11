<?php

namespace Tests\Feature\Growth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ExplorationTest extends TestCase
{
    use RefreshDatabase;

    public function test_kid_can_create_exploration_event(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        [$device, $token] = $this->makeDevice($family, $parent);

        $project = $this->loginKid($child->fresh(['member.user']), $device, $token)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/kid/explorations', [
                'title' => '学游泳',
                'category' => 'sport',
            ])
            ->assertCreated()
            ->json('data');

        $this->loginKid($child->fresh(['member.user']), $device, $token)
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/kid/explorations/{$project['id']}/events", [
                'stage' => 2,
                'feeling' => 'happy',
                'willing_next' => 'yes',
                'child_words' => '今天试了浅水区',
            ])
            ->assertCreated()
            ->assertJsonPath('data.project.current_stage', 2);
    }
}
