<?php

declare(strict_types=1);

namespace CattoLearning\Tests\Integration;

use CattoLearning\Course\CatalogueFilter;
use CattoLearning\Course\CourseRepository;
use CattoLearning\Course\CourseService;
use CattoLearning\Infrastructure\Persistence\Database;
use CattoLearning\Tests\Support\IntegrationContainer;
use CattoLearning\Tests\Support\IntegrationDataFixture;
use PHPUnit\Framework\TestCase;

/** Exercises direct category navigation, descendant search and tag filtering against PostgreSQL. */
final class CatalogueBrowseIntegrationTest extends TestCase
{
    private Database $db;
    private IntegrationDataFixture $fixture;
    private CourseRepository $courses;
    private CourseService $service;
    private int $rootId = 0;
    private int $branchId = 0;
    private int $leafId = 0;
    private string $rootSlug = '';
    private int $tagId = 0;
    private string $tagSlug = '';
    /** @var list<int> */
    private array $seedCourseIds = [];

    protected function setUp(): void
    {
        $container = IntegrationContainer::get();
        $this->db = IntegrationContainer::db();
        $this->fixture = new IntegrationDataFixture($this->db);
        $this->courses = $container->get(CourseRepository::class);
        $this->service = $container->get(CourseService::class);
        $suffix = $this->fixture->suffix();
        $this->rootSlug = 'browse-root-' . $suffix;

        $this->rootId = $this->fixture->createCategory('Browse Root ' . $suffix, $this->rootSlug);
        $this->branchId = $this->fixture->createCategory('Browse Branch ' . $suffix, 'browse-branch-' . $suffix, $this->rootId);
        $this->leafId = $this->fixture->createCategory('Browse Leaf ' . $suffix, 'browse-leaf-' . $suffix, $this->branchId);

        $owner = $this->fixture->createUser('Browse Owner ' . $suffix, 'browse-owner-' . $suffix . '@seed.test');
        $company = $this->fixture->createCompany($owner, 'Browse Company ' . $suffix, 'browse-' . $suffix . '.seed.test');
        $this->fixture->addCompanyMember($company, $owner);

        // One course at each level and a second leaf course test exact and descendant membership.
        foreach ([$this->rootId, $this->branchId, $this->leafId] as $index => $categoryId) {
            $courseId = $this->fixture->createCourse($owner, $company, 'browse-seed-' . $index . '-' . $suffix, 'Browse Seed Course');
            $this->file($courseId, $categoryId);
            $this->seedCourseIds[] = $courseId;
        }

        // A published course filed under nothing at all. It exists so the distribution's
        // uncategorised remainder is non-zero: with every course categorised, a distribution that
        // silently dropped the remainder would still add up, and the assertion below would pass on a
        // query that had stopped counting it.
        $this->fixture->createCourse($owner, $company, 'browse-orphan-' . $suffix, 'Browse Orphan Course');

        // One tag on two of the three, so the tag facet is narrower than the branch and the two can
        // be told apart when they are combined.
        $this->tagSlug = 'browse-tag-' . $suffix;
        $this->tagId = (int) $this->db->fetchAllAssociative(
            'INSERT INTO tags (name, slug) VALUES (:name, :slug) RETURNING id',
            ['name' => 'Browse Tag ' . $suffix, 'slug' => $this->tagSlug]
        )[0]['id'];
        foreach ([$this->seedCourseIds[0], $this->seedCourseIds[2]] as $courseId) {
            $this->db->executeStatement(
                'INSERT INTO course_tags (course_id, tag_id) VALUES (:course, :tag)',
                ['course' => $courseId, 'tag' => $this->tagId]
            );
        }
        // A second provider's course participates in the same shared category vocabulary.
        $realOwner = $this->fixture->createUser('Browse Real Owner ' . $suffix, 'browse-real-owner-' . $suffix . '@real-' . $suffix . '.test');
        $realCompany = $this->fixture->createCompany($realOwner, 'Browse Real Co ' . $suffix, 'browse-real-' . $suffix . '.test');
        $this->fixture->addCompanyMember($realCompany, $realOwner, 'owner');
        $this->file(
            $this->fixture->createCourse($realOwner, $realCompany, 'browse-real-' . $suffix, 'Browse Real Course'),
            $this->leafId
        );
    }

