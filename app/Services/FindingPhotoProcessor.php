<?php

namespace App\Services;

use App\Models\Finding;
use App\Models\GembaWalk;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Exceptions\DecoderException;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;
use InvalidArgumentException;

class FindingPhotoProcessor
{
    private ImageManager $manager;

    public function __construct(?ImageManager $manager = null)
    {
        $this->manager = $manager ?? new ImageManager(new Driver());
    }

    /**
     * Process an uploaded photo into a downscaled full image and a thumbnail.
     *
     * @return array{photo_path: string, thumbnail_path: string}
     */
    public function store(UploadedFile $file, GembaWalk $walk): array
    {
        $image = $this->read($file);

        // Re-encoding through GD drops all EXIF/GPS metadata, so no camera,
        // location or timestamp details leak into the stored JPEGs (privacy).
        $uuid = (string) Str::uuid();
        $directory = "findings/{$walk->getKey()}";

        $photoPath = "{$directory}/{$uuid}.jpg";
        $photo = $image->orient()->scaleDown(width: 1920, height: 1920)->toJpeg(quality: 82);
        Storage::disk('public')->put($photoPath, (string) $photo);

        $thumbnailPath = "{$directory}/thumbs/{$uuid}.jpg";
        $thumbnail = $image->orient()->cover(480, 360)->toJpeg(quality: 75);
        Storage::disk('public')->put($thumbnailPath, (string) $thumbnail);

        return [
            'photo_path' => $photoPath,
            'thumbnail_path' => $thumbnailPath,
        ];
    }

    /**
     * Replace an existing finding's photo and thumbnail with a new upload.
     */
    public function replace(Finding $finding, UploadedFile $file): void
    {
        $paths = $this->store($file, $finding->walk);

        $disk = Storage::disk('public');

        if ($finding->photo_path) {
            $disk->delete($finding->photo_path);
        }

        if ($finding->thumbnail_path) {
            $disk->delete($finding->thumbnail_path);
        }

        $finding->photo_path = $paths['photo_path'];
        $finding->thumbnail_path = $paths['thumbnail_path'];
        $finding->save();
    }

    /**
     * Validation rules for an uploaded finding photo.
     *
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return [
            'photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:12288'],
        ];
    }

    /**
     * Custom validation messages for the photo rules.
     *
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'photo.mimes' => 'Please upload a JPG, PNG or WebP photo (iPhone: Settings > Camera > Formats > Most Compatible).',
        ];
    }

    /**
     * Decode an upload into an image, or fail with a clear message.
     */
    private function read(UploadedFile $file): ImageInterface
    {
        try {
            return $this->manager->read($file->getPathname());
        } catch (DecoderException $e) {
            throw new InvalidArgumentException(
                'The uploaded file is not a valid image.',
                previous: $e,
            );
        }
    }
}
