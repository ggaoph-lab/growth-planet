<?php

namespace Tests\Feature\Identity;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Walks the real route table so every parent endpoint added in later phases is covered automatically.
 */
class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return list<array{0: string, 1: string}>
     */
    private function routesWithPrefix(string $prefix): array
    {
        $routes = [];
        foreach (RouteFacade::getRoutes()->getRoutes() as $route) {
            /** @var Route $route */
            if (! Str::startsWith($route->uri(), $prefix)) {
                continue;
            }
            $uri = '/'.preg_replace('/\{[^}]+\}/', '1', $route->uri());
            foreach ($route->methods() as $method) {
                if (! in_array($method, ['HEAD', 'OPTIONS'], true)) {
                    $routes[] = [$method, $uri];
                }
            }
        }

        return $routes;
    }

    public function test_route_table_contains_parent_and_kid_routes(): void
    {
        $this->assertGreaterThanOrEqual(10, count($this->routesWithPrefix('api/v1/parent')));
        $this->assertGreaterThanOrEqual(2, count($this->routesWithPrefix('api/v1/kid')));
    }

    public function test_child_session_gets_403_on_every_parent_endpoint(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        [$device, $token] = $this->makeDevice($family, $parent);

        foreach ($this->routesWithPrefix('api/v1/parent') as [$method, $uri]) {
            $this->actingAsKid($child, $device, $token)
                ->json($method, $uri, ['name' => 'x', 'pin' => '9999', 'timezone' => 'UTC'])
                ->assertStatus(403);
        }
    }

    public function test_child_cannot_confirm_parent_password(): void
    {
        ['family' => $family, 'parent' => $parent] = $this->makeFamily();
        $child = $this->makeChild($family);
        [$device, $token] = $this->makeDevice($family, $parent);

        $this->actingAsKid($child, $device, $token)
            ->postJson('/api/v1/auth/confirm-password', ['password' => self::PARENT_PASSWORD])
            ->assertForbidden();
    }

    public function test_guest_gets_401_on_every_parent_and_kid_endpoint(): void
    {
        foreach ([...$this->routesWithPrefix('api/v1/parent'), ...$this->routesWithPrefix('api/v1/kid')] as [$method, $uri]) {
            $this->json($method, $uri)->assertStatus(401);
        }
    }

    public function test_parent_gets_403_on_kid_endpoints(): void
    {
        ['parent' => $parent] = $this->makeFamily();

        foreach ($this->routesWithPrefix('api/v1/kid') as [$method, $uri]) {
            $this->actingAsParent($parent)->json($method, $uri)->assertStatus(403);
        }
    }

    public function test_parent_cannot_see_or_modify_another_familys_child(): void
    {
        ['parent' => $parentA] = $this->makeFamily('mama_a');
        ['family' => $familyB] = $this->makeFamily('mama_b');
        $childB = $this->makeChild($familyB);

        $this->actingAsParent($parentA, sudo: true);

        $this->getJson("/api/v1/parent/children/{$childB->id}")->assertNotFound();
        $this->patchJson("/api/v1/parent/children/{$childB->id}", ['nickname' => 'hacked'])->assertNotFound();
        $this->putJson("/api/v1/parent/children/{$childB->id}/pin", ['pin' => '0000'])->assertNotFound();
        $this->putJson("/api/v1/parent/children/{$childB->id}/birth-date", ['birth_date' => '2010-01-01'])->assertNotFound();
        $this->getJson('/api/v1/parent/children')->assertOk()->assertJsonCount(0, 'data');

        $this->assertSame('小星星', $childB->refresh()->nickname);
    }

    public function test_parent_cannot_revoke_another_familys_device(): void
    {
        ['parent' => $parentA] = $this->makeFamily('mama_a');
        ['family' => $familyB, 'parent' => $parentB] = $this->makeFamily('mama_b');
        [$deviceB] = $this->makeDevice($familyB, $parentB);

        $this->actingAsParent($parentA, sudo: true)->deleteJson("/api/v1/parent/devices/{$deviceB->id}")->assertNotFound();
        $this->assertNull($deviceB->refresh()->revoked_at);
    }

    public function test_audit_log_is_scoped_to_family(): void
    {
        ['parent' => $parentA] = $this->makeFamily('mama_a');
        ['family' => $familyB] = $this->makeFamily('mama_b');
        $this->makeChild($familyB);

        $actions = collect($this->actingAsParent($parentA)->getJson('/api/v1/parent/audit-logs')->assertOk()->json('data'))
            ->pluck('action');

        $this->assertNotContains('child.created', $actions);
    }
}