    protected function tearDown(): void
    {
        // The tag is not the fixture's to track - course_tags cascades from courses - so it goes
        // here, before the courses that reference it are removed.
        $this->db->executeStatement('DELETE FROM tags WHERE id = :id', ['id' => $this->tagId]);
        $this->fixture->cleanup();
    }

    private function file(int $courseId, int $categoryId): void
    {
        $this->db->executeStatement(
            'UPDATE courses SET category_id = :category WHERE id = :id',
            ['category' => $categoryId, 'id' => $courseId]
        );
    }

    /** @return array{0:int,1:int} the count and the number of rows the same scope returns */
    private function browse(int $categoryId): array
    {
        return [
            $this->courses->publishedCoursesCount(new CatalogueFilter($categoryId)),
            count($this->courses->publishedCourses(new CatalogueFilter($categoryId), 100, 0)),
        ];
    }

    public function testRepositoryBranchFilteringIncludesEveryLevelBeneathIt(): void
    {
        self::assertSame([4, 4], $this->browse($this->rootId), 'The root holds every course in its branch.');
        self::assertSame([3, 3], $this->browse($this->branchId), 'The branch holds itself and the leaf.');
        self::assertSame([2, 2], $this->browse($this->leafId), 'The leaf is the narrowest scope.');
    }


    /** The taxonomy screen's rollup and the browse query must agree; they share one predicate. */
    public function testTheTaxonomyRollupAgreesWithBranchFiltering(): void
    {
        $rows = [];
        foreach ($this->courses->categories() as $row) {
            $rows[(int) $row['id']] = $row;
        }

        foreach ([$this->rootId, $this->branchId, $this->leafId] as $categoryId) {
            self::assertArrayHasKey($categoryId, $rows);
            [$count] = $this->browse($categoryId);
            self::assertSame(
                $count,
                (int) $rows[$categoryId]['descendant_course_count'],
                'The taxonomy branch count must agree with descendant filtering.'
            );
        }
    }

    /** The breadcrumb is the chain from the root down, and the depth cap bounds it at three. */
    public function testTheAncestryIsTheChainFromTheRootDown(): void
    {
        $trail = $this->courses->categoryAncestry($this->leafId);

        self::assertCount(3, $trail);
        self::assertSame([$this->rootId, $this->branchId, $this->leafId], array_map('intval', array_column($trail, 'id')));
        self::assertSame([1, 2, 3], array_map('intval', array_column($trail, 'level')));

        self::assertCount(1, $this->courses->categoryAncestry($this->rootId), 'A root is its own whole trail.');
    }

    /**
     * A browse URL resolves to its category, and its branch count remains available for scoped search.
     *
     * A category used to carry an active flag, and this test proved that clearing it hid the
     * category from its own URL. The flag is gone: a category exists or it does not, and the way to
     * take one out of the taxonomy is to delete it, which the edit screen already does after
     * reassigning whatever was filed under it.
     */
    public function testACategoryIsAddressableAndCarriesItsBranchCount(): void
    {
        $category = $this->courses->browsableCategory($this->rootSlug);
        self::assertNotNull($category);
        self::assertSame(4, (int) $category['descendant_course_count'], 'The root carries every course beneath it.');
    }

    /** The narrowing list offers each child with the count that choosing it will actually show. */
    public function testTheNarrowingListCountsTheWholeBranchBehindEachChild(): void
    {
        $children = $this->courses->browsableChildCategories($this->rootId);

        self::assertCount(1, $children);
        self::assertSame($this->branchId, (int) $children[0]['id']);
        self::assertSame(3, (int) $children[0]['descendant_course_count'], 'The branch offers itself and its leaf.');
    }

