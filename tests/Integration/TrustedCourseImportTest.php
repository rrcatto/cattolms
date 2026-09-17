<?php

declare(strict_types=1);

namespace CattoLearning\Tests\Integration;

use CattoLearning\Application\CliBootstrap;
use CattoLearning\Course\CoursePortabilityService;
use CattoLearning\Course\CourseRepository;
use CattoLearning\Course\CourseService;
use CattoLearning\Infrastructure\Persistence\Database;
use CattoLearning\Tests\Support\DevelopmentFixture;
use CattoLearning\Tests\Support\TrustedCourseContent;
use CattoLearning\Course\StructuredCourseImporter;
use PHPUnit\Framework\TestCase;

final class TrustedCourseImportTest extends TestCase
{
    public function testStageCommitEditExportAndSvgMediaRetainAuthoredContent(): void
    {
        $boot = CliBootstrap::boot();
        $container = $boot['container'];
        /** @var Database $db */
        $db = $container->get(Database::class);
        /** @var CoursePortabilityService $portability */
        $portability = $container->get(CoursePortabilityService::class);
        /** @var CourseService $service */
        $service = $container->get(CourseService::class);
        /** @var CourseRepository $repository */
        $repository = $container->get(CourseRepository::class);
        $fixture = new DevelopmentFixture($db);
        $suffix = $fixture->suffix();
        $temporary = tempnam(sys_get_temp_dir(), 'trusted-course-');
        self::assertNotFalse($temporary);
        $key = null;
        $mediaPath = null;
        try {
            $owner = $fixture->createUser('Trusted course ' . $suffix);
            $company = $fixture->createCompany($owner, 'Trusted author ' . $suffix, $suffix . '.example.test');
            $course = $fixture->createCourse($owner, $company, 'trusted-' . $suffix, 'Trusted course');
            $fragment = TrustedCourseContent::SVG . '<button onclick="this.dataset.clicked=1">Try</button><script>window.lessonReady=true;</script>';
            $payload = ['course' => ['slug' => 'trusted-' . $suffix, 'title' => 'Trusted course', 'description_html' => $fragment], 'modules' => [['title' => 'Diagram', 'module_key' => 'm1', 'assessment_required' => false, 'content_html' => $fragment, 'summary_html' => $fragment]]];
            file_put_contents($temporary, json_encode($payload, JSON_THROW_ON_ERROR));
            $analysis = $portability->stageImport(['error' => UPLOAD_ERR_OK, 'size' => filesize($temporary), 'tmp_name' => $temporary, 'name' => 'trusted.json'], $owner);
            $key = $analysis['import_key'];
            self::assertSame($fragment, $analysis['modules'][0]['content_html']);
            self::assertSame($course, $portability->commitImport($key, 0, $owner, $course));
            $module = $repository->modules($course)[0];
            self::assertSame($fragment, $module['content_html']);
            $service->updateModule($course, (int) $module['id'], ['module_key' => 'm1', 'position' => 1, 'title' => 'Edited diagram', 'assessment_required' => false, 'content_html' => $fragment, 'summary_html' => $fragment], $owner);
            $service->updateCourse($course, ['title' => 'Trusted course', 'description_html' => $fragment], $owner);
            $portability->updateCertificateTemplate($course, ['certificate_template_html' => TrustedCourseContent::SVG . '<p>{{student_name}}</p>', 'certificate_template_css' => '@import url("https://courses.example.test/certificate.css"); .art{background:url(/art.svg)}'], $owner);
            $export = $portability->exportCourse($course);
            self::assertSame($fragment, $export['course']['description_html']);
            self::assertSame($fragment, (new StructuredCourseImporter())->analyse($export, 'export.json')['modules'][0]['content_blocks'][0]['content_html']);
            self::assertSame($fragment, $export['modules'][0]['blocks'][0]['content_html']);
            self::assertStringContainsString('viewBox=', $export['course']['certificate_template_html']);
            self::assertStringContainsString('url(/art.svg)', $export['course']['certificate_template_css']);
            file_put_contents($temporary, TrustedCourseContent::SVG);
            $media = $service->saveMedia($course, ['error' => UPLOAD_ERR_OK, 'size' => filesize($temporary), 'tmp_name' => $temporary, 'name' => 'diagram.svg'], 'content', 'Gradient diagram', $owner);
            $mediaPath = $boot['instance_root'] . '/storage/course-media/' . $media['storage_key'];
            self::assertSame('image/svg+xml', $media['mime_type']);
            self::assertSame(TrustedCourseContent::SVG, file_get_contents($mediaPath));
        } finally {
            @unlink($temporary);
            if ($key !== null) {
                @unlink($boot['instance_root'] . '/storage/imports/' . $key . '.json');
                @unlink($boot['instance_root'] . '/storage/imports/' . $key . '.meta.json');
            }
            if ($mediaPath !== null) {
                @unlink($mediaPath);
                @rmdir(dirname($mediaPath));
            }
            $fixture->cleanup();
        }
    }
}
