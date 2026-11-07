<?php

declare(strict_types=1);

namespace CattoLearning\Tests\Integration;

use CattoLearning\Application\CliBootstrap;
use CattoLearning\Configuration\SiteMode;
use CattoLearning\Course\CourseService;
use CattoLearning\Course\Sitemap;
use CattoLearning\Course\SitemapRepository;
use CattoLearning\Http\Controller\BundleController;
use CattoLearning\Http\Controller\CourseController;
use CattoLearning\Http\Controller\HelpController;
use CattoLearning\Http\Controller\HomeController;
use CattoLearning\Infrastructure\Persistence\Database;
use CattoLearning\Infrastructure\Persistence\OptionRepository;
use CattoLearning\Kernel;
use CattoLearning\Search\AiCrawlers;
use CattoLearning\Search\LlmsTxt;
use CattoLearning\Search\SearchEnginesAdministration;
use CattoLearning\Search\SearchSettings;
use CattoLearning\Search\SharePictures;
use CattoLearning\Tests\Support\BundleFixture;
use CattoLearning\Tests\Support\DevelopmentFixture;
use CattoLearning\Tests\Support\InProcessPage;
use CattoLearning\Tests\Support\IntegrationContainer;
use CattoLearning\View\ThemeRenderer;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpFoundation\Request;

/**
 * Search engines and AI (owner, 2026-11-06, "2A 3A"): what each public page tells search engines and AI assistants in its
 * head, robots.txt for each AI crawler choice, /llms.txt on a demo and a live site, and the verification codes.
 */
final class SearchEnginesIntegrationTest extends TestCase
{
    private const GOOGLE = 'Ab12_cd-34EFgh56ijKL78mnOP90qrSTuv12wxYZ345';
    private const BING = '0123456789ABCDEF0123456789ABCDEF';

    private Database $db;
    private DevelopmentFixture $fixture;
    /** @var list<string> share pictures this test drew, removed afterwards */
    private array $drawn = [];
    private ?string $appUrl = null;
    private ?string $requestUri = null;

    protected function setUp(): void
    {
        $this->db = IntegrationContainer::db();
        $this->db->beginTransaction();
        $this->fixture = new DevelopmentFixture($this->db);
        $this->appUrl = isset($_ENV['APP_URL']) ? (string) $_ENV['APP_URL'] : null;
        $_ENV['APP_URL'] = 'https://learning.example.test/';
        $this->requestUri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : null;
    }

    protected function tearDown(): void
    {
        if ($this->db->isTransactionActive()) $this->db->rollBack();
        if ($this->appUrl === null) unset($_ENV['APP_URL']); else $_ENV['APP_URL'] = $this->appUrl;
        if ($this->requestUri === null) unset($_SERVER['REQUEST_URI']); else $_SERVER['REQUEST_URI'] = $this->requestUri;
        foreach ($this->drawn as $file) if (is_file($file)) unlink($file);
    }