    /** A tag narrows the catalogue exactly as a category branch does, through the same object. */
    public function testATagNarrowsTheCatalogue(): void
    {
        $filter = CatalogueFilter::forTag($this->tagId);

        self::assertSame(2, $this->courses->publishedCoursesCount($filter), 'The tag is on two of the courses.');
        self::assertCount(2, $this->courses->publishedCourses($filter, 100, 0), 'The rows agree with the count.');
    }

    /**
     * Two facets together mean the intersection, and the count agrees with the rows.
     *
     * The tag is on the root course and the leaf course; the branch holds the branch course and the
     * leaf course. Together they can only be the leaf one.
     */
    public function testFacetsCombineAsAnIntersection(): void
    {
        $filter = new CatalogueFilter($this->branchId, [$this->tagId]);

        self::assertSame(1, $this->courses->publishedCoursesCount($filter));
        $rows = $this->courses->publishedCourses($filter, 100, 0);
        self::assertCount(1, $rows);
        self::assertSame($this->seedCourseIds[2], (int) $rows[0]['id']);
    }

    /**
     * A tag on several courses must not multiply the rows.
     *
     * course_tags is one row per pairing, so a join would return a course once per tag it carries
     * and the count would exceed the number of courses. The predicate is an EXISTS for that reason.
     */
    public function testASecondTagOnTheSameCourseDoesNotDuplicateIt(): void
    {
        $second = (int) $this->db->fetchAllAssociative(
            'INSERT INTO tags (name, slug) VALUES (:name, :slug) RETURNING id',
            ['name' => 'Browse Tag Two ' . $this->tagSlug, 'slug' => $this->tagSlug . '-two']
        )[0]['id'];
        $this->db->executeStatement(
            'INSERT INTO course_tags (course_id, tag_id) VALUES (:course, :tag)',
            ['course' => $this->seedCourseIds[0], 'tag' => $second]
        );

        try {
            $filter = CatalogueFilter::forTag($this->tagId);
            self::assertSame(2, $this->courses->publishedCoursesCount($filter));
            self::assertCount(2, $this->courses->publishedCourses($filter, 100, 0));
        } finally {
            $this->db->executeStatement('DELETE FROM tags WHERE id = :id', ['id' => $second]);
        }
    }

    /** A tag is addressable by its slug, and an unknown slug resolves to nothing. */
    public function testATagIsAddressableBySlug(): void
    {
        self::assertNotNull($this->courses->browsableTag($this->tagSlug));
        self::assertNull($this->courses->browsableTag($this->tagSlug . '-does-not-exist'));
    }


    /** A keyword narrows title, subtitle and summary, and its count agrees with its rows. */
    public function testAKeywordNarrowsTheCatalogue(): void
    {
        $hit = new CatalogueFilter(null, [], 'Browse Seed Course');
        self::assertSame(3, $this->courses->publishedCoursesCount($hit));
        self::assertCount(3, $this->courses->publishedCourses($hit, 100, 0));

        $miss = new CatalogueFilter($this->rootId, [], 'no course is called this');
        self::assertSame(0, $this->courses->publishedCoursesCount($miss));
        self::assertCount(0, $this->courses->publishedCourses($miss, 100, 0));
    }

