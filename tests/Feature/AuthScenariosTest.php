<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\InteractsWithTakafol;
use Tests\TestCase;

class AuthScenariosTest extends TestCase
{
    use InteractsWithTakafol, RefreshDatabase;

    public function test_auth01_camp_admin_login_success_and_wrong_password(): void
    {
        $camp = $this->makeCamp();
        $admin = $this->makeCampAdmin($camp);

        $this->postJson('/api/admin/login', [
            'username' => $admin->username,
            'password' => 'wrong',
        ], $this->campHeaders($camp))->assertStatus(422);

        $this->loginAdmin($admin, $camp);
    }

    public function test_auth02_super_admin_login_without_camp_header(): void
    {
        $super = $this->makeGlobalSuper();
        $this->loginSuper($super);
    }

    public function test_auth03_family_login_success_and_rejects_admin_on_family_endpoint(): void
    {
        $camp = $this->makeCamp();
        $admin = $this->makeCampAdmin($camp);
        $pack = $this->makeFamilyWithHead($camp);

        $this->loginFamily($pack['user'], $pack['serial'], $camp);

        $this->postJson('/api/login', [
            'national_id' => $admin->national_id,
            'serial' => '000',
        ], $this->campHeaders($camp))->assertStatus(422);
    }

    public function test_auth04_family_login_requires_three_digit_serial(): void
    {
        $camp = $this->makeCamp();
        $pack = $this->makeFamilyWithHead($camp);

        $this->postJson('/api/login', [
            'national_id' => $pack['user']->national_id,
            'serial' => '12',
        ], $this->campHeaders($camp))->assertStatus(422);
    }

    public function test_auth05_me_and_logout(): void
    {
        $camp = $this->makeCamp();
        $admin = $this->makeCampAdmin($camp);
        $token = $this->loginAdmin($admin, $camp);

        $this->getJson('/api/me', $this->campHeaders($camp, $token))
            ->assertOk()
            ->assertJsonPath('role', User::ROLE_ADMIN)
            ->assertJsonPath('username', $admin->username);

        $this->postJson('/api/logout', [], $this->campHeaders($camp, $token))
            ->assertOk();

        $this->getJson('/api/me', $this->campHeaders($camp, $token))
            ->assertStatus(401);
    }

    public function test_auth06_family_cannot_use_admin_routes(): void
    {
        $camp = $this->makeCamp();
        $this->makeCampAdmin($camp);
        $pack = $this->makeFamilyWithHead($camp);
        $token = $this->loginFamily($pack['user'], $pack['serial'], $camp);

        $this->getJson('/api/admin/families', $this->campHeaders($camp, $token))
            ->assertForbidden();
    }

    public function test_auth07_admin_cannot_use_family_dashboard(): void
    {
        $camp = $this->makeCamp();
        $admin = $this->makeCampAdmin($camp);
        $token = $this->loginAdmin($admin, $camp);

        $this->getJson('/api/family/dashboard', $this->campHeaders($camp, $token))
            ->assertForbidden();
    }

    public function test_auth08_unauthenticated_requests_return_401_json(): void
    {
        $camp = $this->makeCamp();

        $this->getJson('/api/me', $this->campHeaders($camp))
            ->assertStatus(401)
            ->assertJsonPath('message', 'Unauthenticated.');
    }
}
