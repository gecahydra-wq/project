<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ImageUploader
{
    public const IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    public const VIDEO_MIMES = ['video/mp4', 'video/quicktime', 'video/webm'];

    public const MAX_IMAGE_KB = 5120;

    public const MAX_VIDEO_KB = 102400;

    public static function store(UploadedFile $file, string $directory): string
    {
        $filename = Str::uuid().'.'.$file->extension();
        $path = $file->storeAs($directory, $filename, 'public');

        return Storage::disk('public')->url($path);
    }

    public static function delete(?string $url): void
    {
        if (! $url) {
            return;
        }

        $marker = '/storage/';
        $position = strpos($url, $marker);

        if ($position === false) {
            return;
        }

        Storage::disk('public')->delete(substr($url, $position + strlen($marker)));
    }

    public static function validationRules(): array
    {
        return ['image', 'mimes:jpeg,jpg,png,webp', 'max:5120'];
    }

    /**
     * Classify an uploaded file as 'photo' or 'video' based on its actual
     * (fileinfo-detected) MIME type, not its extension or client-supplied type.
     */
    public static function detectMediaType(UploadedFile $file): ?string
    {
        $mime = $file->getMimeType();

        if (in_array($mime, self::IMAGE_MIMES, true)) {
            return 'photo';
        }

        if (in_array($mime, self::VIDEO_MIMES, true)) {
            return 'video';
        }

        return null;
    }

    /**
     * Validate a single listing-media upload (photo or video), enforcing a
     * type-appropriate size limit. Returns an error message, or null if valid.
     */
    public static function validateMediaFile(UploadedFile $file): ?string
    {
        $type = self::detectMediaType($file);

        if (! $type) {
            return 'Unsupported file type. Allowed formats: JPG, PNG, WEBP images or MP4, MOV, WEBM videos.';
        }

        $maxKb = $type === 'photo' ? self::MAX_IMAGE_KB : self::MAX_VIDEO_KB;

        if ($file->getSize() > $maxKb * 1024) {
            return $type === 'photo'
                ? 'Images must be 5MB or smaller.'
                : 'Videos must be 100MB or smaller.';
        }

        return null;
    }
}