    /** Several tags mean any of them, so adding one can only widen the result. */
    public function testSeveralTagsMeanAnyOfThem(): void
    {
        $second = (int) $this->db->fetchAllAssociative(
            'INSERT INTO tags (name, slug) VALUES (:name, :slug) RETURNING id',
            ['name' => 'Browse Tag Or ' . $this->tagSlug, 'slug' => $this->tagSlug . '-or']
        )[0]['id'];
        // On the branch course only, which the first tag is not on.
        $this->db->executeStatement(
            'INSERT INTO course_tags (course_id, tag_id) VALUES (:course, :tag)',
            ['course' => $this->seedCourseIds[1], 'tag' => $second]
        );

        try {
            self::assertSame(2, $this->courses->publishedCoursesCount(CatalogueFilter::forTag($this->tagId)));
            self::assertSame(1, $this->courses->publishedCoursesCount(CatalogueFilter::forTag($second)));

            $both = new CatalogueFilter(null, [$this->tagId, $second]);
            self::assertSame(3, $this->courses->publishedCoursesCount($both), 'Any of the tags, not all of them.');
            self::assertCount(3, $this->courses->publishedCourses($both, 100, 0));
        } finally {
            $this->db->executeStatement('DELETE FROM tags WHERE id = :id', ['id' => $second]);
        }
    }

    /**
     * A facet's own counts are taken with that facet relaxed.
     *
     * This is the whole difference between a usable rail and a dead end. With the tag facet applied
     * to its own counts, choosing one tag drives every other tag to zero - no course carries a tag
     * it does not carry - and the reader is left with one live option and no way onwards. Relaxed,
     * each option still reports what choosing it would return.
     */
    public function testATagFacetIsCountedWithItsOwnFacetRelaxed(): void
    {
        $second = (int) $this->db->fetchAllAssociative(
            'INSERT INTO tags (name, slug) VALUES (:name, :slug) RETURNING id',
            ['name' => 'Browse Tag Facet ' . $this->tagSlug, 'slug' => $this->tagSlug . '-facet']
        )[0]['id'];
        $this->db->executeStatement(
            'INSERT INTO course_tags (course_id, tag_id) VALUES (:course, :tag)',
            ['course' => $this->seedCourseIds[1], 'tag' => $second]
        );

        try {
            $chosen = CatalogueFilter::forTag($this->tagId);
            $ids = [$this->tagId, $second];

            $relaxed = $this->courses->tagFacetCounts($ids, $chosen->withoutTags());
            self::assertSame(2, $relaxed[$this->tagId]);
            self::assertSame(1, $relaxed[$second], 'The unchosen tag still offers its own courses.');

            // What it would look like if the facet were applied to itself, which is the bug.
            $applied = $this->courses->tagFacetCounts($ids, $chosen);
            self::assertArrayNotHasKey($second, $applied, 'Applied to itself, every other option dies.');
        } finally {
            $this->db->executeStatement('DELETE FROM tags WHERE id = :id', ['id' => $second]);
        }
    }

    /** Facet counts respect the other facets. */
    public function testFacetCountsRespectTheOtherFacets(): void
    {
        // The tag is on the root course and the leaf course; the branch holds the branch and leaf
        // courses. Counted inside the branch, the tag offers only the leaf one.
        $inBranch = $this->courses->tagFacetCounts(
            [$this->tagId],
            (new CatalogueFilter($this->branchId, [$this->tagId]))->withoutTags()
        );
        self::assertSame(1, $inBranch[$this->tagId]);

        self::assertSame(
            [$this->tagId => 2],
            $this->courses->tagFacetCounts([$this->tagId], CatalogueFilter::none()),
            'With no other facet applied the tag offers everything it is on.'
        );
    }

    /** The category facet counts each branch under the reader's other choices. */
    public function testCategoryFacetCountsRespectTheChosenTag(): void
    {
        $counts = $this->courses->categoryFacetCounts(
            [$this->branchId],
            CatalogueFilter::forTag($this->tagId)
        );

        // The branch holds the branch course and the leaf course; only the leaf one carries the tag.
        self::assertSame(1, $counts[$this->branchId]);
    }

