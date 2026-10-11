<?php

namespace Tests\Feature\Growth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CrossFamilyIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_parent_cannot_see_other_family_task(): void
    {
        ['family' => $familyA, 'parent' => $parentA] = $this->makeFamily('mama_a');
        ['family' => $familyB, 'parent' => $parentB] = $this->makeFamily('mama_b');

        $this->seedTask($familyA, $parentA, ['title' => 'A 的任务']);

        $this->actingAsParent($parentB)->getJson('/api/v1/parent/tasks')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }
}
