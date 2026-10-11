<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthTest extends TestCase
{
    public function test_health_reports_database_and_utc(): void
    {
        $this->getJson('/api/health')
            ->assertOk()
            ->assertJson(['status' => 'ok', 'database' => 'ok', 'app_timezone' => 'UTC'])
            ->assertHeader('X-Request-Id');
    }

    public function test_database_session_is_utc_and_utf8mb4(): void
    {
        $row = \DB::selectOne('SELECT @@session.time_zone AS tz, @@character_set_database AS cs');

        $this->assertSame('+00:00', $row->tz);
        $this->assertSame('utf8mb4', $row->cs);
    }

    public function test_unknown_api_route_returns_json_error(): void
    {
        $this->getJson('/api/v1/nope')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'NOT_FOUND');
    }
}