    /**
     * The top-level distribution partitions the published catalogue.
     *
     * Every published course is filed at exactly one level of exactly one branch, or at none, so the
     * branch totals plus the uncategorised remainder must come to the whole catalogue. A distribution
     * that does not add up is the strongest signal there is that a branch predicate has drifted -
     * and the chart above it would still look perfectly reasonable.
     */
    public function testTheCategoryDistributionAddsUpToTheWholeCatalogue(): void
    {
        $distribution = $this->courses->categoryDistribution();
        $total = 0;
        foreach ($distribution as $row) {
            $total += $row['total'];
        }

        self::assertSame(
            $this->courses->publishedCoursesCount(CatalogueFilter::none()),
            $total,
            'Top-level branches plus the uncategorised remainder are the whole published catalogue.'
        );

        $labels = array_column($distribution, 'label');
        self::assertSame('Uncategorised', end($labels), 'The remainder is stated, not left implied.');

        $remainder = end($distribution);
        self::assertGreaterThanOrEqual(
            1,
            $remainder['total'],
            'The fixture files one course under nothing, so a dropped remainder cannot pass unnoticed.'
        );
    }

    /** Shares are scaled against the largest row, so exactly one row is full width. */
    public function testDistributionSharesAreScaledAgainstTheLargestRow(): void
    {
        $rows = $this->service->categoryDistribution();
        self::assertNotSame([], $rows);

        $shares = array_column($rows, 'share');
        foreach ($shares as $share) {
            self::assertGreaterThanOrEqual(0, $share);
            self::assertLessThanOrEqual(100, $share);
        }
        self::assertSame(100, max($shares), 'The largest row fills the track.');

        // A row with no courses draws nothing rather than a minimum bar, so an empty branch reads as
        // empty at a glance.
        foreach ($rows as $row) {
            if ($row['total'] === 0) {
                self::assertSame(0, $row['share']);
            }
        }
    }

    /**
     * The tag distribution reports the untagged remainder, which no other figure on the platform
     * gives: a course carrying no tag is reachable only by category or by keyword.
     */
    public function testTheTagDistributionReportsTheUntaggedRemainder(): void
    {
        $rows = $this->courses->tagDistribution(5);
        $labels = array_column($rows, 'label');

        self::assertSame('Untagged', end($labels));
        self::assertLessThanOrEqual(6, count($rows), 'The limit bounds the tags, not the remainder.');

        // Our fixture tagged two of its three courses, so exactly one is untagged.
        $untagged = end($rows);
        self::assertGreaterThanOrEqual(1, $untagged['total']);
    }


    public function testDirectCategoryPagesDoNotIncludeDescendants(): void
    {
        foreach ([$this->rootId => 1, $this->branchId => 1, $this->leafId => 2] as $id => $expected) {
            $pagination = \CattoLearning\Support\Pagination::catalogue(1, $this->service->categoryCourseTotal($id));
            $rows = $this->service->categoryCoursePage($id, $pagination->toArray());
            self::assertSame($expected, $pagination->total);
            self::assertCount($expected, $rows);
            foreach ($rows as $row) self::assertSame($id, (int) $row['category_id']);
        }
    }

    public function testFlatNavigationRetainsBothRailsAndActiveAncestors(): void
    {
        $model = $this->service->catalogueNavigation($this->service->category($this->leafId));
        self::assertSame($this->rootId, (int) $model['active_root']['id']);
        self::assertSame($this->branchId, (int) $model['active_tier_2']['id']);
        self::assertSame($this->leafId, (int) $model['active_tier_3']['id']);
        self::assertSame([$this->branchId], array_map('intval', array_column($model['tier_2'], 'id')));
        self::assertSame([$this->leafId], array_map('intval', array_column($model['tier_3'], 'id')));
        self::assertTrue($model['tier_2'][0]['is_active']);
        self::assertTrue($model['tier_3'][0]['is_active']);
        foreach (['roots', 'tier_2', 'tier_3'] as $rail) {
            foreach ($model[$rail] as $item) {
                self::assertArrayNotHasKey('children', $item, 'The view model must not offer recursive rendering.');
                self::assertSame('/courses/category/' . $item['slug'], $item['href']);
            }
        }
        $root = $this->service->catalogueNavigation($this->service->category($this->rootId));
        self::assertNotEmpty($root['tier_2']);
        self::assertSame([], $root['tier_3']);
    }

