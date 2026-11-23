<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Item;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class ItemApiTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['mysql'];

    public function test_guest_cannot_access_item_endpoints(): void
    {
        $this->getJson('/api/items')->assertUnauthorized();
        $this->getJson('/api/my/items')->assertUnauthorized();
        $this->postJson('/api/items', [])->assertUnauthorized();
    }

    public function test_suspended_account_cannot_access_item_api(): void
    {
        $user = $this->createUser('suspended');

        $this->withToken($user->createToken('item-api-test')->plainTextToken)
            ->getJson('/api/items')
            ->assertForbidden()
            ->assertJsonPath('message', 'Akun tidak aktif.');
    }

    public function test_deactivated_account_cannot_access_item_api(): void
    {
        $user = $this->createUser('deactivated');

        $this->withToken($user->createToken('item-api-test')->plainTextToken)
            ->getJson('/api/items')
            ->assertForbidden()
            ->assertJsonPath('message', 'Akun tidak aktif.');
    }

    public function test_active_user_can_list_active_items_even_when_category_is_inactive(): void
    {
        $user = $this->createUser();
        $category = $this->createCategory(false);
        $item = $this->createItem($this->createUser(), $category, 'active');

        $response = $this->withToken($user->createToken('item-api-test')->plainTextToken)
            ->getJson('/api/items');

        $response->assertOk()
            ->assertJsonFragment(['id' => $item->id, 'listing_status' => 'active']);
    }

    public function test_public_item_list_excludes_inactive_and_archived_items(): void
    {
        $user = $this->createUser();
        $owner = $this->createUser();
        $category = $this->createCategory();
        $inactive = $this->createItem($owner, $category, 'inactive');
        $archived = $this->createItem($owner, $category, 'archived');

        $response = $this->withToken($user->createToken('item-api-test')->plainTextToken)
            ->getJson('/api/items')
            ->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertNotContains($inactive->id, $ids);
        $this->assertNotContains($archived->id, $ids);
    }

    public function test_owner_list_contains_all_statuses_but_not_other_users_items(): void
    {
        $owner = $this->createUser();
        $other = $this->createUser();
        $category = $this->createCategory();
        $owned = [
            $this->createItem($owner, $category, 'active'),
            $this->createItem($owner, $category, 'inactive'),
            $this->createItem($owner, $category, 'archived'),
        ];
        $otherItem = $this->createItem($other, $category);

        $response = $this->withToken($owner->createToken('item-api-test')->plainTextToken)
            ->getJson('/api/my/items')
            ->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();

        foreach ($owned as $item) {
            $this->assertContains($item->id, $ids);
        }
        $this->assertNotContains($otherItem->id, $ids);
    }

    public function test_active_item_is_visible_to_another_active_user(): void
    {
        $owner = $this->createUser();
        $viewer = $this->createUser();
        $item = $this->createItem($owner, $this->createCategory(), 'active');

        $this->withToken($viewer->createToken('item-api-test')->plainTextToken)
            ->getJson('/api/items/' . $item->id)
            ->assertOk()
            ->assertJsonPath('data.id', $item->id);
    }

    public function test_owner_can_view_own_inactive_item(): void
    {
        $owner = $this->createUser();
        $item = $this->createItem($owner, $this->createCategory(), 'inactive');

        $this->withToken($owner->createToken('item-api-test')->plainTextToken)
            ->getJson('/api/items/' . $item->id)
            ->assertOk()
            ->assertJsonPath('data.listing_status', 'inactive');
    }

    public function test_owner_can_view_own_archived_item(): void
    {
        $owner = $this->createUser();
        $item = $this->createItem($owner, $this->createCategory(), 'archived');

        $this->withToken($owner->createToken('item-api-test')->plainTextToken)
            ->getJson('/api/items/' . $item->id)
            ->assertOk()
            ->assertJsonPath('data.listing_status', 'archived');
    }

    public function test_other_user_cannot_view_inactive_item(): void
    {
        $viewer = $this->createUser();
        $item = $this->createItem($this->createUser(), $this->createCategory(), 'inactive');

        $this->withToken($viewer->createToken('item-api-test')->plainTextToken)
            ->getJson('/api/items/' . $item->id)
            ->assertNotFound();
    }

    public function test_other_user_cannot_view_archived_item(): void
    {
        $viewer = $this->createUser();
        $item = $this->createItem($this->createUser(), $this->createCategory(), 'archived');

        $this->withToken($viewer->createToken('item-api-test')->plainTextToken)
            ->getJson('/api/items/' . $item->id)
            ->assertNotFound();
    }

    public function test_user_can_create_item_with_active_category(): void
    {
        $user = $this->createUser();
        $category = $this->createCategory();
        $payload = $this->validPayload($category);

        $response = $this->withToken($user->createToken('item-api-test')->plainTextToken)
            ->postJson('/api/items', $payload);

        $response->assertCreated()
            ->assertJsonPath('data.category_id', $category->id)
            ->assertJsonPath('data.title', $payload['title'])
            ->assertJsonPath('data.daily_price', '25.50')
            ->assertJsonPath('data.listing_status', 'active');
    }

    public function test_new_item_owner_is_the_authenticated_user(): void
    {
        $user = $this->createUser();
        $payload = $this->validPayload($this->createCategory());

        $response = $this->withToken($user->createToken('item-api-test')->plainTextToken)
            ->postJson('/api/items', $payload)
            ->assertCreated();

        $this->assertDatabaseHas('items', [
            'id' => $response->json('data.id'),
            'owner_id' => $user->id,
        ], 'mysql');
    }

    public function test_admin_cannot_create_an_item(): void
    {
        $admin = $this->createUser('active', 'admin');
        $category = $this->createCategory();
        $itemsBefore = Item::on('mysql')->count();

        $this->withToken($admin->createToken('item-api-test')->plainTextToken)
            ->postJson('/api/items', $this->validPayload($category))
            ->assertForbidden();

        $this->assertSame($itemsBefore, Item::on('mysql')->count());
    }

    public function test_forged_owner_id_does_not_change_item_ownership(): void
    {
        $user = $this->createUser();
        $other = $this->createUser();
        $payload = $this->validPayload($this->createCategory());
        $payload['owner_id'] = $other->id;

        $response = $this->withToken($user->createToken('item-api-test')->plainTextToken)
            ->postJson('/api/items', $payload)
            ->assertCreated();

        $this->assertDatabaseHas('items', [
            'id' => $response->json('data.id'),
            'owner_id' => $user->id,
        ], 'mysql');
        $this->assertDatabaseMissing('items', [
            'id' => $response->json('data.id'),
            'owner_id' => $other->id,
        ], 'mysql');
    }

    public function test_inactive_category_is_rejected_when_creating_item(): void
    {
        $user = $this->createUser();
        $payload = $this->validPayload($this->createCategory(false));

        $this->withToken($user->createToken('item-api-test')->plainTextToken)
            ->postJson('/api/items', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['category_id']);
    }

    public function test_zero_and_negative_prices_are_rejected(): void
    {
        $user = $this->createUser();
        $token = $user->createToken('item-api-test')->plainTextToken;
        $category = $this->createCategory();

        foreach (['0', '-1.00'] as $price) {
            $this->withToken($token)
                ->postJson('/api/items', $this->validPayload($category, [
                    'daily_price' => $price,
                ]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['daily_price']);
        }
    }

    public function test_price_with_more_than_two_decimal_places_is_rejected(): void
    {
        $user = $this->createUser();

        $this->withToken($user->createToken('item-api-test')->plainTextToken)
            ->postJson('/api/items', $this->validPayload($this->createCategory(), [
                'daily_price' => '25.501',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['daily_price']);
    }

    public function test_invalid_item_condition_is_rejected(): void
    {
        $user = $this->createUser();

        $this->withToken($user->createToken('item-api-test')->plainTextToken)
            ->postJson('/api/items', $this->validPayload($this->createCategory(), [
                'item_condition' => 'broken',
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['item_condition']);
    }

    public function test_owner_can_update_item_and_cannot_change_ownership(): void
    {
        $owner = $this->createUser();
        $other = $this->createUser();
        $item = $this->createItem($owner, $this->createCategory());
        $newTitle = 'Updated ' . Str::uuid();

        $this->withToken($owner->createToken('item-api-test')->plainTextToken)
            ->patchJson('/api/items/' . $item->id, [
                'title' => $newTitle,
                'owner_id' => $other->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.title', $newTitle)
            ->assertJsonPath('data.owner_id', $owner->id);

        $this->assertDatabaseHas('items', [
            'id' => $item->id,
            'owner_id' => $owner->id,
            'title' => $newTitle,
        ], 'mysql');
    }

    public function test_other_user_cannot_update_item(): void
    {
        $owner = $this->createUser();
        $other = $this->createUser();
        $item = $this->createItem($owner, $this->createCategory());

        $this->withToken($other->createToken('item-api-test')->plainTextToken)
            ->patchJson('/api/items/' . $item->id, ['title' => 'Unauthorized update'])
            ->assertForbidden();
    }

    public function test_admin_cannot_update_or_delete_an_item_as_owner(): void
    {
        $admin = $this->createUser('active', 'admin');
        $item = $this->createItem($admin, $this->createCategory());
        $originalTitle = $item->title;
        $token = $admin->createToken('item-api-test')->plainTextToken;

        $this->withToken($token)
            ->patchJson('/api/items/'.$item->id, ['title' => 'Admin update'])
            ->assertForbidden();

        $this->withToken($token)
            ->deleteJson('/api/items/'.$item->id)
            ->assertForbidden();

        $this->assertDatabaseHas('items', [
            'id' => $item->id,
            'owner_id' => $admin->id,
            'title' => $originalTitle,
            'deleted_at' => null,
        ], 'mysql');
    }

    public function test_inactive_category_is_rejected_when_changing_category(): void
    {
        $owner = $this->createUser();
        $item = $this->createItem($owner, $this->createCategory());

        $this->withToken($owner->createToken('item-api-test')->plainTextToken)
            ->patchJson('/api/items/' . $item->id, [
                'category_id' => $this->createCategory(false)->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['category_id']);
    }

    public function test_item_can_be_updated_when_its_existing_category_becomes_inactive(): void
    {
        $owner = $this->createUser();
        $category = $this->createCategory();
        $item = $this->createItem($owner, $category);
        $category->is_active = false;
        $category->save();
        $newTitle = 'Updated after deactivation ' . Str::uuid();

        $this->withToken($owner->createToken('item-api-test')->plainTextToken)
            ->patchJson('/api/items/' . $item->id, ['title' => $newTitle])
            ->assertOk()
            ->assertJsonPath('data.title', $newTitle);
    }

    public function test_owner_can_change_listing_status(): void
    {
        $owner = $this->createUser();
        $item = $this->createItem($owner, $this->createCategory());

        $this->withToken($owner->createToken('item-api-test')->plainTextToken)
            ->patchJson('/api/items/' . $item->id, ['listing_status' => 'archived'])
            ->assertOk()
            ->assertJsonPath('data.listing_status', 'archived');
    }

    public function test_owner_can_soft_delete_item(): void
    {
        $owner = $this->createUser();
        $item = $this->createItem($owner, $this->createCategory());

        $this->withToken($owner->createToken('item-api-test')->plainTextToken)
            ->deleteJson('/api/items/' . $item->id)
            ->assertNoContent();

        $this->assertSoftDeleted('items', ['id' => $item->id], 'mysql');
    }

    public function test_soft_deleted_item_is_excluded_from_item_lists(): void
    {
        $owner = $this->createUser();
        $item = $this->createItem($owner, $this->createCategory());
        $item->delete();

        $response = $this->withToken($owner->createToken('item-api-test')->plainTextToken)
            ->getJson('/api/my/items')
            ->assertOk();

        $this->assertNotContains(
            $item->id,
            collect($response->json('data'))->pluck('id')->all()
        );
        $this->getJson('/api/items')
            ->assertOk()
            ->assertJsonMissing(['id' => $item->id]);
    }

    private function createUser(string $accountStatus = 'active', string $role = 'student'): User
    {
        $user = User::factory()->create();
        $user->account_status = $accountStatus;
        $user->role = $role;
        $user->save();

        return $user;
    }

    private function createCategory(bool $isActive = true): Category
    {
        return Category::on('mysql')->create([
            'name' => 'Item API category ' . Str::uuid(),
            'is_active' => $isActive,
        ]);
    }

    private function createItem(
        User $owner,
        Category $category,
        string $listingStatus = 'active'
    ): Item {
        return Item::on('mysql')->create([
            'owner_id' => $owner->id,
            'category_id' => $category->id,
            'title' => 'Test item ' . Str::uuid(),
            'description' => 'Item description for API testing.',
            'daily_price' => '25.50',
            'item_condition' => 'good',
            'condition_notes' => null,
            'listing_status' => $listingStatus,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validPayload(Category $category, array $overrides = []): array
    {
        return array_merge([
            'category_id' => $category->id,
            'title' => 'New item ' . Str::uuid(),
            'description' => 'Description for a newly listed item.',
            'daily_price' => '25.50',
            'item_condition' => 'like_new',
            'condition_notes' => null,
        ], $overrides);
    }
}
