<?php

namespace Tests\Feature\Growth;

use App\Modules\Ielts\Models\IeltsLearningState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IeltsLearningStateTest extends TestCase
{
    use RefreshDatabase;

    public function test_kid_can_persist_and_restore_learning_state(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        [$device, $token] = $this->makeDevice($family, $parent);

        $settings = [
            'onboarded' => true,
            'startLevel' => 2,
            'dailyNew' => 15,
            'accent' => 'uk',
            'theme' => 'dark',
        ];
        $progress = [
            'version' => 2,
            'datasetId' => 'word-atlas-open',
            'cards' => [
                'abandon' => [
                    'due' => 1, 's' => 2, 'd' => 5, 'el' => 0, 'sd' => 1,
                    'ls' => 0, 'reps' => 1, 'lapses' => 0, 'st' => 2, 'seen' => 1,
                ],
            ],
            'bookmarks' => [],
            'days' => [],
            'typing' => [],
            'session' => null,
            'mistakes' => [],
        ];

        $this->actingAsKid($child, $device, $token)
            ->getJson('/api/v1/kid/ielts/state')
            ->assertOk()
            ->assertJsonPath('data', null);

        $this->putJson('/api/v1/kid/ielts/state', [
            'settings' => $settings,
            'progress' => $progress,
        ])
            ->assertOk()
            ->assertJsonPath('data.settings.onboarded', true)
            ->assertJsonPath('data.settings.startLevel', 2)
            ->assertJsonPath('data.progress.cards.abandon.reps', 1);

        $this->assertDatabaseHas('ielts_learning_states', ['child_id' => $child->id]);

        $this->getJson('/api/v1/kid/ielts/state')
            ->assertOk()
            ->assertJsonPath('data.settings.dailyNew', 15)
            ->assertJsonPath('data.progress.datasetId', 'word-atlas-open');

        // Upsert replaces the previous blob.
        $this->putJson('/api/v1/kid/ielts/state', [
            'settings' => array_merge($settings, ['dailyNew' => 5]),
            'progress' => array_merge($progress, ['bookmarks' => ['abandon']]),
        ])
            ->assertOk()
            ->assertJsonPath('data.settings.dailyNew', 5)
            ->assertJsonPath('data.progress.bookmarks.0', 'abandon');

        $this->assertSame(1, IeltsLearningState::query()->where('child_id', $child->id)->count());
    }

    public function test_parent_cannot_read_kid_learning_state(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $this->makeDevice($family, $parent);

        $this->actingAsParent($parent)
            ->getJson('/api/v1/kid/ielts/state')
            ->assertForbidden();
    }
}
