<?php

declare(strict_types=1);

namespace CattoLearning\Tests\Unit;

use CattoLearning\Course\ResourceLibraryService;
use CattoLearning\Http\FileDownload;
use PHPUnit\Framework\TestCase;

/** Guards how a protected file is sent and described to learners. */
final class FileDownloadTest extends TestCase
{
    public function testAFileIsSentAsAPrivateAttachmentWithASafeFilename(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'dl');
        self::assertIsString($path);
        file_put_contents($path, 'PK');
        try {
            $response = FileDownload::attachment($path, '../../Précédents "final".zip', 'application/zip');
            $disposition = (string) $response->headers->get('Content-Disposition');
            self::assertStringStartsWith('attachment;', $disposition);
            self::assertStringContainsString('filename="Pr_c_dents final.zip"', $disposition, 'ASCII fallback without path or quotes.');
            self::assertStringContainsString("filename*=utf-8''Pr%C3%A9c%C3%A9dents%20final.zip", $disposition);
            self::assertSame('application/zip', $response->headers->get('Content-Type'));
            self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
            self::assertTrue($response->headers->hasCacheControlDirective('private'));
            self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
            self::assertSame('application/octet-stream', FileDownload::attachment($path, 'x.bin', '')->headers->get('Content-Type'));
        } finally {
            unlink($path);
        }
        self::assertSame('download', FileDownload::safeFilename('../'));
    }

    public function testFilesAreDescribedByFormatAndSize(): void
    {
        self::assertSame('ZIP archive', ResourceLibraryService::formatLabel('conveyancing-templates.zip'));
        self::assertSame('Word document', ResourceLibraryService::formatLabel('deed.DOCX'));
        self::assertSame('STL file', ResourceLibraryService::formatLabel('model.stl'));
        self::assertSame('4.8 MB', ResourceLibraryService::sizeLabel(5033165));
        self::assertSame('512 bytes', ResourceLibraryService::sizeLabel(512));
        self::assertSame('1.5 KB', ResourceLibraryService::sizeLabel(1536));
        self::assertSame('archive', ResourceLibraryService::classify('pack.tar.gz'));
        self::assertSame('document', ResourceLibraryService::classify('notes.docx'));
    }
}
