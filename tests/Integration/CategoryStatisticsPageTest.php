<?php

declare(strict_types=1);

namespace CattoLearning\Tests\Integration;

use CattoLearning\Application\CliBootstrap;
use CattoLearning\Infrastructure\Persistence\AuthSessionRepository;
use CattoLearning\Infrastructure\Persistence\Database;
use CattoLearning\Kernel;
use CattoLearning\Support\Env;
use CattoLearning\Support\Token;
use CattoLearning\Tests\Support\IntegrationContainer;
use CattoLearning\Tests\Support\IntegrationDataFixture;
use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * The category management page shows the taxonomy's counts above the category tree, and they agree
 * with the categories table walked along parent_id.
 */
final class CategoryStatisticsPageTest extends TestCase
{
    private Database $db;
    private IntegrationDataFixture $fixture;
    /** @var array<string,mixed> */
    private array $cookies = [];

    protected function setUp(): void
    {
        $container = IntegrationContainer::get();
        $this->db = IntegrationContainer::db();
        $this->fixture = new IntegrationDataFixture($this->db);
        $suffix = $this->fixture->suffix();
        $admin = $this->fixture->createUser('Category admin ' . $suffix, 'category-admin-' . $suffix . '@seed.test');
        $this->fixture->grantRole($admin, 'ADMIN');
        $this->fixture->attachToSystemCompany($admin);
        // One branch of each depth, so the page is never counting an empty taxonomy.
        $root = $this->fixture->createCategory('Stats Root ' . $suffix, 'stats-root-' . $suffix);
        $branch = $this->fixture->createCategory('Stats Branch ' . $suffix, 'stats-branch-' . $suffix, $root);
        $this->fixture->createCategory('Stats Leaf ' . $suffix, 'stats-leaf-' . $suffix, $branch);
        $token = Token::generate();
        $container->get(AuthSessionRepository::class)->create($admin, Token::hash($token), 3600, 'qa', 'PHPUnit');
        $this->cookies = $_COOKIE;
        $_COOKIE[Env::string('AUTH_SESSION_COOKIE', 'catto_learning_session')] = $token;
    }

    protected function tearDown(): void
    {
        $_COOKIE = $this->cookies;
        $this->fixture->cleanup();
    }

    public function testThePageShowsTheTaxonomyCountsAboveTheTree(): void
    {
        $kernel = new Kernel('test', true, CliBootstrap::boot()['instance_root']);
        try {
            $response = $kernel->handle(Request::create('/admin/courses/categories', 'GET'));
        } finally {
            $kernel->shutdown();
        }
        self::assertSame(200, $response->getStatusCode());
        $html = (string) $response->getContent();
        $document = new DOMDocument();
        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8"?>' . $html);
        libxml_clear_errors();
        $page = new DOMXPath($document);
        $shown = [];
        foreach ($page->query('//div[contains(@class, "cl-ui-stat-card")]') as $card) {
            $label = trim($page->query('.//div[contains(@class, "cl-ui-stat-label")]', $card)->item(0)->textContent ?? '');
            $shown[$label] = (int) trim($page->query('.//div[contains(@class, "cl-ui-stat-value")]', $card)->item(0)->textContent ?? '');
        }
        $depths = [];
        foreach ($this->db->fetchAllAssociative('WITH RECURSIVE walk AS (SELECT id, 1 AS depth FROM course_categories WHERE parent_id IS NULL UNION ALL SELECT c.id, w.depth + 1 FROM course_categories c JOIN walk w ON c.parent_id = w.id WHERE w.depth < 4) SELECT depth, count(*) AS categories FROM walk GROUP BY depth') as $row) {
            $depths[(int) $row['depth']] = (int) $row['categories'];
        }
        $total = (int) $this->db->fetchOne('SELECT count(*) FROM course_categories');
        $withCourses = (int) $this->db->fetchOne('SELECT count(*) FROM course_categories cc WHERE EXISTS (SELECT 1 FROM courses c WHERE c.category_id = cc.id)');
        self::assertSame([
            'Main categories' => (int) ($depths[1] ?? 0),
            'Subcategories' => (int) ($depths[2] ?? 0),
            'Sub-subcategories' => (int) ($depths[3] ?? 0),
            'Total categories' => $total,
            'With courses' => $withCourses,
            'Empty categories' => $total - $withCourses,
        ], $shown);
        self::assertArrayNotHasKey(4, $depths, 'The development taxonomy keeps to three levels.');
        self::assertSame($total, $shown['Main categories'] + $shown['Subcategories'] + $shown['Sub-subcategories']);
        self::assertStringNotContainsString('Categories outside the three-level hierarchy', $html);
        self::assertLessThan(strpos($html, 'id="category-tree"'), strpos($html, 'cl-ui-stat-grid'), 'The counts sit above the tree.');
        self::assertStringContainsString('data-controller="category-tree"', $html, 'The tree is the sortable category tree.');
        self::assertStringContainsString('Stats Leaf', $html);
    }
}
