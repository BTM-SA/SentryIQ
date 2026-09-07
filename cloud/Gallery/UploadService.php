<?php

declare(strict_types=1);

namespace SentryIQCloud\Gallery;

use Throwable;
use RuntimeException;
use SentryIQCloud\Gallery\Image\ImageProcessor;
use SentryIQCloud\Gallery\Image\ThumbnailGenerator;
use SentryIQCloud\Gallery\Storage\DuplicateIndex;
use SentryIQCloud\Gallery\Storage\PhotoMetadataStore;
use SentryIQCloud\Gallery\Storage\PhotoNameAllocator;
use SentryIQCloud\Gallery\Storage\PhotoStorage;

final class UploadService
{
    public function __construct(
        private readonly ImageProcessor $processor,
        private readonly ThumbnailGenerator $thumbnailGenerator,
        private readonly DuplicateIndex $duplicateIndex,
        private readonly PhotoStorage $storage,
        private readonly PhotoMetadataStore $metadata,
        private readonly PhotoNameAllocator $nameAllocator,
    ) {}

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

        $filename = null;
        $stored = null;

        try {
            $webp = $this->processor->toWebp($input);
            $hash = $this->processor->contentHash($webp);
            $existingPhotoId = $this->duplicateIndex->find($hash);
            if ($existingPhotoId !== null) {
                return [
                    'status' => 'duplicate',
                    'photo_id' => $existingPhotoId,
                    'message' => 'This image already exists in the gallery.',
                ];
            }

            // Reserve the lowest available generated name only after duplicate detection.
            $filename = $this->nameAllocator->next();
            $thumbnail = $this->thumbnailGenerator->fromWebp($webp);
            $stored = $this->storage->store($webp, $thumbnail, $hash, $filename);

            try {
                $this->duplicateIndex->add($hash, $stored['photo_id']);
                $this->metadata->add(
                    $stored['photo_id'],
                    (string)($upload['name'] ?? ''),
                    $filename,
                    $hash,
                    (int)$stored['created_at'],
                );
            } catch (RuntimeException $exception) {
                @unlink($stored['path']);
                @unlink($stored['thumbnail_path']);
                throw $exception;
            }

            // The physical file now owns this number; remove only the temporary reservation.
            $this->nameAllocator->release($filename);
            $filename = null;

            return [
                'status' => 'stored',
                'photo_id' => $stored['photo_id'],
                'filename' => $stored['filename'] ?? basename($stored['path']),
                'message' => 'Image uploaded successfully.',
            ];
        } catch (Throwable $exception) {
            if ($stored !== null) {
                @unlink($stored['path']);
                @unlink($stored['thumbnail_path']);
            }

            if ($filename !== null) {
                $this->nameAllocator->release($filename);
            }

            if ($exception instanceof RuntimeException) {
                return ['status' => 'rejected', 'message' => $exception->getMessage()];
            }

            throw $exception;
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
