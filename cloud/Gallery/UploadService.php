<?php

declare(strict_types=1);

namespace SentryIQCloud\Gallery;

use RuntimeException;
use SentryIQCloud\Gallery\Image\ImageProcessor;
use SentryIQCloud\Gallery\Image\ThumbnailGenerator;
use SentryIQCloud\Gallery\Storage\DuplicateIndex;
use SentryIQCloud\Gallery\Storage\PhotoStorage;

final class UploadService
{
    public function __construct(
        private readonly ImageProcessor $processor,
        private readonly ThumbnailGenerator $thumbnailGenerator,
        private readonly DuplicateIndex $duplicateIndex,
        private readonly PhotoStorage $storage,
    ) {
    }

    public function upload(array $upload): array
    {
        $error = $upload['error'] ?? UPLOAD_ERR_NO_FILE;
        if (!is_int($error) || $error !== UPLOAD_ERR_OK) {
            return ['status' => 'rejected', 'message' => $this->uploadErrorMessage($error)];
        }

        $temporaryPath = $upload['tmp_name'] ?? '';
        if (!is_string($temporaryPath) || $temporaryPath === '' || !is_uploaded_file($temporaryPath)) {
            return ['status' => 'rejected', 'message' => 'Invalid upload source.'];
        }

        $input = file_get_contents($temporaryPath);
        if ($input === false) {
            return ['status' => 'rejected', 'message' => 'Unable to read uploaded image.'];
        }

        try {
            $webp = $this->processor->toWebp($input);
            $hash = $this->processor->contentHash($webp);

            $existingPhotoId = $this->duplicateIndex->find($hash);
            if ($existingPhotoId !== null) {
                return [
                    'status' => 'duplicate',
                    'photo_id' => $existingPhotoId,
                    'hash' => $hash,
                    'message' => 'This image already exists in the gallery.',
                ];
            }

            $thumbnail = $this->thumbnailGenerator->fromWebp($webp);
            $stored = $this->storage->store($webp, $thumbnail, $hash);
            $this->duplicateIndex->add($hash, $stored['photo_id']);

            return [
                'status' => 'stored',
                'photo_id' => $stored['photo_id'],
                'hash' => $hash,
                'message' => 'Image uploaded successfully.',
            ];
        } catch (RuntimeException $exception) {
            return ['status' => 'rejected', 'message' => $exception->getMessage()];
        }
    }

    private function uploadErrorMessage(mixed $error): string
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The uploaded image is too large.',
            UPLOAD_ERR_PARTIAL => 'The image upload was incomplete.',
            UPLOAD_ERR_NO_FILE => 'No image was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'The server upload directory is unavailable.',
            UPLOAD_ERR_CANT_WRITE => 'The server could not save the uploaded image.',
            UPLOAD_ERR_EXTENSION => 'The upload was stopped by a server extension.',
            default => 'The image upload failed.',
        };
    }
}
