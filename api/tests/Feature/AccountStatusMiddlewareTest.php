<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class AccountStatusMiddlewareTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['mysql'];

    public function test_active_account_can_access_the_user_endpoint(): void
    {
        $credentials = $this->createUserWithToken();

        $this->withToken($credentials['plainTextToken'])
            ->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('id', $credentials['user']->id)
            ->assertJsonPath('email', $credentials['user']->email);
    }

    public function test_suspended_account_cannot_access_the_user_endpoint(): void
    {
        $credentials = $this->createUserWithToken();
        $this->setAccountStatus($credentials['user'], 'suspended');

        $this->withToken($credentials['plainTextToken'])
            ->getJson('/api/user')
            ->assertForbidden()
            ->assertJsonPath('message', 'Akun tidak aktif.');
    }

    public function test_deactivated_account_cannot_access_the_user_endpoint(): void
    {
        $credentials = $this->createUserWithToken();
        $this->setAccountStatus($credentials['user'], 'deactivated');

        $this->withToken($credentials['plainTextToken'])
            ->getJson('/api/user')
            ->assertForbidden()
            ->assertJsonPath('message', 'Akun tidak aktif.');
    }

    public function test_suspended_account_can_log_out_and_revoke_its_token(): void
    {
        $credentials = $this->createUserWithToken();
        $this->setAccountStatus($credentials['user'], 'suspended');

        $this->withToken($credentials['plainTextToken'])
            ->postJson('/api/auth/logout')
            ->assertOk();

        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => $credentials['tokenId'],
        ], 'mysql');
    }

    public function test_deactivated_account_can_log_out_and_revoke_its_token(): void
    {
        $credentials = $this->createUserWithToken();
        $this->setAccountStatus($credentials['user'], 'deactivated');

        $this->withToken($credentials['plainTextToken'])
            ->postJson('/api/auth/logout')
            ->assertOk();

        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => $credentials['tokenId'],
        ], 'mysql');
    }

    public function test_user_endpoint_requires_a_token(): void
    {
        $this->getJson('/api/user')->assertUnauthorized();
    }

    /**
     * @return array{user: User, plainTextToken: string, tokenId: int}
     */
    private function createUserWithToken(): array
    {
        $user = User::factory()->create();
        $newToken = $user->createToken('account-status-test');

        return [
            'user' => $user,
            'plainTextToken' => $newToken->plainTextToken,
            'tokenId' => $newToken->accessToken->id,
        ];
    }

    private function setAccountStatus(User $user, string $status): void
    {
        $user->account_status = $status;
        $user->save();
    }
}
