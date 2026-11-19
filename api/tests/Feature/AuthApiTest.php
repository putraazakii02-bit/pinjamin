<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['mysql'];

    public function test_student_can_register_successfully(): void
    {
        $payload = $this->validRegistrationPayload();

        $response = $this->postJson('/api/auth/register', $payload);

        $response->assertCreated()
            ->assertJsonStructure([
                'message',
                'token',
                'token_type',
                'user' => ['id', 'name', 'email'],
                'student_profile' => [
                    'user_id',
                    'nim',
                    'university_name',
                    'study_program',
                    'whatsapp_number',
                    'profile_photo_path',
                ],
            ]);
        $this->assertNotEmpty($response->json('token'));
        $this->assertSame('Bearer', $response->json('token_type'));

        $this->assertDatabaseHas('users', [
            'email' => $payload['email'],
            'role' => 'student',
            'account_status' => 'active',
        ], 'mysql');

        $user = User::on('mysql')->where('email', $payload['email'])->firstOrFail();

        $this->assertNotSame($payload['password'], $user->password);
        $this->assertTrue(Hash::check($payload['password'], $user->password));

        $this->assertDatabaseHas('student_profiles', [
            'user_id' => $user->id,
            'nim' => $payload['nim'],
            'university_name' => $payload['university_name'],
            'study_program' => $payload['study_program'],
            'whatsapp_number' => $payload['whatsapp_number'],
            'verification_status' => 'unverified',
        ], 'mysql');
    }

    public function test_registration_fails_when_email_and_password_confirmation_are_invalid(): void
    {
        $payload = $this->validRegistrationPayload([
            'email' => 'not-an-email',
            'password_confirmation' => 'DifferentPass123!',
        ]);

        $response = $this->postJson('/api/auth/register', $payload);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'password']);

        $this->assertDatabaseMissing('users', [
            'email' => $payload['email'],
        ], 'mysql');
    }

    public function test_user_can_log_in_with_valid_credentials(): void
    {
        $user = User::factory()->create();

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'message',
                'token',
                'token_type',
                'user' => ['id', 'name', 'email'],
            ]);
        $this->assertNotEmpty($response->json('token'));
        $this->assertSame('Bearer', $response->json('token_type'));
    }

    public function test_login_fails_with_an_incorrect_password(): void
    {
        $user = User::factory()->create();

        $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'incorrect-password',
        ])->assertUnauthorized();
    }

    public function test_non_active_user_cannot_log_in(): void
    {
        $user = User::factory()->create();
        $user->account_status = 'suspended';
        $user->save();

        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertForbidden();
        $this->assertArrayNotHasKey('token', $response->json());
    }

    public function test_logout_revokes_the_current_token(): void
    {
        $user = User::factory()->create();
        $newToken = $user->createToken('auth-api-test');
        $tokenId = $newToken->accessToken->id;

        $this->withToken($newToken->plainTextToken)
            ->postJson('/api/auth/logout')
            ->assertOk();

        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => $tokenId,
        ], 'mysql');

        $this->app['auth']->forgetGuards();

        $this->withToken($newToken->plainTextToken)
            ->getJson('/api/user')
            ->assertUnauthorized();
    }

    public function test_authenticated_user_can_get_their_user_details(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('auth-api-test')->plainTextToken;

        $response = $this->withToken($token)->getJson('/api/user');

        $response->assertOk()
            ->assertJsonPath('id', $user->id)
            ->assertJsonPath('name', $user->name)
            ->assertJsonPath('email', $user->email);
        $this->assertArrayNotHasKey('password', $response->json());
    }

    public function test_user_details_require_authentication(): void
    {
        $this->getJson('/api/user')->assertUnauthorized();
    }

    public function test_registration_rejects_a_duplicate_nim_at_the_same_university(): void
    {
        $existingUser = User::factory()->create();
        $nim = $this->uniqueNim();
        $universityName = 'Universitas Uji';

        $existingUser->studentProfile()->create([
            'nim' => $nim,
            'university_name' => $universityName,
            'study_program' => 'Teknik Informatika',
            'whatsapp_number' => '081234567890',
        ]);

        $payload = $this->validRegistrationPayload([
            'nim' => $nim,
            'university_name' => $universityName,
        ]);

        $response = $this->postJson('/api/auth/register', $payload);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['nim']);
        $this->assertDatabaseMissing('users', [
            'email' => $payload['email'],
        ], 'mysql');
    }

    /**
     * @return array<string, string>
     */
    private function validRegistrationPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Test Student',
            'email' => 'student-' . Str::uuid() . '@example.test',
            'password' => 'StrongPass123!',
            'password_confirmation' => 'StrongPass123!',
            'nim' => $this->uniqueNim(),
            'university_name' => 'Universitas Pinjamin',
            'study_program' => 'Teknik Informatika',
            'whatsapp_number' => '081234567890',
        ], $overrides);
    }

    private function uniqueNim(): string
    {
        return 'NIM' . strtoupper(substr(str_replace('-', '', (string) Str::uuid()), 0, 20));
    }
}
