<?php

namespace Tests\Feature\Walks;

use App\Models\Finding;
use App\Models\GembaWalk;
use App\Models\User;
use App\Services\FindingPhotoProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use InvalidArgumentException;
use Tests\TestCase;

class FindingPhotoProcessorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
    }

    public function test_store_downscales_photo_and_generates_thumbnail(): void
    {
        $walk = GembaWalk::factory()->create();
        $file = UploadedFile::fake()->image('photo.jpg', 4000, 3000);

        $paths = app(FindingPhotoProcessor::class)->store($file, $walk);

        Storage::disk('public')->assertExists($paths['photo_path']);
        Storage::disk('public')->assertExists($paths['thumbnail_path']);

        $this->assertStringStartsWith("findings/{$walk->id}/", $paths['photo_path']);
        $this->assertStringStartsWith("findings/{$walk->id}/thumbs/", $paths['thumbnail_path']);

        [$fullWidth, $fullHeight] = $this->dimensions($paths['photo_path']);
        $this->assertSame(1920, max($fullWidth, $fullHeight));

        [$thumbWidth, $thumbHeight] = $this->dimensions($paths['thumbnail_path']);
        $this->assertSame(480, $thumbWidth);
        $this->assertSame(360, $thumbHeight);
    }

    public function test_png_upload_is_stored_as_jpeg(): void
    {
        $walk = GembaWalk::factory()->create();
        $file = UploadedFile::fake()->image('photo.png', 100, 100);

        $paths = app(FindingPhotoProcessor::class)->store($file, $walk);

        $this->assertStringEndsWith('.jpg', $paths['photo_path']);
        $this->assertStringEndsWith('.jpg', $paths['thumbnail_path']);

        Storage::disk('public')->assertExists($paths['photo_path']);
        Storage::disk('public')->assertExists($paths['thumbnail_path']);
    }

    public function test_exif_metadata_is_stripped(): void
    {
        if (! function_exists('exif_read_data')) {
            $this->markTestSkipped('The exif extension is not installed.');
        }

        $walk = GembaWalk::factory()->create();
        $file = UploadedFile::fake()->image('photo.jpg', 400, 300);

        $paths = app(FindingPhotoProcessor::class)->store($file, $walk);

        $exif = @exif_read_data(Storage::disk('public')->path($paths['photo_path']));

        $hasMetadata = is_array($exif)
            && (isset($exif['GPSLatitude']) || isset($exif['GPSLongitude']) || isset($exif['Make']));

        $this->assertFalse($hasMetadata, 'The stored JPEG must not contain EXIF GPS or camera metadata.');
    }

    public function test_replace_deletes_old_files_and_sets_new_paths(): void
    {
        $walk = GembaWalk::factory()->create();
        $finding = Finding::factory()->for($walk, 'walk')->create();

        $processor = app(FindingPhotoProcessor::class);

        $first = $processor->store(UploadedFile::fake()->image('one.jpg', 100, 100), $walk);
        $finding->photo_path = $first['photo_path'];
        $finding->thumbnail_path = $first['thumbnail_path'];
        $finding->save();

        Storage::disk('public')->assertExists($first['photo_path']);
        Storage::disk('public')->assertExists($first['thumbnail_path']);

        $processor->replace($finding, UploadedFile::fake()->image('two.jpg', 120, 120));

        Storage::disk('public')->assertMissing($first['photo_path']);
        Storage::disk('public')->assertMissing($first['thumbnail_path']);

        $this->assertNotSame($first['photo_path'], $finding->photo_path);
        $this->assertNotSame($first['thumbnail_path'], $finding->thumbnail_path);

        Storage::disk('public')->assertExists($finding->photo_path);
        Storage::disk('public')->assertExists($finding->thumbnail_path);
    }

    public function test_non_image_throws_invalid_argument_exception(): void
    {
        $walk = GembaWalk::factory()->create();
        $file = UploadedFile::fake()->createWithContent('fake.jpg', 'this is not an image');

        $this->expectException(InvalidArgumentException::class);

        app(FindingPhotoProcessor::class)->store($file, $walk);
    }

    public function test_validation_rejects_heic_with_custom_message(): void
    {
        $file = UploadedFile::fake()->create('photo.heic', 100, 'image/heic');

        $validator = Validator::make(
            ['photo' => $file],
            FindingPhotoProcessor::rules(),
            FindingPhotoProcessor::messages(),
        );

        $this->assertTrue($validator->fails());
        $this->assertContains(
            'Please upload a JPG, PNG or WebP photo (iPhone: Settings > Camera > Formats > Most Compatible).',
            $validator->errors()->get('photo'),
        );
    }

    public function test_validation_rejects_files_over_12_mb(): void
    {
        $file = UploadedFile::fake()->create('photo.jpg', 13312, 'image/jpeg');

        $validator = Validator::make(
            ['photo' => $file],
            FindingPhotoProcessor::rules(),
            FindingPhotoProcessor::messages(),
        );

        $this->assertTrue($validator->fails());
        $this->assertTrue($validator->errors()->has('photo'));
    }

    public function test_validation_accepts_a_jpg(): void
    {
        $file = UploadedFile::fake()->image('photo.jpg', 100, 100);

        $validator = Validator::make(
            ['photo' => $file],
            FindingPhotoProcessor::rules(),
            FindingPhotoProcessor::messages(),
        );

        $this->assertTrue($validator->passes());
    }

    public function test_owner_check_passes_when_user_id_is_a_numeric_string(): void
    {
        $user = User::factory()->create();
        $walk = GembaWalk::factory()->for($user)->create();

        $walk->user_id = (string) $user->id;

        $this->assertTrue($user->can('view', $walk));
        $this->assertTrue($user->can('update', $walk));
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function dimensions(string $path): array
    {
        [$width, $height] = getimagesize(Storage::disk('public')->path($path));

        return [$width, $height];
    }
}
