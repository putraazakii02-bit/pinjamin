<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class AdminMiddlewareTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['mysql'];

    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/_test/admin-middleware', function () {
            return response()->json(['message' => 'Admin access granted.']);
        })->middleware(['auth:sanctum', 'active.account', 'admin']);
    }

    public function test_guest_cannot_access_admin_route(): void
    {
        $this->getJson('/_test/admin-middleware')->assertUnauthorized();
    }

    public function test_active_student_is_forbidden_from_admin_route(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('admin-middleware-test')->plainTextToken;

        $this->withToken($token)
            ->getJson('/_test/admin-middleware')
            ->assertForbidden()
            ->assertJsonPath('message', 'Akses hanya untuk Admin.');
    }

    public function test_active_admin_can_access_admin_route(): void
    {
        $user = $this->createAdmin();
        $token = $user->createToken('admin-middleware-test')->plainTextToken;

        $this->withToken($token)
            ->getJson('/_test/admin-middleware')
            ->assertOk()
            ->assertJsonPath('message', 'Admin access granted.');
    }

    public function test_suspended_admin_is_forbidden_from_admin_route(): void
    {
        $user = $this->createAdmin('suspended');
        $token = $user->createToken('admin-middleware-test')->plainTextToken;

        $this->withToken($token)
            ->getJson('/_test/admin-middleware')
            ->assertForbidden()
            ->assertJsonPath('message', 'Akun tidak aktif.');
    }

    public function test_deactivated_admin_is_forbidden_from_admin_route(): void
    {
        $user = $this->createAdmin('deactivated');
        $token = $user->createToken('admin-middleware-test')->plainTextToken;

        $this->withToken($token)
            ->getJson('/_test/admin-middleware')
            ->assertForbidden()
            ->assertJsonPath('message', 'Akun tidak aktif.');
    }

    private function createAdmin(string $accountStatus = 'active'): User
    {
        $user = User::factory()->create();
        $user->role = 'admin';
        $user->account_status = $accountStatus;
        $user->save();

        return $user;
    }
}
