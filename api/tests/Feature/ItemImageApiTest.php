<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Item;
use App\Models\ItemImage;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ItemImageApiTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['mysql'];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_guest_cannot_upload_item_images(): void
    {
        $item = $this->createItem($this->createUser());

        $this->post('/api/items/'.$item->id.'/images', [
            'images' => [$this->fakeImage('guest.jpg')],
        ], ['Accept' => 'application/json'])->assertUnauthorized();
    }

    public function test_suspended_account_cannot_upload_item_images(): void
    {
        $user = $this->createUser('suspended');
        $item = $this->createItem($user);

        $this->actingAs($user, 'sanctum')
            ->post('/api/items/'.$item->id.'/images', [
                'images' => [$this->fakeImage('suspended.jpg')],
            ])
            ->assertForbidden();
    }

    public function test_deactivated_account_cannot_upload_item_images(): void
    {
        $user = $this->createUser('deactivated');
        $item = $this->createItem($user);

        $this->actingAs($user, 'sanctum')
            ->post('/api/items/'.$item->id.'/images', [
                'images' => [$this->fakeImage('deactivated.jpg')],
            ])
            ->assertForbidden();
    }

    public function test_non_owner_cannot_upload_item_images(): void
    {
        $owner = $this->createUser();
        $other = $this->createUser();
        $item = $this->createItem($owner);

        $this->actingAs($other, 'sanctum')
            ->post('/api/items/'.$item->id.'/images', [
                'images' => [$this->fakeImage('other.jpg')],
            ])
            ->assertForbidden();

        $this->assertSame(0, $item->images()->count());
    }

    public function test_owner_can_upload_jpg_image(): void
    {
        $owner = $this->createUser();
        $item = $this->createItem($owner);

        $response = $this->uploadImages($owner, $item, [$this->fakeImage('photo.jpg')]);

        $response->assertCreated()->assertJsonCount(1, 'data');
        $this->assertSame(1, $item->images()->count());
    }

    public function test_jpeg_png_and_webp_images_are_accepted(): void
    {
        $owner = $this->createUser();
        $item = $this->createItem($owner);

        $this->uploadImages($owner, $item, [
            $this->fakeImage('photo.jpeg'),
            $this->fakeImage('photo.png'),
            $this->fakeImage('photo.webp'),
        ])->assertCreated()->assertJsonCount(3, 'data');
    }

    public function test_disallowed_image_format_is_rejected(): void
    {
        $owner = $this->createUser();
        $item = $this->createItem($owner);

        $this->uploadImages($owner, $item, [
            UploadedFile::fake()->createWithContent('photo.gif', 'not an allowed image'),
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['images.0']);

        $this->assertSame(0, $item->images()->count());
    }

    public function test_image_larger_than_five_megabytes_is_rejected(): void
    {
        $owner = $this->createUser();
        $item = $this->createItem($owner);

        $this->uploadImages($owner, $item, [
            $this->fakeImage('large.jpg')->size(5121),
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['images.0']);
    }

    public function test_upload_that_would_exceed_five_total_images_is_rejected(): void
    {
        $owner = $this->createUser();
        $item = $this->createItem($owner);

        foreach (range(0, 3) as $order) {
            $this->createImage($item, 'items/'.$item->id.'/existing-'.$order.'.jpg', $order, $order === 0);
        }

        $this->uploadImages($owner, $item, [
            $this->fakeImage('new-one.jpg'),
            $this->fakeImage('new-two.jpg'),
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['images']);

        $this->assertSame(4, $item->images()->count());
        Storage::disk('local')->assertDirectoryEmpty('items/'.$item->id);
    }

    public function test_uploading_exactly_five_images_succeeds(): void
    {
        $owner = $this->createUser();
        $item = $this->createItem($owner);
        $files = array_map(fn (int $number) => $this->fakeImage('photo-'.$number.'.jpg'), range(1, 5));

        $this->uploadImages($owner, $item, $files)
            ->assertCreated()
            ->assertJsonCount(5, 'data');

        $this->assertSame(5, $item->images()->count());
        $this->assertSame(1, $item->images()->where('is_primary', true)->count());
    }

    public function test_first_uploaded_image_becomes_primary(): void
    {
        $owner = $this->createUser();
        $item = $this->createItem($owner);

        $this->uploadImages($owner, $item, [$this->fakeImage('first.jpg')])->assertCreated();

        $this->assertTrue($item->images()->firstOrFail()->is_primary);
    }

    public function test_additional_upload_does_not_replace_primary_image(): void
    {
        $owner = $this->createUser();
        $item = $this->createItem($owner);
        $this->uploadImages($owner, $item, [$this->fakeImage('first.jpg')])->assertCreated();
        $first = $item->images()->firstOrFail();

        $this->uploadImages($owner, $item, [$this->fakeImage('second.jpg')])->assertCreated();

        $this->assertTrue($first->fresh()->is_primary);
        $this->assertSame(1, $item->images()->where('is_primary', true)->count());
    }

    public function test_owner_can_select_a_different_primary_image(): void
    {
        $owner = $this->createUser();
        $item = $this->createItem($owner);
        $this->uploadImages($owner, $item, [
            $this->fakeImage('first.jpg'),
            $this->fakeImage('second.jpg'),
        ])->assertCreated();
        $images = $item->images()->orderBy('sort_order')->get();

        $this->actingAs($owner, 'sanctum')
            ->patchJson('/api/items/'.$item->id.'/images/'.$images[1]->id.'/primary')
            ->assertOk()
            ->assertJsonPath('data.id', $images[1]->id)
            ->assertJsonPath('data.is_primary', true);

        $this->assertTrue($images[1]->fresh()->is_primary);
        $this->assertFalse($images[0]->fresh()->is_primary);
    }

    public function test_setting_primary_leaves_exactly_one_primary_image(): void
    {
        $owner = $this->createUser();
        $item = $this->createItem($owner);
        $this->uploadImages($owner, $item, [
            $this->fakeImage('first.jpg'),
            $this->fakeImage('second.jpg'),
            $this->fakeImage('third.jpg'),
        ])->assertCreated();
        $selected = $item->images()->orderByDesc('id')->firstOrFail();

        $this->actingAs($owner, 'sanctum')
            ->patchJson('/api/items/'.$item->id.'/images/'.$selected->id.'/primary')
            ->assertOk();

        $this->assertSame(1, $item->images()->where('is_primary', true)->count());
    }

    public function test_deleting_image_removes_its_record_and_file(): void
    {
        $owner = $this->createUser();
        $item = $this->createItem($owner);
        $this->uploadImages($owner, $item, [$this->fakeImage('delete.jpg')])->assertCreated();
        $image = $item->images()->firstOrFail();
        Storage::disk('local')->assertExists($image->image_path);

        $this->actingAs($owner, 'sanctum')
            ->deleteJson('/api/items/'.$item->id.'/images/'.$image->id)
            ->assertOk();

        $this->assertDatabaseMissing('item_images', ['id' => $image->id], 'mysql');
        Storage::disk('local')->assertMissing($image->image_path);
    }

    public function test_deleting_primary_promotes_oldest_remaining_image(): void
    {
        $owner = $this->createUser();
        $item = $this->createItem($owner);
        $primary = $this->createImage($item, 'items/'.$item->id.'/primary.jpg', 0, true);
        $next = $this->createImage($item, 'items/'.$item->id.'/next.jpg', 2);
        $this->createImage($item, 'items/'.$item->id.'/last.jpg', 2);
        Storage::disk('local')->put($primary->image_path, 'image');

        $this->actingAs($owner, 'sanctum')
            ->deleteJson('/api/items/'.$item->id.'/images/'.$primary->id)
            ->assertOk();

        $this->assertTrue($next->fresh()->is_primary);
        $this->assertSame(1, $item->images()->where('is_primary', true)->count());
    }

    public function test_deleting_non_primary_does_not_change_primary(): void
    {
        $owner = $this->createUser();
        $item = $this->createItem($owner);
        $primary = $this->createImage($item, 'items/'.$item->id.'/primary.jpg', 0, true);
        $secondary = $this->createImage($item, 'items/'.$item->id.'/secondary.jpg', 1);

        $this->actingAs($owner, 'sanctum')
            ->deleteJson('/api/items/'.$item->id.'/images/'.$secondary->id)
            ->assertOk();

        $this->assertTrue($primary->fresh()->is_primary);
        $this->assertSame(1, $item->images()->where('is_primary', true)->count());
    }

    public function test_image_from_another_item_cannot_be_deleted_or_selected(): void
    {
        $owner = $this->createUser();
        $item = $this->createItem($owner);
        $otherItem = $this->createItem($owner);
        $image = $this->createImage($otherItem, 'items/'.$otherItem->id.'/other.jpg', 0, true);

        $this->actingAs($owner, 'sanctum')
            ->deleteJson('/api/items/'.$item->id.'/images/'.$image->id)
            ->assertNotFound();

        $this->patchJson('/api/items/'.$item->id.'/images/'.$image->id.'/primary')
            ->assertNotFound();

        $this->assertDatabaseHas('item_images', ['id' => $image->id], 'mysql');
    }

    public function test_non_owner_cannot_change_primary_image(): void
    {
        $owner = $this->createUser();
        $other = $this->createUser();
        $item = $this->createItem($owner);
        $image = $this->createImage($item, 'items/'.$item->id.'/image.jpg', 0, true);

        $this->actingAs($other, 'sanctum')
            ->patchJson('/api/items/'.$item->id.'/images/'.$image->id.'/primary')
            ->assertForbidden();

        $this->assertTrue($image->fresh()->is_primary);
    }

    public function test_non_owner_cannot_delete_item_image(): void
    {
        $owner = $this->createUser();
        $other = $this->createUser();
        $item = $this->createItem($owner);
        $image = $this->createImage($item, 'items/'.$item->id.'/image.jpg', 0, true);

        $this->actingAs($other, 'sanctum')
            ->deleteJson('/api/items/'.$item->id.'/images/'.$image->id)
            ->assertForbidden();

        $this->assertDatabaseHas('item_images', ['id' => $image->id], 'mysql');
    }

    public function test_missing_storage_file_does_not_affect_other_image_records(): void
    {
        $owner = $this->createUser();
        $item = $this->createItem($owner);
        $primary = $this->createImage($item, 'items/'.$item->id.'/primary.jpg', 0, true);
        $missingFile = $this->createImage($item, 'items/'.$item->id.'/missing.jpg', 1);

        $this->actingAs($owner, 'sanctum')
            ->deleteJson('/api/items/'.$item->id.'/images/'.$missingFile->id)
            ->assertOk();

        $this->assertDatabaseMissing('item_images', ['id' => $missingFile->id], 'mysql');
        $this->assertTrue($primary->fresh()->is_primary);
        $this->assertSame(1, $item->images()->count());
    }

    public function test_soft_deleting_item_preserves_its_image_record_and_file(): void
    {
        $owner = $this->createUser();
        $item = $this->createItem($owner);
        $image = $this->createImage($item, 'items/'.$item->id.'/image.jpg', 0, true);
        Storage::disk('local')->put($image->image_path, 'image');

        $this->actingAs($owner, 'sanctum')
            ->deleteJson('/api/items/'.$item->id)
            ->assertNoContent();

        $this->assertDatabaseHas('item_images', ['id' => $image->id], 'mysql');
        Storage::disk('local')->assertExists($image->image_path);
    }

    public function test_item_resource_includes_image_details_and_protected_file_url(): void
    {
        $viewer = $this->createUser();
        $item = $this->createItem($this->createUser());
        $image = $this->createImage($item, 'items/'.$item->id.'/resource.jpg', 0, true);
        Storage::disk('local')->put($image->image_path, 'image');
        $expectedUrl = route('api.items.images.file', [
            'item' => $item->id,
            'image' => $image->id,
        ]);

        $this->actingAs($viewer, 'sanctum')
            ->getJson('/api/items/'.$item->id)
            ->assertOk()
            ->assertJsonPath('data.images.0.id', $image->id)
            ->assertJsonPath('data.images.0.url', $expectedUrl)
            ->assertJsonPath('data.images.0.is_primary', true)
            ->assertJsonPath('data.images.0.sort_order', 0);
    }

    public function test_guest_cannot_read_item_image_file(): void
    {
        $item = $this->createItem($this->createUser());
        $image = $this->createImage($item, 'items/'.$item->id.'/image.png', 0, true);
        $this->storeFakeImage($image);

        $this->getJson($this->fileUrl($item, $image))->assertUnauthorized();
    }

    public function test_suspended_account_cannot_read_item_image_file(): void
    {
        $user = $this->createUser('suspended');
        $item = $this->createItem($user);
        $image = $this->createImage($item, 'items/'.$item->id.'/image.png', 0, true);
        $this->storeFakeImage($image);

        $this->actingAs($user, 'sanctum')
            ->getJson($this->fileUrl($item, $image))
            ->assertForbidden();
    }

    public function test_deactivated_account_cannot_read_item_image_file(): void
    {
        $user = $this->createUser('deactivated');
        $item = $this->createItem($user);
        $image = $this->createImage($item, 'items/'.$item->id.'/image.png', 0, true);
        $this->storeFakeImage($image);

        $this->actingAs($user, 'sanctum')
            ->getJson($this->fileUrl($item, $image))
            ->assertForbidden();
    }

    public function test_active_user_can_read_active_item_image_file(): void
    {
        $owner = $this->createUser();
        $viewer = $this->createUser();
        $item = $this->createItem($owner);
        $image = $this->createImage($item, 'items/'.$item->id.'/image.png', 0, true);
        $contents = $this->storeFakeImage($image);

        $response = $this->actingAs($viewer, 'sanctum')
            ->getJson($this->fileUrl($item, $image))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $cacheControl = $response->headers->get('Cache-Control');
        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringContainsString('max-age=0', $cacheControl);
        $response->assertStreamedContent($contents);
    }

    public function test_owner_can_read_inactive_item_image_file(): void
    {
        $owner = $this->createUser();
        $item = $this->createItem($owner, 'inactive');
        $image = $this->createImage($item, 'items/'.$item->id.'/image.png', 0, true);
        $this->storeFakeImage($image);

        $this->actingAs($owner, 'sanctum')
            ->getJson($this->fileUrl($item, $image))
            ->assertOk();
    }

    public function test_owner_can_read_archived_item_image_file(): void
    {
        $owner = $this->createUser();
        $item = $this->createItem($owner, 'archived');
        $image = $this->createImage($item, 'items/'.$item->id.'/image.png', 0, true);
        $this->storeFakeImage($image);

        $this->actingAs($owner, 'sanctum')
            ->getJson($this->fileUrl($item, $image))
            ->assertOk();
    }

    public function test_other_user_cannot_read_inactive_item_image_file(): void
    {
        $owner = $this->createUser();
        $viewer = $this->createUser();
        $item = $this->createItem($owner, 'inactive');
        $image = $this->createImage($item, 'items/'.$item->id.'/image.png', 0, true);
        $this->storeFakeImage($image);

        $this->actingAs($viewer, 'sanctum')
            ->getJson($this->fileUrl($item, $image))
            ->assertNotFound();
    }

    public function test_other_user_cannot_read_archived_item_image_file(): void
    {
        $owner = $this->createUser();
        $viewer = $this->createUser();
        $item = $this->createItem($owner, 'archived');
        $image = $this->createImage($item, 'items/'.$item->id.'/image.png', 0, true);
        $this->storeFakeImage($image);

        $this->actingAs($viewer, 'sanctum')
            ->getJson($this->fileUrl($item, $image))
            ->assertNotFound();
    }

    public function test_image_from_another_item_cannot_be_read_through_item_file_route(): void
    {
        $owner = $this->createUser();
        $item = $this->createItem($owner);
        $otherItem = $this->createItem($owner);
        $image = $this->createImage($otherItem, 'items/'.$otherItem->id.'/image.png', 0, true);
        $this->storeFakeImage($image);

        $this->actingAs($owner, 'sanctum')
            ->getJson($this->fileUrl($item, $image))
            ->assertNotFound();
    }

    public function test_missing_private_image_file_returns_not_found(): void
    {
        $owner = $this->createUser();
        $item = $this->createItem($owner);
        $image = $this->createImage($item, 'items/'.$item->id.'/missing.png', 0, true);

        $this->actingAs($owner, 'sanctum')
            ->getJson($this->fileUrl($item, $image))
            ->assertNotFound();
    }

    public function test_upload_stores_image_on_local_disk_not_public_disk(): void
    {
        Storage::fake('public');
        $owner = $this->createUser();
        $item = $this->createItem($owner);

        $this->uploadImages($owner, $item, [$this->fakeImage('private.png')])
            ->assertCreated();

        $image = $item->images()->firstOrFail();
        Storage::disk('local')->assertExists($image->image_path);
        Storage::disk('public')->assertDirectoryEmpty('items/'.$item->id);
    }

    public function test_upload_cleanup_failure_is_logged_and_original_exception_is_preserved(): void
    {
        $owner = $this->createUser();
        $item = $this->createItem($owner);
        $localDisk = Storage::disk('local');
        $mockDisk = Mockery::mock($localDisk)->makePartial();
        $mockDisk->shouldReceive('delete')
            ->once()
            ->with(Mockery::type('string'))
            ->andReturn(false);
        Storage::shouldReceive('disk')
            ->twice()
            ->with('local')
            ->andReturn($mockDisk);

        $failedPath = null;
        Log::shouldReceive('error')
            ->once()
            ->withArgs(function (string $message, array $context) use ($item, &$failedPath): bool {
                $failedPath = $context['path'] ?? null;

                return $message === 'Failed to clean up item image after upload failure.'
                    && $context['item_id'] === $item->id
                    && str_starts_with($failedPath, 'items/'.$item->id.'/');
            });

        ItemImage::creating(function (): void {
            throw new RuntimeException('Simulated image record failure.');
        });

        $this->withoutExceptionHandling();

        try {
            $this->uploadImages($owner, $item, [$this->fakeImage('failure.png')]);
            $this->fail('The simulated record failure should be rethrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated image record failure.', $exception->getMessage());
        }

        $this->assertNotNull($failedPath);
        $this->assertTrue($localDisk->exists($failedPath));
        $this->assertSame(0, $item->images()->count());
    }

    public function test_upload_cleanup_exception_is_logged_without_replacing_original_exception(): void
    {
        $owner = $this->createUser();
        $item = $this->createItem($owner);
        $localDisk = Storage::disk('local');
        $mockDisk = Mockery::mock($localDisk)->makePartial();
        $mockDisk->shouldReceive('delete')
            ->once()
            ->with(Mockery::type('string'))
            ->andThrow(new RuntimeException('Simulated cleanup failure.'));
        Storage::shouldReceive('disk')
            ->twice()
            ->with('local')
            ->andReturn($mockDisk);

        $failedPath = null;
        Log::shouldReceive('error')
            ->once()
            ->withArgs(function (string $message, array $context) use ($item, &$failedPath): bool {
                $failedPath = $context['path'] ?? null;

                return $message === 'Failed to clean up item image after upload failure.'
                    && $context['item_id'] === $item->id
                    && str_starts_with($failedPath, 'items/'.$item->id.'/')
                    && $context['cleanup_exception_class'] === RuntimeException::class;
            });

        ItemImage::creating(function (): void {
            throw new RuntimeException('Simulated image record failure.');
        });

        $this->withoutExceptionHandling();

        try {
            $this->uploadImages($owner, $item, [$this->fakeImage('failure.png')]);
            $this->fail('The simulated record failure should be rethrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated image record failure.', $exception->getMessage());
        }

        $this->assertNotNull($failedPath);
        $this->assertTrue($localDisk->exists($failedPath));
        $this->assertSame(0, $item->images()->count());
    }

    public function test_upload_logger_failure_does_not_replace_original_exception(): void
    {
        $owner = $this->createUser();
        $item = $this->createItem($owner);
        $localDisk = Storage::disk('local');
        $mockDisk = Mockery::mock($localDisk)->makePartial();
        $mockDisk->shouldReceive('delete')
            ->once()
            ->with(Mockery::type('string'))
            ->andReturn(false);
        Storage::shouldReceive('disk')
            ->twice()
            ->with('local')
            ->andReturn($mockDisk);
        Log::shouldReceive('error')
            ->once()
            ->andThrow(new RuntimeException('Simulated logger failure.'));

        ItemImage::creating(function (): void {
            throw new RuntimeException('Simulated image record failure.');
        });

        $this->withoutExceptionHandling();

        try {
            $this->uploadImages($owner, $item, [$this->fakeImage('logger-failure.png')]);
            $this->fail('The simulated record failure should be rethrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated image record failure.', $exception->getMessage());
        }

        $this->assertSame(0, $item->images()->count());
        $this->assertCount(1, $localDisk->allFiles('items/'.$item->id));
    }

    public function test_delete_file_failure_is_logged_after_record_deletion(): void
    {
        $owner = $this->createUser();
        $item = $this->createItem($owner);
        $image = $this->createImage($item, 'items/'.$item->id.'/cleanup.png', 0, true);
        $localDisk = Storage::disk('local');
        $localDisk->put($image->image_path, 'image');
        $mockDisk = Mockery::mock($localDisk)->makePartial();
        $mockDisk->shouldReceive('exists')->once()->with($image->image_path)->andReturn(true);
        $mockDisk->shouldReceive('delete')->once()->with($image->image_path)->andReturn(false);
        Storage::shouldReceive('disk')->once()->with('local')->andReturn($mockDisk);

        Log::shouldReceive('error')
            ->once()
            ->withArgs(function (string $message, array $context) use ($item, $image): bool {
                return $message === 'Item image file requires cleanup after record deletion.'
                    && $context['item_id'] === $item->id
                    && $context['path'] === $image->image_path;
            });

        $this->actingAs($owner, 'sanctum')
            ->deleteJson('/api/items/'.$item->id.'/images/'.$image->id)
            ->assertOk()
            ->assertJsonPath('warning', 'The physical image file still requires cleanup.');

        $this->assertDatabaseMissing('item_images', ['id' => $image->id], 'mysql');
        $this->assertTrue($localDisk->exists($image->image_path));
    }

    public function test_delete_file_exception_is_logged_without_reverting_record_deletion(): void
    {
        $owner = $this->createUser();
        $item = $this->createItem($owner);
        $image = $this->createImage($item, 'items/'.$item->id.'/exception.png', 0, true);
        $localDisk = Storage::disk('local');
        $localDisk->put($image->image_path, 'image');
        $mockDisk = Mockery::mock($localDisk)->makePartial();
        $mockDisk->shouldReceive('exists')->once()->with($image->image_path)->andReturn(true);
        $mockDisk->shouldReceive('delete')
            ->once()
            ->with($image->image_path)
            ->andThrow(new RuntimeException('Simulated storage failure.'));
        Storage::shouldReceive('disk')->once()->with('local')->andReturn($mockDisk);

        Log::shouldReceive('error')
            ->once()
            ->withArgs(function (string $message, array $context) use ($item, $image): bool {
                return $message === 'Item image file requires cleanup after record deletion.'
                    && $context['item_id'] === $item->id
                    && $context['path'] === $image->image_path
                    && $context['cleanup_exception_class'] === RuntimeException::class;
            });

        $this->actingAs($owner, 'sanctum')
            ->deleteJson('/api/items/'.$item->id.'/images/'.$image->id)
            ->assertOk()
            ->assertJsonPath('warning', 'The physical image file still requires cleanup.');

        $this->assertDatabaseMissing('item_images', ['id' => $image->id], 'mysql');
        $this->assertTrue($localDisk->exists($image->image_path));
    }

    public function test_delete_logger_failure_keeps_success_response_after_record_deletion(): void
    {
        $owner = $this->createUser();
        $item = $this->createItem($owner);
        $image = $this->createImage($item, 'items/'.$item->id.'/logger-failure.png', 0, true);
        $localDisk = Storage::disk('local');
        $localDisk->put($image->image_path, 'image');
        $mockDisk = Mockery::mock($localDisk)->makePartial();
        $mockDisk->shouldReceive('exists')->once()->with($image->image_path)->andReturn(true);
        $mockDisk->shouldReceive('delete')->once()->with($image->image_path)->andReturn(false);
        Storage::shouldReceive('disk')->once()->with('local')->andReturn($mockDisk);
        Log::shouldReceive('error')
            ->once()
            ->andThrow(new RuntimeException('Simulated logger failure.'));

        $this->actingAs($owner, 'sanctum')
            ->deleteJson('/api/items/'.$item->id.'/images/'.$image->id)
            ->assertOk()
            ->assertJsonPath('warning', 'The physical image file still requires cleanup.');

        $this->assertDatabaseMissing('item_images', ['id' => $image->id], 'mysql');
        $this->assertTrue($localDisk->exists($image->image_path));
    }

    private function createUser(string $accountStatus = 'active'): User
    {
        $user = User::factory()->create();
        $user->account_status = $accountStatus;
        $user->save();

        return $user;
    }

    private function createItem(User $owner, string $listingStatus = 'active'): Item
    {
        $category = Category::on('mysql')->create([
            'name' => 'Image API category '.Str::uuid(),
            'is_active' => true,
        ]);

        return Item::on('mysql')->create([
            'owner_id' => $owner->id,
            'category_id' => $category->id,
            'title' => 'Image API item '.Str::uuid(),
            'description' => 'Item description for image API tests.',
            'daily_price' => '25.50',
            'item_condition' => 'good',
            'condition_notes' => null,
            'listing_status' => $listingStatus,
        ]);
    }

    private function createImage(
        Item $item,
        string $path,
        int $sortOrder,
        bool $primary = false
    ): ItemImage {
        return $item->images()->create([
            'image_path' => $path,
            'is_primary' => $primary,
            'sort_order' => $sortOrder,
        ]);
    }

    private function fakeImage(string $name): UploadedFile
    {
        return UploadedFile::fake()->image($name);
    }

    private function storeFakeImage(ItemImage $image, string $name = 'image.png'): string
    {
        $file = $this->fakeImage($name);
        $contents = file_get_contents($file->getRealPath());
        Storage::disk('local')->put($image->image_path, $contents);

        return $contents;
    }

    private function fileUrl(Item $item, ItemImage $image): string
    {
        return route('api.items.images.file', [
            'item' => $item->id,
            'image' => $image->id,
        ], false);
    }

    /**
     * @param  array<int, UploadedFile>  $files
     */
    private function uploadImages(User $user, Item $item, array $files)
    {
        return $this->actingAs($user, 'sanctum')
            ->post('/api/items/'.$item->id.'/images', [
                'images' => $files,
            ], ['Accept' => 'application/json']);
    }
}