    public function testLiveCategoryRouteUsesDirectCoursesAndSearchUsesDescendants(): void
    {
        $base = '/courses/category/' . $this->rootSlug;
        $normal = $this->page($base);
        self::assertSame(1, $normal->query('//article[contains(@class,"cl-course-card")]')->length);
        self::assertSame(1, $normal->query('//a[contains(@class,"cl-category-tile") and @aria-current]')->length);
        self::assertSame(1, $normal->query('//form[@action="' . $base . '" and @data-server-search]')->length);
        $search = $this->page($base . '?catalogue_q=Browse%20Seed%20Course');
        self::assertSame(3, $search->query('//article[contains(@class,"cl-course-card")]')->length);
        self::assertSame(1, $search->query('//form[@data-server-search]//a[@href="' . $base . '"]')->length, 'Clearing search stays in the category.');
        $enhanced = $this->page($base, true);
        self::assertSame(1, $enhanced->query('//*[@id="catalogue-region"]/*[@id="catalogue-results"]')->length);
        self::assertSame(1, $enhanced->query('//article[contains(@class,"cl-course-card")]')->length);
        $cleared = $this->page($base . '?catalogue_q=');
        self::assertSame(1, $cleared->query('//article[contains(@class,"cl-course-card")]')->length);
    }

    public function testLiveTagRouteKeepsVocabularyAndSearchScope(): void
    {
        $base = '/courses/tag/' . $this->tagSlug;
        $tag = $this->page($base);
        self::assertSame(2, $tag->query('//article[contains(@class,"cl-course-card")]')->length);
        self::assertSame(1, $tag->query('//nav[@aria-label="All tags"]//a[@aria-current="page" and @href="' . $base . '"]')->length);
        $search = $this->page($base . '?catalogue_q=Browse%20Real%20Course');
        self::assertSame(0, $search->query('//article[contains(@class,"cl-course-card")]')->length, 'A keyword cannot escape the selected tag.');
        self::assertSame(1, $search->query('//form[@data-server-search and @action="' . $base . '"]')->length);
        $index = $this->page('/courses/tags');
        self::assertSame(0, $index->query('//article[contains(@class,"cl-course-card")]')->length);
        self::assertGreaterThan(0, $index->query('//nav[@aria-label="All tags"]//a[@href]')->length);
    }

    public function testVocabularyIncludesUnusedTagsAndEmptyCategories(): void
    {
        $empty = $this->fixture->createCategory('Empty ' . $this->fixture->suffix(), 'empty-' . $this->fixture->suffix());
        $navigation = $this->service->catalogueNavigation($this->service->category($empty));
        self::assertContains($empty, array_map('intval', array_column($navigation['roots'], 'id')));
        $this->db->executeStatement('DELETE FROM course_tags WHERE tag_id = :tag', ['tag' => $this->tagId]);
        $tags = array_column($this->service->tagIndex(), null, 'id');
        self::assertArrayHasKey($this->tagId, $tags);
        self::assertSame(0, (int) $tags[$this->tagId]['course_count']);
        $page = $this->page('/courses/category/empty-' . $this->fixture->suffix());
        self::assertSame(0, $page->query('//article[contains(@class,"cl-course-card")]')->length);
        self::assertSame(1, $page->query('//*[contains(@class,"cl-ui-empty-state")]')->length);
    }