    /**
     * The structured data in a page's head, each node decoded.
     *
     * @return list<array<string,mixed>>
     */
    private static function nodes(string $html): array
    {
        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $scripts);
        return array_map(static fn(string $json): array => json_decode($json, true, 64, JSON_THROW_ON_ERROR), $scripts[1]);
    }

    /** @return array<string,mixed>|null */
    private static function node(string $html, string $type): ?array
    {
        foreach (self::nodes($html) as $node) if (($node['@type'] ?? '') === $type) return $node;
        return null;
    }

    /** @return array{0:int,1:string,2:string} a published course with a cover and a price: its id, slug and cover */
    private function publishedCourse(string $title = 'Garden Design'): array
    {
        $owner = $this->fixture->createUser('Search engines owner');
        $company = $this->fixture->createCompany($owner, 'Search engines provider ' . $this->fixture->suffix(), $this->fixture->suffix() . '.search.example.test');
        $slug = 'search-engines-' . $this->fixture->suffix();
        $course = $this->fixture->createCourse($owner, $company, $slug, $title, 'published');
        $this->db->executeStatement("UPDATE courses SET summary = 'Plan a garden from the soil up, season by season.', level = 'Beginner' WHERE id = :id", ['id' => $course]);
        $this->fixture->createPriceVariant($course, $owner, 31536000, 250000);
        // A cover of its own, so its share picture is this test's alone and is removed afterwards.
        $cover = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 360" preserveAspectRatio="xMidYMid slice" role="img" aria-label="Rows of seedlings in a raised bed"><rect width="640" height="360" fill="#2f5d3a"/><circle cx="320" cy="180" r="' . (10 + random_int(1, 150)) . '" fill="#c7a14e"/><desc>' . $slug . '</desc></svg>';
        IntegrationContainer::get()->get(CourseService::class)->setCourseCover($course, $cover, $owner);
        $this->remember($cover);
        return [$course, $slug, $cover];
    }

    private function remember(string $cover): void
    {
        $file = glob(CliBootstrap::boot()['instance_root'] . '/storage/share-pictures/*.png') ?: [];
        $version = (string) SharePictures::version($cover);
        foreach ($file as $path) if (str_starts_with(basename($path), $version)) $this->drawn[] = $path;
    }

    public function testACoursePageTellsSearchEnginesWhatItShows(): void
    {
        [, $slug, $cover] = $this->publishedCourse();
        $_SERVER['REQUEST_URI'] = '/courses/' . $slug;
        $page = InProcessPage::run(null, CourseController::class, 'detail', ['slug' => $slug]);
        self::assertSame(200, $page['status']);
        $html = $page['body'];
        $course = self::node($html, 'Course');
        self::assertNotNull($course, 'The course page carries its Course.');
        $url = 'https://learning.example.test/courses/' . $slug;
        self::assertSame('Garden Design', $course['name']);
        self::assertSame('Plan a garden from the soil up, season by season.', $course['description']);
        self::assertSame($url, $course['url']);
        self::assertSame('en-ZA', $course['inLanguage']);
        self::assertSame('Online', $course['hasCourseInstance'][0]['courseMode']);
        self::assertSame('Organization', $course['provider']['@type']);
        self::assertStringStartsWith('Search engines provider', $course['provider']['name'], 'A company\'s course is the company\'s.');
        self::assertSame('2500.00', $course['offers'][0]['price']);
        self::assertSame('ZAR', $course['offers'][0]['priceCurrency']);
        self::assertSame('https://schema.org/InStock', $course['offers'][0]['availability']);
        self::assertArrayNotHasKey('aggregateRating', $course, 'No approved review, no rating.');
        self::assertArrayHasKey('courseCode', $course);
        $trail = self::node($html, 'BreadcrumbList');
        self::assertNotNull($trail, 'The trail the page shows.');
        self::assertSame('https://learning.example.test/', $trail['itemListElement'][0]['item']);

        // The share picture, drawn when the cover was saved, and the full Open Graph set with X's large card.
        $picture = 'https://learning.example.test/share/course/' . $slug . '.png?v=' . SharePictures::version($cover);
        if (IntegrationContainer::get()->get(SharePictures::class)->available()) {
            self::assertStringContainsString('<meta property="og:image" content="' . htmlspecialchars($picture) . '">', $html);
            self::assertStringContainsString('<meta property="og:image:width" content="1200"><meta property="og:image:height" content="630">', $html);
            self::assertStringContainsString('<meta property="og:image:alt" content="Rows of seedlings in a raised bed">', $html);
            self::assertStringContainsString('<meta name="twitter:card" content="summary_large_image">', $html);
            self::assertSame($picture, $course['image']);
            $served = InProcessPage::run(null, \CattoLearning\Http\Controller\SearchEngineController::class, 'coursePicture', ['slug' => $slug]);
            self::assertSame(200, $served['status']);
            self::assertSame('image/png', $served['headers']['content-type'] ?? '');
            self::assertStringContainsString('public', $served['headers']['cache-control'] ?? '', 'Anyone may cache a share picture.');
        }
        foreach (['og:type' => 'website', 'og:title' => 'Garden Design', 'og:url' => $url] as $property => $value) {
            self::assertStringContainsString('<meta property="' . $property . '" content="' . $value . '">', $html);
        }
        self::assertStringContainsString('<link rel="canonical" href="' . $url . '">', $html);
        self::assertStringNotContainsString('google-site-verification', $html, 'The codes are on the home page only.');
    }

    public function testALandingPageIsTheSameCourseHoldingOnlyWhatItsSectionsShow(): void
    {
        [$course, $slug, $cover] = $this->publishedCourse('Hedges and Borders');
        $owner = (int) $this->db->fetchOne('SELECT owner_user_id FROM courses WHERE id = :id', ['id' => $course]);
        $container = IntegrationContainer::get();
        $landing = $container->get(\CattoLearning\Course\Landing\LandingPageService::class);
        $landing->create($course, $owner);
        $page = $landing->page($course);
        self::assertNotNull($page);
        $view = $container->get(\CattoLearning\Course\Landing\LandingPageViewBuilder::class)->build($page, null, false);
        $metadata = $container->get(\CattoLearning\Search\PageMetadata::class);
        $meta = $metadata->landing($view);
        $nodes = array_map(static fn(string $json): array => json_decode($json, true, 64, JSON_THROW_ON_ERROR), $meta['structured_data']);
        self::assertCount(1, $nodes);
        self::assertSame('Course', $nodes[0]['@type']);
        self::assertSame('https://learning.example.test/courses/' . $slug . '#course', $nodes[0]['@id'], 'The same Course as its course page.');
        $types = array_column($view['sections'], 'type');
        self::assertSame(in_array('pricing', $types, true) || in_array('cta', $types, true), isset($nodes[0]['offers']), 'An Offer only where a section shows the price.');
        self::assertSame('Beginner', $nodes[0]['educationalLevel'] ?? null, 'The hero shows the level.');
        self::assertSame('https://learning.example.test' . $view['path'], $meta['canonical']);
        if ($container->get(SharePictures::class)->available()) {
            self::assertStringContainsString('/share/course/' . $slug . '.png?v=' . SharePictures::version($cover), (string) ($meta['og']['image'] ?? ''), 'Shared with its course\'s cover.');
            self::assertSame('summary_large_image', $meta['twitter_card']);
        }
        $preview = $metadata->landing($container->get(\CattoLearning\Course\Landing\LandingPageViewBuilder::class)->build($page, null, true));
        self::assertArrayNotHasKey('structured_data', $preview, 'A preview carries nothing more.');
        self::assertSame('noindex, nofollow', $preview['robots']);
    }

    public function testADraftCourseHasNoSharePicture(): void
    {
        $owner = $this->fixture->createUser('Search engines draft');
        $company = $this->fixture->createCompany($owner, 'Search engines draft ' . $this->fixture->suffix(), $this->fixture->suffix() . '.draft.example.test');
        $slug = 'search-draft-' . $this->fixture->suffix();
        $this->fixture->createCourse($owner, $company, $slug, 'Draft course', 'draft');
        $this->expectException(\Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);
        InProcessPage::run(null, \CattoLearning\Http\Controller\SearchEngineController::class, 'coursePicture', ['slug' => $slug]);
    }

    public function testABundlePageIsAProductWithItsCourses(): void
    {
        [$first] = $this->publishedCourse('Soil Science');
        [$second] = $this->publishedCourse('Pruning');
        [$third] = $this->publishedCourse('Irrigation');
        $owner = $this->fixture->createUser('Search engines bundle owner');
        $bundle = BundleFixture::create($this->db, $owner, [$first, $second, $third], 400000);
        $page = InProcessPage::run(null, BundleController::class, 'show', ['slug' => $bundle['slug']]);
        self::assertSame(200, $page['status']);
        $product = self::node($page['body'], 'Product');
        self::assertNotNull($product);
        self::assertSame($bundle['title'], $product['name']);
        self::assertSame('4000.00', $product['offers']['price']);
        self::assertSame('ZAR', $product['offers']['priceCurrency']);
        $list = self::node($page['body'], 'ItemList');
        self::assertNotNull($list, 'Its courses, each by its own page.');
        self::assertCount(3, $list['itemListElement']);
        self::assertStringContainsString('<meta name="twitter:card"', $page['body']);
    }

    public function testTheFaqMarksUpEveryQuestionItShowsAndNothingElse(): void
    {
        $_SERVER['REQUEST_URI'] = '/faq';
        $page = InProcessPage::run(null, HelpController::class, 'faq');
        self::assertSame(200, $page['status']);
        $faq = self::node($page['body'], 'FAQPage');
        self::assertNotNull($faq);
        $shown = substr_count($page['body'], '<details class="cl-ui-accordion-section"');
        self::assertCount($shown, $faq['mainEntity'], 'One question marked up for each the page shows.');
        foreach ($faq['mainEntity'] as $question) {
            self::assertStringContainsString('<span class="cl-ui-accordion-title">' . htmlspecialchars((string) $question['name'], ENT_QUOTES) . '</span>', $page['body']);
        }
        self::assertNotNull(self::node($page['body'], 'BreadcrumbList'));
    }

    public function testTheHomePageNamesTheBusinessTheSiteAndCarriesTheCodes(): void
    {
        $options = new OptionRepository($this->db);
        $settings = new SearchSettings($options);
        $actor = $this->fixture->createUser('Search engines settings');
        $settings->save(['ai_crawlers' => 'all', 'google' => '<meta name="google-site-verification" content="' . self::GOOGLE . '" />', 'bing' => self::BING], $actor);
        $_SERVER['REQUEST_URI'] = '/';
        $html = InProcessPage::run(null, HomeController::class, 'index')['body'];
        self::assertStringContainsString('<meta name="google-site-verification" content="' . self::GOOGLE . '">', $html);
        self::assertStringContainsString('<meta name="msvalidate.01" content="' . self::BING . '">', $html);
        $organisation = self::node($html, 'Organization');
        self::assertNotNull($organisation);
        self::assertSame('https://learning.example.test/', $organisation['url']);
        self::assertSame('https://learning.example.test/img/favicon-512x512.png', $organisation['logo']);
        self::assertSame('https://learning.example.test/contact', $organisation['contactPoint'][0]['url']);
        self::assertArrayNotHasKey('sameAs', $organisation, 'The footer links to platforms\' home pages, not the business\'s profiles.');
        $site = self::node($html, 'WebSite');
        self::assertNotNull($site);
        self::assertSame('https://learning.example.test/', $site['url']);
        self::assertNull(self::node($html, 'BreadcrumbList'), 'The home page has no trail.');
        self::assertMatchesRegularExpression('#<meta property="og:image" content="https://learning\.example\.test/img/hero-learning-1200x630\.png\?v=[0-9a-f]{12}">#', $html);
        self::assertFileExists(dirname(__DIR__, 2) . '/public_html/img/hero-learning-1200x630.png');
    }

    public function testAPageThatIsNotPublicCarriesNoStructuredData(): void
    {
        $renderer = IntegrationContainer::get()->get(ThemeRenderer::class);
        $_SERVER['REQUEST_URI'] = '/admin/settings';
        $meta = ['structured_data' => ['{"@context":"https://schema.org","@type":"Thing"}'], 'verification' => ['msvalidate.01' => self::BING]];
        foreach (['admin-section', 'account-section', 'learn-item', 'course-public-preview', 'login', 'error'] as $template) {
            $model = $renderer->viewModel($template, ['page_meta' => $meta], 'factory-reset', false);
            self::assertSame([], $model['meta']['structured_data'], $template);
            self::assertSame([], $model['meta']['verification'], $template);
        }
        // A public page that asks not to be indexed (an ADMIN preview) carries none either.
        $_SERVER['REQUEST_URI'] = '/landing/a-preview';
        $preview = $renderer->viewModel('landing-page', ['page_meta' => $meta + ['robots' => 'noindex, nofollow'], 'title' => 'Preview'], 'factory-reset', false);
        self::assertSame([], $preview['meta']['structured_data']);
    }

    public function testRobotsTxtFollowsEachAiCrawlerChoice(): void
    {
        $repository = IntegrationContainer::get()->get(SitemapRepository::class);
        $groups = static function (string $robots): array {
            $groups = [];
            $agent = null;
            foreach (explode("\n", $robots) as $line) {
                if (str_starts_with($line, 'User-agent: ')) { $agent = substr($line, 12); $groups[$agent] = []; continue; }
                if ($agent !== null && preg_match('/^(Allow|Disallow): (.*)$/', $line, $rule) === 1) $groups[$agent][] = $rule[1] . ' ' . $rule[2];
                if ($line === '') $agent = null;
            }
            return $groups;
        };
        foreach ([SearchSettings::ALLOW_ALL, SearchSettings::SEARCH_ONLY, SearchSettings::BLOCK_ALL] as $choice) {
            $robots = (new Sitemap($repository, SiteMode::fixed(SiteMode::DEMO), new ArrayAdapter(), Sitemap::LIMIT, SearchSettings::fixed(['ai_crawlers' => $choice])))->robots();
            $byAgent = $groups($robots);
            self::assertNotContains('Disallow /', $byAgent['*'], 'Search engines are never kept out: ' . $choice);
            self::assertContains('Disallow /admin/', $byAgent['*']);
            foreach (AiCrawlers::all() as $token => $crawler) {
                self::assertArrayHasKey($token, $byAgent, $token . ' has a group of its own.');
                if (AiCrawlers::allowed($crawler['kind'], $choice)) {
                    self::assertContains('Allow /', $byAgent[$token], $token . ' may come in: ' . $choice);
                    self::assertContains('Disallow /admin/', $byAgent[$token], $token . ' still keeps out of private pages.');
                    self::assertNotContains('Disallow /', $byAgent[$token]);
                } else {
                    self::assertSame(['Disallow /'], $byAgent[$token], $token . ' is kept out: ' . $choice);
                }
            }
            self::assertStringNotContainsString('llms.txt', $robots, 'A demo site has no llms.txt.');
        }
        $search = $groups((new Sitemap($repository, SiteMode::fixed(SiteMode::DEMO), new ArrayAdapter(), Sitemap::LIMIT, SearchSettings::fixed(['ai_crawlers' => SearchSettings::SEARCH_ONLY])))->robots());
        self::assertSame(['Disallow /'], $search['GPTBot'], 'A training crawler is kept out.');
        self::assertContains('Allow /', $search['OAI-SearchBot'], 'An AI search crawler comes in.');
        self::assertContains('Allow /', $search['ChatGPT-User'], 'An assistant reading a page for a person comes in.');
        $live = (new Sitemap($repository, SiteMode::fixed(SiteMode::LIVE), new ArrayAdapter()))->robots();
        self::assertStringContainsString("Sitemap: https://learning.example.test/sitemap.xml\n", $live);
        self::assertStringContainsString('https://learning.example.test/llms.txt', $live, 'A live site names its llms.txt.');
    }

    public function testLlmsTxtIsALiveSitesOnly(): void
    {
        [, $slug] = $this->publishedCourse('Compost Basics');
        $container = IntegrationContainer::get();
        $make = static fn(string $mode): LlmsTxt => new LlmsTxt(SiteMode::fixed($mode), $container->get(\CattoLearning\Configuration\SiteOwner::class), $container->get(\CattoLearning\Configuration\RuntimeSettings::class), $container->get(\CattoLearning\Search\SearchRepository::class), $container->get(\CattoLearning\Legal\LegalDocuments::class));
        self::assertNull($make(SiteMode::DEMO)->document(), 'A demo site has none.');
        $document = (string) $make(SiteMode::LIVE)->document();
        $lines = explode("\n", $document);
        self::assertMatchesRegularExpression('/^# \S/', $lines[0], 'It opens with the site\'s name as its one H1.');
        self::assertSame('', $lines[1]);
        self::assertStringStartsWith('> ', $lines[2], 'Then its summary in a quote.');
        self::assertSame(1, preg_match_all('/^# /m', $document));
        self::assertStringContainsString("\n## Courses\n\n", $document);
        self::assertStringContainsString('- [Compost Basics](https://learning.example.test/courses/' . $slug . '): Plan a garden from the soil up, season by season.', $document);
        foreach (['/help', '/faq', '/terms', '/refunds', '/privacy'] as $path) self::assertStringContainsString('(https://learning.example.test' . $path . ')', $document);
        self::assertStringNotContainsString('<', $document, 'Plain Markdown, no HTML.');
    }

    public function testTheRoutesAnswerAsTheSiteModeSays(): void
    {
        $this->db->rollBack();
        $previous = $_ENV['APP_SITE_MODE'] ?? null;
        try {
            $_ENV['APP_SITE_MODE'] = 'demo';
            self::assertSame(404, $this->send('/llms.txt')->getStatusCode());
            $_ENV['APP_SITE_MODE'] = 'live';
            $llms = $this->send('/llms.txt');
            // The site's own Settings may hold a site mode that overrides .env; only a live one answers.
            if ((new SiteMode(new OptionRepository(IntegrationContainer::db())))->isDemo()) self::markTestSkipped('This site\'s Settings make it a demo site.');
            self::assertSame(200, $llms->getStatusCode());
            self::assertStringStartsWith('text/plain', (string) $llms->headers->get('Content-Type'));
            self::assertStringStartsWith('# ', (string) $llms->getContent());
        } finally {
            if ($previous === null) unset($_ENV['APP_SITE_MODE']); else $_ENV['APP_SITE_MODE'] = $previous;
            $this->db->beginTransaction();
        }
    }

    public function testACodeThatIsNotACodeIsRefusedAndNothingIsSaved(): void
    {
        $actor = $this->fixture->createUser('Search engines refusal');
        $administration = IntegrationContainer::get()->get(SearchEnginesAdministration::class);
        $settings = IntegrationContainer::get()->get(SearchSettings::class);
        $before = $settings->saved();
        foreach ([['ai_crawlers' => 'all', 'google' => 'not a code at all', 'bing' => ''], ['ai_crawlers' => 'some', 'google' => '', 'bing' => ''], ['ai_crawlers' => 'all', 'google' => '<meta name="msvalidate.01" content="' . self::BING . '">', 'bing' => '']] as $wrong) {
            try {
                $administration->save($wrong, $actor);
                self::fail('Refused: ' . json_encode($wrong));
            } catch (InvalidArgumentException) {
                self::assertSame($before, $settings->saved(), 'Nothing saved.');
            }
        }
        $administration->save(['ai_crawlers' => 'none', 'google' => self::GOOGLE, 'bing' => ''], $actor);
        self::assertSame(['ai_crawlers' => 'none', 'google' => self::GOOGLE, 'bing' => ''], $settings->saved());
        self::assertSame(1, (int) $this->db->fetchOne("SELECT COUNT(*) FROM audit_log WHERE user_id = :actor AND event_key = 'platform.search_engines_changed'", ['actor' => $actor]), 'The change is in the Activity log.');
        $view = $administration->view();
        self::assertSame('none', $view['ai_crawlers']);
        self::assertStringStartsWith('No AI crawler may come in.', $view['summary']);
    }

    private function send(string $path): \Symfony\Component\HttpFoundation\Response
    {
        $kernel = new Kernel('test', true, CliBootstrap::boot()['instance_root']);
        try {
            return $kernel->handle(Request::create($path));
        } finally {
            $kernel->shutdown();
        }
    }
}
