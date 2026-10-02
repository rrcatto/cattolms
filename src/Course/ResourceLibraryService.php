<?php

declare(strict_types=1);

namespace CattoLearning\Course;

use InvalidArgumentException;
use RuntimeException;

/** Manages immutable files in the single Resource Library directory and their database records. */
final class ResourceLibraryService
{
    public const TYPES = ['pdf','image_graphic','uploaded_video','audio','markdown','document','archive','file'];

    /** Readable names for common downloadable formats, keyed by lower-case extension. Anything else is "EXT file". */
    private const FORMAT_LABELS = [
        'zip' => 'ZIP archive', '7z' => '7-Zip archive', 'rar' => 'RAR archive', 'tar' => 'TAR archive', 'gz' => 'GZip archive', 'tgz' => 'GZip archive',
        'pdf' => 'PDF document', 'doc' => 'Word document', 'docx' => 'Word document', 'odt' => 'OpenDocument text', 'rtf' => 'Rich Text document', 'txt' => 'Text file',
        'xls' => 'Excel workbook', 'xlsx' => 'Excel workbook', 'ods' => 'OpenDocument spreadsheet', 'csv' => 'CSV file',
        'ppt' => 'PowerPoint presentation', 'pptx' => 'PowerPoint presentation', 'odp' => 'OpenDocument presentation',
    ];
    private const ARCHIVE_EXTENSIONS = ['zip', '7z', 'rar', 'tar', 'gz', 'tgz'];
    private const DOCUMENT_EXTENSIONS = ['doc', 'docx', 'odt', 'rtf', 'txt', 'xls', 'xlsx', 'ods', 'csv', 'ppt', 'pptx', 'odp'];

    /** @var \Closure(string):bool */
    private readonly \Closure $isUploadedFile;

    /** @param (\Closure(string):bool)|null $isUploadedFile tests replace PHP's upload check, which is always false on the CLI */
    public function __construct(private readonly CourseItemRepository $records, private readonly string $storageRoot, ?\Closure $isUploadedFile = null)
    {
        $this->isUploadedFile = $isUploadedFile ?? static fn(string $path): bool => is_uploaded_file($path);
    }

    /** The Resource classification for a file added without one: archives, documents, PDFs, otherwise a general file. */
    public static function classify(string $filename): string
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return match (true) {
            in_array($extension, self::ARCHIVE_EXTENSIONS, true) => 'archive',
            $extension === 'pdf' => 'pdf',
            in_array($extension, self::DOCUMENT_EXTENSIONS, true) => 'document',
            default => 'file',
        };
    }

    /** "ZIP archive", "Word document" or, for an unlisted extension, "EXT file". */
    public static function formatLabel(string $filename): string
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return self::FORMAT_LABELS[$extension] ?? ($extension === '' ? 'File' : strtoupper($extension) . ' file');
    }

    /** A file size for learners: bytes below 1 KB, then KB, MB and GB to one decimal place. */
    public static function sizeLabel(int $bytes): string
    {
        if ($bytes < 1024) { return $bytes . ' bytes'; }
        $units = ['KB', 'MB', 'GB'];
        $value = $bytes / 1024;
        $unit = 0;
        while ($value >= 1024 && $unit < count($units) - 1) { $value /= 1024; $unit++; }
        return rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.') . ' ' . $units[$unit];
    }

    /** @return list<array<string,mixed>> */
    public function library(string $search = '', string $type = ''): array
    {
        if ($type !== '' && !in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException('Select a valid Resource type.');
        }
        $resources = $this->records->resources(trim($search), $type);
        foreach ($resources as &$resource) {
            $resource['usage'] = $this->records->resourceUsage((int) $resource['id']);
            $resource['size_label'] = self::sizeLabel((int) $resource['byte_size']);
        }
        unset($resource);
        return $resources;
    }

    /**
     * @param array<string,mixed> $upload
     * @param array<string,mixed> $input
     */
    public function upload(array $upload, array $input, int $userId): int
    {
        if ((int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !($this->isUploadedFile)((string) ($upload['tmp_name'] ?? ''))) {
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

    /**
     * Course Items using this file, as its own file or as a video poster or captions.
     * @return list<array<string,mixed>>
     */
    public function usage(int $id): array
    {
        return $this->records->resourceUsage($id);
    }

    /**
     * One Resource record with its usage count, or null.
     * @return array<string,mixed>|null
     */
    public function resource(int $id): ?array
    {
        return $id > 0 ? $this->records->resource($id) : null;
    }

    /**
     * Change a Resource's title, description or classification. The file is immutable. A new
     * classification must still suit every Course Item that uses the file as its own.
     *
     * @param array<string,mixed> $input
     */
    public function update(int $id, array $input): void
    {
        $resource = $this->records->resource($id);
        if ($resource === null) { throw new InvalidArgumentException('The Resource does not exist.'); }
        $title = trim((string) ($input['title'] ?? ''));
        $type = trim((string) ($input['resource_type'] ?? ''));
        if ($title === '' || mb_strlen($title) > 240 || !in_array($type, self::TYPES, true)) {
            throw new InvalidArgumentException('Resource title and type are required.');
        }
        if ($this->records->resourceTitleTaken($title, $id)) {
            throw new InvalidArgumentException('Another Resource is already titled "' . $title . '".');
        }
        foreach ($this->records->resourcePrimaryUsage($id) as $item) {
            if (!CourseItemService::acceptsResource((string) $item['item_type'], $type)) {
                throw new InvalidArgumentException('“' . $item['title'] . '” is a ' . CourseItemService::TYPE_LABELS[(string) $item['item_type']] . ' item and cannot use a ' . str_replace('_', ' ', $type) . ' Resource.');
            }
        }
        $this->records->updateResource($id, $title, trim((string) ($input['description'] ?? '')), $type);
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
        if ($this->records->resourceTitleTaken($title)) {
            throw new InvalidArgumentException('Another Resource is already titled "' . $title . '".');
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
