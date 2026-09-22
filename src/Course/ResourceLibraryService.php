<?php

declare(strict_types=1);

namespace CattoLearning\Course;

use InvalidArgumentException;
use RuntimeException;

/** Manages immutable files in the single Resource Library directory and their database records. */
final class ResourceLibraryService
{
    public const TYPES = ['pdf','image_graphic','uploaded_video','audio','markdown','document'];

    public function __construct(private readonly CourseItemRepository $records, private readonly string $storageRoot)
    {
    }

    /** @return list<array<string,mixed>> */
    public function library(string $search = '', string $type = ''): array
    {
        if ($type !== '' && !in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException('Select a valid Resource type.');
        }
        $resources = $this->records->resources(trim($search), $type);
        foreach ($resources as &$resource) { $resource['usage'] = $this->records->resourceUsage((int) $resource['id']); }
        unset($resource);
        return $resources;
    }

    /**
     * @param array<string,mixed> $upload
     * @param array<string,mixed> $input
     */
    public function upload(array $upload, array $input, int $userId): int
    {
        if ((int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($upload['tmp_name'] ?? ''))) {
            throw new InvalidArgumentException('Select a file to upload.');
        }
        $filename = $this->filename((string) ($upload['name'] ?? ''));
        $directory = $this->directory();
        $target = $directory . '/' . $filename;
        if (is_file($target)) {
            throw new InvalidArgumentException('A Resource file with that filename already exists.');
        }
        $data = $this->metadata($filename, $input, (string) ($upload['tmp_name'] ?? ''), (int) ($upload['size'] ?? 0));
        $destination = @fopen($target, 'xb');
        if ($destination === false) { throw new InvalidArgumentException('A Resource file with that filename already exists or cannot be created.'); }
        $source = fopen((string) $upload['tmp_name'], 'rb');
        if ($source === false) { fclose($destination); unlink($target); throw new RuntimeException('Unable to read the uploaded Resource file.'); }
        try {
            $copied = stream_copy_to_stream($source, $destination);
            if ($copied !== (int) $upload['size']) { throw new RuntimeException('The complete Resource file could not be stored.'); }
        } catch (\Throwable $exception) {
            unlink($target);
            throw $exception;
        } finally { fclose($source); fclose($destination); }
        try {
            return $this->records->createResource($data, $userId);
        } catch (\Throwable $exception) {
            @unlink($target);
            throw $exception;
        }
    }

    /** @param array<string,mixed> $input */
    public function register(array $input, int $userId): int
    {
        $filename = $this->filename((string) ($input['filename'] ?? ''));
        $path = $this->directory() . '/' . $filename;
        if (!is_file($path) || !is_readable($path)) {
            throw new InvalidArgumentException('That file does not exist in the Resource Library directory.');
        }
        return $this->records->createResource($this->metadata($filename, $input, $path, (int) filesize($path)), $userId);
    }

    public function delete(int $id): void
    {
        $resource = $this->records->resource($id);
        if ($resource === null) {
            throw new InvalidArgumentException('The Resource does not exist.');
        }
        if ((int) ($resource['usage_count'] ?? 0) > 0) {
            throw new InvalidArgumentException('Remove or replace every Course Item reference before deleting this Resource.');
        }
        $this->records->deleteResource($id);
        $path = $this->directory() . '/' . (string) $resource['filename'];
        if (is_file($path) && !unlink($path)) {
            throw new RuntimeException('The Resource record was deleted but its file could not be removed.');
        }
    }

    /** @param array<string,mixed> $resource */
    public function path(array $resource): string
    {
        return $this->directory() . '/' . $this->filename((string) ($resource['filename'] ?? ''));
    }

    /** @return array<string,mixed> */
    public function resourceByPublicId(string $publicId): array
    {
        $resource = $this->records->resourceByPublicId($publicId);
        if ($resource === null) { throw new InvalidArgumentException('The Resource does not exist.'); }
        $resource['path'] = $this->path($resource);
        if (!is_file($resource['path'])) { throw new InvalidArgumentException('The Resource file is unavailable.'); }
        return $resource;
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function metadata(string $filename, array $input, string $source, int $size): array
    {
        $title = trim((string) ($input['title'] ?? ''));
        $type = trim((string) ($input['resource_type'] ?? ''));
        if ($title === '' || !in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException('Resource title and type are required.');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($source) ?: 'application/octet-stream';
        return [
            'title' => $title, 'description' => trim((string) ($input['description'] ?? '')), 'filename' => $filename,
            'original_filename' => trim((string) ($input['original_filename'] ?? '')) ?: $filename,
            'resource_type' => $type, 'mime_type' => $mime, 'byte_size' => max(0, $size), 'updated_at' => date(DATE_ATOM, filemtime($source) ?: time()),
        ];
    }

    private function filename(string $value): string
    {
        if ($value !== basename(str_replace('\\', '/', $value)) || $value !== trim($value)) { throw new InvalidArgumentException('Supply the exact filename without directories or surrounding whitespace.'); }
        if ($value === '' || $value === '.' || $value === '..' || preg_match('/^[A-Za-z0-9][A-Za-z0-9._ -]{0,499}$/', $value) !== 1) {
            throw new InvalidArgumentException('Use a simple filename without directories.');
        }
        return $value;
    }

    private function directory(): string
    {
        $directory = $this->storageRoot . '/course-resources';
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create the Resource Library directory.');
        }
        return $directory;
    }
}
