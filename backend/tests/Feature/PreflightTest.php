<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class PreflightTest extends TestCase
{
    public function test_quick_preflight_passes_with_database(): void
    {
        $code = Artisan::call('app:preflight', ['--quick' => true]);

        $this->assertSame(0, $code);
        $this->assertStringContainsString('PASS', Artisan::output());
    }
}
