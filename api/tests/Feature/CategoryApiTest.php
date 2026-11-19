<?php

namespace Tests\Feature;

use App\Models\Category;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class CategoryApiTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['mysql'];

    public function test_category_list_contains_only_active_categories(): void
    {
        $suffix = Str::uuid()->toString();
        $active = $this->createCategory('Active ' . $suffix, true);
        $inactive = $this->createCategory('Inactive ' . $suffix, false);

        $response = $this->getJson('/api/categories');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    ['id', 'name', 'description', 'is_active'],
                ],
            ]);

        $categoryIds = collect($response->json('data'))->pluck('id')->all();

        $this->assertContains($active->id, $categoryIds);
        $this->assertNotContains($inactive->id, $categoryIds);
    }

    public function test_category_list_is_ordered_by_name(): void
    {
        $suffix = Str::uuid()->toString();
        $first = $this->createCategory('AAA ' . $suffix, true);
        $last = $this->createCategory('ZZZ ' . $suffix, true);

        $response = $this->getJson('/api/categories')->assertOk();
        $categoryNames = collect($response->json('data'))->pluck('name')->all();
        $firstPosition = array_search($first->name, $categoryNames, true);
        $lastPosition = array_search($last->name, $categoryNames, true);

        $this->assertNotFalse($firstPosition);
        $this->assertNotFalse($lastPosition);
        $this->assertLessThan($lastPosition, $firstPosition);
    }

    public function test_active_category_details_are_returned(): void
    {
        $category = $this->createCategory(
            'Active detail ' . Str::uuid(),
            true,
            'An active category.'
        );

        $this->getJson('/api/categories/' . $category->id)
            ->assertOk()
            ->assertJsonPath('data.id', $category->id)
            ->assertJsonPath('data.name', $category->name)
            ->assertJsonPath('data.description', $category->description)
            ->assertJsonPath('data.is_active', true);
    }

    public function test_inactive_category_details_are_still_returned(): void
    {
        $category = $this->createCategory(
            'Inactive detail ' . Str::uuid(),
            false,
            'An inactive category.'
        );

        $this->getJson('/api/categories/' . $category->id)
            ->assertOk()
            ->assertJsonPath('data.id', $category->id)
            ->assertJsonPath('data.name', $category->name)
            ->assertJsonPath('data.description', $category->description)
            ->assertJsonPath('data.is_active', false);
    }

    public function test_missing_category_details_return_not_found(): void
    {
        $maxId = (int) (Category::on('mysql')->max('id') ?? 0);
        $missingId = $maxId + 1;

        $this->getJson('/api/categories/' . $missingId)->assertNotFound();
    }

    private function createCategory(
        string $name,
        bool $isActive,
        ?string $description = null
    ): Category {
        return Category::on('mysql')->create([
            'name' => $name,
            'description' => $description,
            'is_active' => $isActive,
        ]);
    }
}
