<?php

declare(strict_types=1);

namespace CattoLearning\Http;

use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/** A protected file sent as an attachment: never rendered inline, never cached by a shared cache. */
final class FileDownload
{
    public static function attachment(string $path, string $filename, string $mimeType): BinaryFileResponse
    {
        $name = self::safeFilename($filename);
        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', $mimeType !== '' ? $mimeType : 'application/octet-stream');
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $name, self::asciiFallback($name));
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');
        return $response;
    }

    /** The original filename without any path, control characters or quotes. */
    public static function safeFilename(string $filename): string
    {
        $name = basename(str_replace('\\', '/', $filename));
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F"]+/u', '', $name));
        return $name === '' || $name === '.' || $name === '..' ? 'download' : $name;
    }

    /** A plain-ASCII version for clients that ignore the UTF-8 filename* parameter. */
    public static function asciiFallback(string $filename): string
    {
        $ascii = (string) preg_replace('/[^\x20-\x7E]/u', '_', $filename);
        $ascii = str_replace(['%', '/', '\\'], '_', $ascii);
        return $ascii === '' ? 'download' : $ascii;
    }
}
