<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminCategoryApiTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['mysql'];

    public function test_guest_cannot_access_admin_category_endpoints(): void
    {
        $this->getJson('/api/admin/categories')->assertUnauthorized();
    }

    public function test_active_student_cannot_manage_categories(): void
    {
        $user = User::factory()->create();

        $this->withToken($user->createToken('admin-category-test')->plainTextToken)
            ->getJson('/api/admin/categories')
            ->assertForbidden()
            ->assertJsonPath('message', 'Akses hanya untuk Admin.');
    }

    public function test_suspended_admin_cannot_manage_categories(): void
    {
        $user = $this->createAdmin('suspended');

        $this->withToken($user->createToken('admin-category-test')->plainTextToken)
            ->getJson('/api/admin/categories')
            ->assertForbidden()
            ->assertJsonPath('message', 'Akun tidak aktif.');
    }

    public function test_deactivated_admin_cannot_manage_categories(): void
    {
        $user = $this->createAdmin('deactivated');

        $this->withToken($user->createToken('admin-category-test')->plainTextToken)
            ->getJson('/api/admin/categories')
            ->assertForbidden()
            ->assertJsonPath('message', 'Akun tidak aktif.');
    }

    public function test_admin_can_list_active_and_inactive_categories(): void
    {
        $admin = $this->createAdmin();
        $active = $this->createCategory('Active ' . Str::uuid(), true);
        $inactive = $this->createCategory('Inactive ' . Str::uuid(), false);

        $response = $this->withToken($admin->createToken('admin-category-test')->plainTextToken)
            ->getJson('/api/admin/categories');

        $response->assertOk()
            ->assertJsonFragment(['id' => $active->id, 'is_active' => true])
            ->assertJsonFragment(['id' => $inactive->id, 'is_active' => false]);
    }

    public function test_admin_category_list_is_sorted_by_name(): void
    {
        $admin = $this->createAdmin();
        $suffix = Str::uuid();
        $first = $this->createCategory('AAA ' . $suffix, true);
        $last = $this->createCategory('ZZZ ' . $suffix, false);

        $response = $this->withToken($admin->createToken('admin-category-test')->plainTextToken)
            ->getJson('/api/admin/categories')
            ->assertOk();
        $names = collect($response->json('data'))->pluck('name')->all();
        $firstPosition = array_search($first->name, $names, true);
        $lastPosition = array_search($last->name, $names, true);

        $this->assertNotFalse($firstPosition);
        $this->assertNotFalse($lastPosition);
        $this->assertLessThan($lastPosition, $firstPosition);
    }

    public function test_admin_can_create_an_active_category(): void
    {
        $admin = $this->createAdmin();
        $name = 'New category ' . Str::uuid();

        $response = $this->withToken($admin->createToken('admin-category-test')->plainTextToken)
            ->postJson('/api/admin/categories', [
                'name' => $name,
                'description' => 'Created for the feature test.',
                'is_active' => false,
                'unexpected' => 'ignored',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', $name)
            ->assertJsonPath('data.description', 'Created for the feature test.')
            ->assertJsonPath('data.is_active', true);
        $this->assertDatabaseHas('categories', [
            'name' => $name,
            'is_active' => true,
        ], 'mysql');
    }

    public function test_category_name_is_required(): void
    {
        $admin = $this->createAdmin();

        $this->withToken($admin->createToken('admin-category-test')->plainTextToken)
            ->postJson('/api/admin/categories', ['description' => 'Missing name'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    }

    public function test_category_name_cannot_exceed_80_characters(): void
    {
        $admin = $this->createAdmin();

        $this->withToken($admin->createToken('admin-category-test')->plainTextToken)
            ->postJson('/api/admin/categories', ['name' => str_repeat('a', 81)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    }

    public function test_duplicate_category_name_is_rejected(): void
    {
        $admin = $this->createAdmin();
        $category = $this->createCategory('Duplicate ' . Str::uuid());

        $this->withToken($admin->createToken('admin-category-test')->plainTextToken)
            ->postJson('/api/admin/categories', ['name' => $category->name])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    }

    public function test_admin_can_update_category_name_and_description(): void
    {
        $admin = $this->createAdmin();
        $category = $this->createCategory('Before ' . Str::uuid(), true, 'Old description');
        $newName = 'After ' . Str::uuid();

        $this->withToken($admin->createToken('admin-category-test')->plainTextToken)
            ->patchJson('/api/admin/categories/' . $category->id, [
                'name' => $newName,
                'description' => 'Updated description',
                'is_active' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.name', $newName)
            ->assertJsonPath('data.description', 'Updated description')
            ->assertJsonPath('data.is_active', true);

        $this->assertDatabaseHas('categories', [
            'id' => $category->id,
            'name' => $newName,
            'description' => 'Updated description',
            'is_active' => true,
        ], 'mysql');
    }

    public function test_admin_can_update_category_without_changing_its_name(): void
    {
        $admin = $this->createAdmin();
        $category = $this->createCategory('Unchanged ' . Str::uuid(), true, 'Old description');

        $this->withToken($admin->createToken('admin-category-test')->plainTextToken)
            ->patchJson('/api/admin/categories/' . $category->id, [
                'name' => $category->name,
                'description' => 'New description',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', $category->name)
            ->assertJsonPath('data.description', 'New description');
    }

    public function test_admin_can_deactivate_category_without_deleting_it(): void
    {
        $admin = $this->createAdmin();
        $category = $this->createCategory('Deactivate ' . Str::uuid(), true);

        $this->withToken($admin->createToken('admin-category-test')->plainTextToken)
            ->patchJson('/api/admin/categories/' . $category->id . '/status', ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertDatabaseHas('categories', [
            'id' => $category->id,
            'is_active' => false,
        ], 'mysql');
    }

    public function test_admin_can_reactivate_category_without_deleting_it(): void
    {
        $admin = $this->createAdmin();
        $category = $this->createCategory('Reactivate ' . Str::uuid(), false);

        $this->withToken($admin->createToken('admin-category-test')->plainTextToken)
            ->patchJson('/api/admin/categories/' . $category->id . '/status', ['is_active' => true])
            ->assertOk()
            ->assertJsonPath('data.is_active', true);

        $this->assertDatabaseHas('categories', [
            'id' => $category->id,
            'is_active' => true,
        ], 'mysql');
    }

    private function createAdmin(string $accountStatus = 'active'): User
    {
        $user = User::factory()->create();
        $user->role = 'admin';
        $user->account_status = $accountStatus;
        $user->save();

        return $user;
    }

    private function createCategory(
        string $name,
        bool $isActive = true,
        ?string $description = null
    ): Category {
        return Category::on('mysql')->create([
            'name' => $name,
            'description' => $description,
            'is_active' => $isActive,
        ]);
    }
}
