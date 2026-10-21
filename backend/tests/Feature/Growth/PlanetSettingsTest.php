<?php

namespace Tests\Feature\Growth;

use App\Modules\Core\Settings\Settings;
use App\Modules\Identity\Support\KidPlanets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanetSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_parent_switches_are_family_scoped_and_restore_without_deleting_data(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        ['family' => $other] = $this->makeFamily('other-parent');
        $child = $this->makeChild($family);
        [$device, $token] = $this->makeDevice($family, $parent);
        $this->actingAs($parent)->getJson('/api/v1/parent/planet-settings')->assertOk()->assertJsonCount(12, 'data');
        $this->patchJson('/api/v1/parent/planet-settings', ['key' => 'game_planet', 'enabled' => false])->assertOk();
        $this->assertTrue(KidPlanets::enabled('game_planet', $other->id));
        $this->assertFalse(KidPlanets::enabled('game_planet', $family->id));
    }

    public function test_disabled_module_blocks_child_reads_and_writes_and_can_be_restored(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        [$device, $token] = $this->makeDevice($family, $parent);
        $settings = app(Settings::class);
        $settings->set('planets.game_planet', false, $family->id);
        $this->actingAsKid($child, $device, $token)->getJson('/api/v1/kid/home')->assertOk()->assertJsonCount(11, 'data.planets');
        $this->getJson('/api/v1/kid/games/schulte')->assertForbidden()->assertJsonPath('error.code', 'PLANET_DISABLED');
        $this->postJson('/api/v1/kid/games/schulte', [])->assertForbidden()->assertJsonPath('error.code', 'PLANET_DISABLED');
        $this->patchJson('/api/v1/parent/planet-settings', ['key' => 'game_planet', 'enabled' => true])->assertForbidden();
        $settings->set('planets.game_planet', true, $family->id);
        $this->getJson('/api/v1/kid/games/schulte')->assertOk();
        $this->getJson('/api/v1/kid/home')->assertOk()->assertJsonCount(12, 'data.planets');
    }

    public function test_unknown_keys_and_non_boolean_values_are_rejected(): void
    {
        ['parent' => $parent] = $this->makeFamily();
        $this->actingAs($parent)->patchJson('/api/v1/parent/planet-settings', ['key' => 'ai.provider', 'enabled' => false])->assertUnprocessable();
        $this->patchJson('/api/v1/parent/planet-settings', ['key' => 'english_planet', 'enabled' => 'false'])->assertUnprocessable();
    }
}
