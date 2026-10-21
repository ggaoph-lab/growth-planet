<?php

namespace Tests\Unit;

use App\Modules\English\Services\EnglishSkillPilot;
use Tests\TestCase;

class EnglishSkillPilotTest extends TestCase
{
    public function test_focus_advances_only_after_enough_anchor_words_are_known(): void
    {
        $pilot = app(EnglishSkillPilot::class);

        $this->assertSame('mt_oL9s_bufDp', $pilot->focus([])['topic_id']);
        $this->assertSame('mt_oL9s_bufDp', $pilot->focus(['bird', 'fish'])['topic_id']);
        $this->assertSame('mt_6-MYToNZ39', $pilot->focus(['bird', 'fish', 'tree'])['topic_id']);
    }
}
