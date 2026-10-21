<?php

declare(strict_types=1);

namespace CattoLearning\Tests\Integration;

use CattoLearning\Http\Controller\CourseController;
use CattoLearning\Http\Controller\HelpController;
use CattoLearning\Infrastructure\Persistence\Database;
use CattoLearning\Tests\Support\{DevelopmentFixture, InProcessPage, IntegrationContainer};
use PHPUnit\Framework\TestCase;

/**
 * The course page and the FAQ (owner, 2026-10-20): a large cover picture at the top of a course's page
 * ("these course pages must look interesting"), access that starts "Immediately", no Reviews section
 * while there is no review, grading moved to the FAQ, and an FAQ whose answers stay closed until their
 * question is chosen, each question with an icon and its category above it.
 */
final class CoursePageAndFaqIntegrationTest extends TestCase
{
    private const COVER = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 360" preserveAspectRatio="xMidYMid slice"><rect width="640" height="360" fill="#0f3d3e"/></svg>';

    private Database $db;
    private DevelopmentFixture $fixture;

    protected function setUp(): void
    {
        $this->db = IntegrationContainer::db();
        $this->db->beginTransaction();
        $this->fixture = new DevelopmentFixture($this->db);
    }

    protected function tearDown(): void
    {
        if ($this->db->isTransactionActive()) $this->db->rollBack();
    }

    public function testACoursePageOpensWithItsCoverAndLeavesOutGradingAndEmptyReviews(): void
    {
        $owner = $this->fixture->createUser('Course page owner');
        $company = $this->fixture->createCompany($owner, 'Course page provider ' . $this->fixture->suffix(), $this->fixture->suffix() . '.page.example.test');
        $slug = 'course-page-' . $this->fixture->suffix();
        $course = $this->fixture->createCourse($owner, $company, $slug, 'Course page course', 'published');
        $this->db->executeStatement('UPDATE courses SET cover_svg = :cover WHERE id = :id', ['cover' => self::COVER, 'id' => $course]);
        $page = InProcessPage::run(null, CourseController::class, 'detail', ['slug' => $slug]);
        self::assertSame(200, $page['status']);
        $html = $page['body'];
        self::assertStringContainsString('<div class="cl-picture-head-art" aria-hidden="true">' . self::COVER . '</div>', $html, 'The cover picture, large, at the top.');
        self::assertMatchesRegularExpression('#<div class="cl-picture-head-text">.*<h1 class="cl-display-4 fw-bold">\s*Course page course\s*</h1>#s', $html, 'The title sits over the picture.');
        self::assertSame(1, substr_count($html, '<h1'), 'One title.');
        self::assertMatchesRegularExpression('#<dt>Access starts</dt><dd>Immediately</dd>#', $html);
        self::assertStringNotContainsString('When you start', $html);
        self::assertStringNotContainsString('id="reviews"', $html, 'No Reviews section while the course has no review.');
        self::assertStringNotContainsString('No reviews yet', $html);
        self::assertStringNotContainsString('>Grading<', $html, 'Grading is in the FAQ.');
        self::assertStringContainsString('href="/faq#grading"', $html);
    }

    public function testTheFaqKeepsEachAnswerClosedUnderItsIconCategoryAndQuestion(): void
    {
        $page = InProcessPage::run(null, HelpController::class, 'faq');
        self::assertSame(200, $page['status']);
        $html = $page['body'];
        $questions = substr_count($html, '<details class="cl-ui-accordion-section"');
        self::assertGreaterThanOrEqual(10, $questions);
        self::assertSame(0, preg_match('#<details class="cl-ui-accordion-section"[^>]*\bopen\b#', $html), 'Every answer starts closed.');
        self::assertSame($questions, substr_count($html, '<summary class="cl-ui-accordion-summary cl-ui-accordion-summary--labelled"><span class="cl-ui-accordion-icon" aria-hidden="true"><svg'), 'Each question opens with its icon.');
        self::assertSame($questions, substr_count($html, '<span class="cl-ui-accordion-eyebrow">'), 'Each question has its category above it.');
        self::assertStringContainsString('id="grading"', $html);
        self::assertStringContainsString('<span class="cl-ui-accordion-eyebrow">Grading</span><span class="cl-ui-accordion-title">How is my result worked out?</span>', $html);
        foreach (['75% and above', 'Below 50%', 'Do I need to pass to get a certificate?', 'Immediately.'] as $answer) self::assertStringContainsString($answer, $html);
        self::assertStringContainsString('href="/faq"', InProcessPage::run(null, HelpController::class, 'index')['body'], 'Help links to the FAQ.');
    }

    /**
     * Header pictures (owner, 2026-10-20: "give the FAQ page a header svg graphic too … These header SVG graphics
     * should all be the same size"; "header graphics for the Catalogue page … and also the Browse by Tag page"):
     * each page's own picture in the one picture head, its title over it and the only h1.
     */
    public function testEveryPageWithAPictureOpensWithItsOwnUnderTheTitle(): void
    {
        $artwork = dirname(__DIR__, 2) . '/resources/views/partials/artwork/';
        $pages = [
            'Help' => [InProcessPage::run(null, HelpController::class, 'index')['body'], 'help-hero'],
            'FAQ' => [InProcessPage::run(null, HelpController::class, 'faq')['body'], 'faq-hero'],
            'Catalogue' => [InProcessPage::run(null, CourseController::class, 'catalogue')['body'], 'catalogue-hero'],
            // The legal pages and the contact form too (owner, 2026-10-20: "all get svg header images").
            'Terms' => [InProcessPage::run(null, \CattoLearning\Http\Controller\LegalController::class, 'terms')['body'], 'terms-hero'],
            'Refunds' => [InProcessPage::run(null, \CattoLearning\Http\Controller\LegalController::class, 'refunds')['body'], 'refunds-hero'],
            'Privacy' => [InProcessPage::run(null, \CattoLearning\Http\Controller\LegalController::class, 'privacy')['body'], 'privacy-hero'],
            'Contact' => [InProcessPage::run(null, \CattoLearning\Http\Controller\ContactController::class, 'index')['body'], 'contact-hero'],
        ];
        foreach ($pages as $name => [$html, $picture]) {
            $source = (string) file_get_contents($artwork . $picture . '.html.twig');
            $svg = trim(substr($source, (int) strpos($source, '<svg')));
            self::assertMatchesRegularExpression('#<section class="cl-page-head  cl-page-head--picture">\s*<div class="cl-page-head-inner">\s*<div class="cl-picture-head"><div class="cl-picture-head-art" aria-hidden="true">#', $html, $name);
            self::assertStringContainsString($svg, $html, $name . ' shows its own picture.');
            self::assertMatchesRegularExpression('#<div class="cl-page-head-content cl-picture-head-text">.*?<h1 #s', $html, $name . ': the title sits over it.');
            self::assertSame(1, substr_count($html, '<h1'), $name);
        }
        self::assertStringContainsString("partials/artwork/tags-hero.html.twig", (string) file_get_contents(dirname(__DIR__, 2) . '/resources/views/pages/course-tags.html.twig'), 'The tag browser has its own picture.');
        self::assertCount(8, array_unique(array_map(static fn(string $f): string => (string) md5_file($f), glob($artwork . '*-hero.html.twig') ?: [])), 'Eight different pictures.');
    }
}