    public function testLivePublicPagesAreBoundedAndBothPagersRetainSearch(): void
    {
        $course = $this->courses->findById($this->seedCourseIds[0]);
        self::assertNotNull($course);
        for ($n = 0; $n < 25; $n++) {
            $id = $this->fixture->createCourse((int) $course['owner_user_id'], (int) $course['owner_company_id'], 'browse-page-' . $n . '-' . $this->fixture->suffix(), 'Browse pagination fixture');
            $this->file($id, $this->rootId);
            $this->db->executeStatement('INSERT INTO course_tags (course_id, tag_id) VALUES (:course, :tag)', ['course' => $id, 'tag' => $this->tagId]);
        }
        foreach (['/courses/category/' . $this->rootSlug, '/courses/tag/' . $this->tagSlug] as $base) {
            $first = $this->page($base . '?catalogue_q=Browse&page_size=2000');
            self::assertSame(24, $first->query('//article[contains(@class,"cl-course-card")]')->length);
            self::assertSame(2, $first->query('//nav[@data-pagination="catalogue"]')->length);
            self::assertSame(2, $first->query('//a[@rel="next" and contains(@href,"catalogue_q=Browse") and contains(@href,"page_size=24")]')->length);
            $second = $this->page($base . '?catalogue_q=Browse&page=2', true);
            self::assertGreaterThan(0, $second->query('//article[contains(@class,"cl-course-card")]')->length);
            self::assertLessThanOrEqual(24, $second->query('//article[contains(@class,"cl-course-card")]')->length);
        }
        $root = $this->page('/courses');
        self::assertLessThanOrEqual(12, $root->query('//article[contains(@class,"cl-course-card")]')->length);
        self::assertGreaterThan(0, $root->query('//article[contains(@class,"cl-course-card")]')->length);
    }

    public function testFavouriteStateAndCsrfSurviveFullAndHtmxWorkspaceRendering(): void
    {
        $row = $this->courses->findById($this->seedCourseIds[0]);
        self::assertNotNull($row);
        $userId = (int) $row['owner_user_id'];
        $this->fixture->grantRole($userId, 'STUDENT');
        $token = \CattoLearning\Support\Token::generate();
        $sessions = IntegrationContainer::get()->get(\CattoLearning\Infrastructure\Persistence\AuthSessionRepository::class);
        $sessions->create($userId, \CattoLearning\Support\Token::hash($token), 3600, 'qa', 'PHPUnit');
        $this->db->executeStatement('INSERT INTO course_favourites (user_id, course_id) VALUES (:user, :course)', ['user' => $userId, 'course' => $this->seedCourseIds[0]]);
        $cookie = \CattoLearning\Support\Env::string('AUTH_SESSION_COOKIE', 'catto_learning_session');
        $before = $_COOKIE;
        $_COOKIE[$cookie] = $token;
        try {
            foreach (['/courses/category/' . $this->rootSlug, '/courses/tag/' . $this->tagSlug] as $base) {
                foreach ([false, true] as $htmx) {
                    $page = $this->page($base, $htmx);
                    $form = '//*[@id="favourite-' . $this->seedCourseIds[0] . '"]';
                    self::assertSame(1, $page->query($form . '//button[@aria-pressed="true"]')->length);
                    self::assertSame(1, $page->query($form . '//input[@name="csrf" and string-length(@value)>0]')->length);
                    self::assertSame(1, $page->query($form . '//input[@name="return" and @value="' . $base . '"]')->length);
                }
            }
        } finally {
            $_COOKIE = $before;
        }
    }

    /** Actual route dispatch and strict Twig rendering, with no client-side rendering involved. */
    private function page(string $url, bool $htmx = false): \DOMXPath
    {
        $boot = \CattoLearning\Application\CliBootstrap::boot();
        $kernel = new \CattoLearning\Kernel('test', true, $boot['instance_root']);
        $request = \Symfony\Component\HttpFoundation\Request::create($url, 'GET');
        if ($htmx) $request->headers->set('HX-Request', 'true');
        try {
            $response = $kernel->handle($request);
            self::assertSame(200, $response->getStatusCode(), $url . ' must render through its live Symfony owner.');
            $html = (string) $response->getContent();
            $dom = new \DOMDocument();
            $previous = libxml_use_internal_errors(true);
            try {
                $dom->loadHTML($html);
            } finally {
                libxml_clear_errors();
                libxml_use_internal_errors($previous);
            }
            return new \DOMXPath($dom);
        } finally {
            $kernel->shutdown();
        }
    }
}
