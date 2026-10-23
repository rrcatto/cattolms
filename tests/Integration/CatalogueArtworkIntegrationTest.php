<?php

declare(strict_types=1);

namespace CattoLearning\Tests\Integration;

use CattoLearning\Course\CatalogueFile;
use CattoLearning\Course\CourseService;
use CattoLearning\Infrastructure\Persistence\Database;
use CattoLearning\Infrastructure\Persistence\RoleRepository;
use CattoLearning\Tests\Support\{DevelopmentFixture, IntegrationContainer};
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * The catalogue's own categories and tags, each with an icon, and each course's tags and cover picture
 * (owner, 2026-10-20: "custom svg images … for each course … a custom svg icon … for each category. each
 * tag also needs a custom svg icon"; "1 - 3 tags per course"), kept in the catalogue file and put back
 * after a rebuild ("save it to a format you can reimport each time you reset the db"). Since 2026-10-23 the file holds
 * the categories and tags; each course carries its own in its package (CataloguePackageIntegrationTest).
 */
final class CatalogueArtworkIntegrationTest extends TestCase
{
    private const ICON = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path d="M4 4h16v16H4z"/></svg>';
    private const OTHER_ICON = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor"><circle cx="12" cy="12" r="8"/></svg>';
    private const COVER = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 640 360" preserveAspectRatio="xMidYMid slice"><rect width="640" height="360" fill="#0f3d3e"/></svg>';

    private Database $db;
    private DevelopmentFixture $fixture;
    private CourseService $courses;
    private int $admin;
    private string $suffix;

    protected function setUp(): void
    {
        $this->db = IntegrationContainer::db();
        $this->db->beginTransaction();
        $this->fixture = new DevelopmentFixture($this->db);
        $this->courses = IntegrationContainer::get()->get(CourseService::class);
        $roles = new RoleRepository($this->db);
        $this->admin = $roles->firstUserWithRole('ADMIN') ?? (function () use ($roles): int {
            $id = $this->fixture->createUser('Catalogue administrator');
            $roles->assign($id, 'ADMIN');
            return $id;
        })();
        $this->suffix = $this->fixture->suffix();
    }

    protected function tearDown(): void
    {
        if ($this->db->isTransactionActive()) $this->db->rollBack();
    }

    public function testTheCatalogueFileIsImportedOnceAndExportedAsItWasLoaded(): void
    {
        // Version 2 (owner, 2026-10-23): the categories and tags; each course's settings travel in its own package.
        $catalogue = [
            'format' => 'cattolms-catalogue', 'version' => 2,
            'categories' => [['name' => 'Catalogue root ' . $this->suffix, 'slug' => 'catalogue-root-' . $this->suffix, 'description' => 'The root.', 'icon' => self::ICON,
                'children' => [['name' => 'Catalogue child ' . $this->suffix, 'slug' => 'catalogue-child-' . $this->suffix, 'description' => '', 'icon' => self::OTHER_ICON]]]],
            'tags' => [['name' => 'Catalogue tag ' . $this->suffix, 'icon' => self::ICON], ['name' => 'Second tag ' . $this->suffix, 'icon' => self::OTHER_ICON]],
        ];
        $file = IntegrationContainer::get()->get(CatalogueFile::class);
        $plan = $file->plan($catalogue);
        self::assertSame(['Catalogue root ' . $this->suffix, 'Catalogue root ' . $this->suffix . ' › Catalogue child ' . $this->suffix], $plan['categories_new'], 'The review names each category it would make by its path.');
        self::assertSame(['Catalogue tag ' . $this->suffix, 'Second tag ' . $this->suffix], $plan['tags_new']);
        $report = $file->import($catalogue, $this->admin);
        self::assertSame(['categories_created' => 2, 'categories_kept' => 0, 'tags_created' => 2, 'tags_kept' => 0], $report);

        $root = $this->db->fetchAssociative('SELECT id, icon_svg, description FROM course_categories WHERE slug = :s', ['s' => 'catalogue-root-' . $this->suffix]);
        $child = $this->db->fetchAssociative('SELECT parent_id, level, icon_svg FROM course_categories WHERE slug = :s', ['s' => 'catalogue-child-' . $this->suffix]);
        self::assertSame([self::ICON, 'The root.', (int) $root['id'], 2, self::OTHER_ICON], [$root['icon_svg'], $root['description'], (int) $child['parent_id'], (int) $child['level'], $child['icon_svg']], 'The hierarchy and the icons are kept.');

        $exported = $file->export();
        self::assertSame([2, false], [$exported['version'], array_key_exists('courses', $exported)], 'The file holds categories and tags only.');
        $exportedRoot = array_values(array_filter($exported['categories'], fn(array $node): bool => $node['slug'] === 'catalogue-root-' . $this->suffix))[0];
        self::assertSame(['Catalogue root ' . $this->suffix, self::ICON, 'catalogue-child-' . $this->suffix, self::OTHER_ICON], [$exportedRoot['name'], $exportedRoot['icon'], $exportedRoot['children'][0]['slug'], $exportedRoot['children'][0]['icon']]);

        $again = $file->import($catalogue, $this->admin);
        self::assertSame([0, 2, 0, 2], [$again['categories_created'], $again['categories_kept'], $again['tags_created'], $again['tags_kept']], 'Importing again creates nothing twice.');
        self::assertSame([[], [], 2, 2], [($second = $file->plan($catalogue))['categories_new'], $second['tags_new'], $second['categories_kept'], $second['tags_kept']]);
    }

    public function testACatalogueFileIsCheckedBeforeAnythingIsKept(): void
    {
        $file = IntegrationContainer::get()->get(CatalogueFile::class);
        foreach ([['format' => 'something-else', 'version' => 2], ['format' => 'cattolms-catalogue', 'version' => 1]] as $wrong) {
            try {
                $file->import($wrong, $this->admin);
                self::fail('Only a catalogue file of this version is imported.');
            } catch (InvalidArgumentException $refused) {
                self::assertSame('This is not a CattoLMS catalogue file (format "cattolms-catalogue", version 2).', $refused->getMessage());
            }
        }
        $this->expectExceptionMessage('The icon of the tag "Bad tag ' . $this->suffix . '" must be a picture only: it contains a <script> element.');
        $file->import(['format' => 'cattolms-catalogue', 'version' => 2, 'categories' => [], 'tags' => [['name' => 'Bad tag ' . $this->suffix, 'icon' => '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>']]], $this->admin);
    }

    public function testADetailsFormSetsTheCoursesTagsAndCoverAndTheFormsSetIcons(): void
    {
        $category = $this->courses->createCategory(['name' => 'Icon category ' . $this->suffix], $this->admin, null, self::ICON);
        self::assertSame(self::ICON, $this->db->fetchOne('SELECT icon_svg FROM course_categories WHERE id = :id', ['id' => (int) $category['id']]));
        $this->courses->updateCategory((int) $category['id'], ['name' => 'Icon category ' . $this->suffix, 'slug' => (string) $category['slug']], $this->admin, self::OTHER_ICON);
        self::assertSame(self::OTHER_ICON, $this->db->fetchOne('SELECT icon_svg FROM course_categories WHERE id = :id', ['id' => (int) $category['id']]));
        $generated = $this->courses->createCategory(['name' => 'Plain category ' . $this->suffix], $this->admin);
        self::assertStringStartsWith('<svg', (string) $this->db->fetchOne('SELECT icon_svg FROM course_categories WHERE id = :id', ['id' => (int) $generated['id']]), 'Without an upload a lettered icon is made.');

        $first = $this->courses->createTag(['name' => 'First icon tag ' . $this->suffix], $this->admin, self::ICON);
        $second = $this->courses->createTag(['name' => 'Second icon tag ' . $this->suffix], $this->admin);
        $this->courses->updateTag((int) $second['id'], ['name' => 'Second icon tag ' . $this->suffix], $this->admin, self::OTHER_ICON);
        self::assertSame([self::ICON, self::OTHER_ICON], [$this->courses->tag((int) $first['id'])['icon_svg'], $this->courses->tag((int) $second['id'])['icon_svg']]);

        $owner = $this->fixture->createUser('Tagged course owner');
        $company = $this->fixture->createCompany($owner, 'Tagged provider ' . $this->suffix, $this->suffix . '.tagged.example.test');
        $course = $this->fixture->createCourse($owner, $company, 'tagged-course-' . $this->suffix, 'Tagged course', 'published');
        $details = ['title' => 'Tagged course', 'slug' => 'tagged-course-' . $this->suffix, 'category_id' => (string) $category['id'], 'access_days' => '365', 'tags_shown' => '1', 'tag_ids' => [(string) $first['id'], (string) $second['id']]];
        $this->courses->updateCourse($course, $details, $this->admin, self::COVER);
        $options = array_values(array_filter($this->courses->courseTagOptions($course), static fn(array $tag): bool => $tag['checked']));
        self::assertSame(['First icon tag ' . $this->suffix, 'Second icon tag ' . $this->suffix], array_column($options, 'name'));
        self::assertSame(self::COVER, $this->db->fetchOne('SELECT cover_svg FROM courses WHERE id = :id', ['id' => $course]));
        $this->courses->updateCourse($course, ['tag_ids' => []] + $details, $this->admin);
        self::assertSame([], array_values(array_filter($this->courses->courseTagOptions($course), static fn(array $tag): bool => $tag['checked'])), 'Clearing every box takes the tags away.');
        self::assertSame(self::COVER, $this->db->fetchOne('SELECT cover_svg FROM courses WHERE id = :id', ['id' => $course]), 'No cover chosen keeps the current one.');
        try {
            $this->courses->updateCourse($course, ['tag_ids' => ['999999999']] + $details, $this->admin);
            self::fail('A tag that does not exist is refused.');
        } catch (InvalidArgumentException $refused) {
            self::assertSame('One of the chosen tags no longer exists. Review the tags and save again.', $refused->getMessage());
        }

        // The category picker and the catalogue's category line carry each category's icon.
        $picker = $this->courses->courseCategoryPicker((int) $category['id']);
        $item = self::find($picker['items'], (string) $category['id']);
        self::assertSame(self::OTHER_ICON, $item['icon']);
        $navigation = $this->courses->categoryNavigation(null);
        $line = array_values(array_filter($navigation['subcategories'], fn(array $sub): bool => $sub['label'] === 'Icon category ' . $this->suffix))[0];
        self::assertSame(self::OTHER_ICON, $line['icon']);
    }

    /**
     * @param list<array<string,mixed>> $items
     * @return array<string,mixed>
     */
    private static function find(array $items, string $value): array
    {
        foreach ($items as $item) {
            if ((string) $item['value'] === $value) return $item;
            $found = self::find($item['children'] ?? [], $value);
            if ($found !== []) return $found;
        }
        return [];
    }

    /**
     * A new category or tag without an uploaded icon takes the library's icon that fits its name (owner, 2026-10-20: "an
     * icon library is the best option here"), and its form can choose another from the library.
     */
    public function testANewCategoryOrTagTakesTheLibrarysIconThatFitsItsName(): void
    {
        $library = new \CattoLearning\View\Artwork\IconLibrary();
        $category = $this->courses->createCategory(['name' => 'Law ' . $this->suffix, 'slug' => 'law-' . $this->suffix], $this->admin);
        self::assertSame($library->svg('scale'), (string) $category['icon_svg'], 'Law is pictured by the scales.');
        $tag = $this->courses->createTag(['name' => 'Physics ' . $this->suffix], $this->admin);
        self::assertSame($library->svg('atom'), (string) $tag['icon_svg']);
        $this->courses->updateCategory((int) $category['id'], ['name' => $category['name'], 'slug' => $category['slug'], 'description' => ''], $this->admin, $library->chosen('lock'));
        self::assertSame($library->svg('lock'), (string) $this->courses->category((int) $category['id'])['icon_svg'], 'Another icon chosen from the library replaces it.');
        $this->courses->updateCategory((int) $category['id'], ['name' => $category['name'], 'slug' => $category['slug'], 'description' => ''], $this->admin, $library->chosen(''));
        self::assertSame($library->svg('lock'), (string) $this->courses->category((int) $category['id'])['icon_svg'], 'Choosing nothing keeps it.');
        $this->courses->replaceCategoryIcon((int) $category['id'], (string) $library->chosen('book'), $this->admin);
        self::assertSame($library->svg('book'), (string) $this->courses->category((int) $category['id'])['icon_svg'], 'The icon card saves the icon on its own.');
        $this->courses->replaceTagIcon((int) $tag['id'], (string) $library->chosen('lock'), $this->admin);
        self::assertSame($library->svg('lock'), (string) $this->courses->tag((int) $tag['id'])['icon_svg']);
    }
}
