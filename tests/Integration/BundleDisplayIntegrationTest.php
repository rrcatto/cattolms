<?php

declare(strict_types=1);

namespace CattoLearning\Tests\Integration;

use CattoLearning\Auth\CurrentUser;
use CattoLearning\Bundle\BundleService;
use CattoLearning\Commerce\Application\OrderService;
use CattoLearning\Http\Controller\{CourseController, CourseTagController};
use CattoLearning\Infrastructure\Persistence\Database;
use CattoLearning\Support\{Money, Uuid};
use CattoLearning\Tests\Support\{BillingFixture, BundleFixture, CheckNames, DevelopmentFixture, InProcessPage, IntegrationContainer, IntegrationDataFixture};
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * How bundles show beside the courses (owner, 2026-11-06: "Bundle mockup is approved"): on a course's page, under the
 * buy button, each bundle holding it that the visitor could buy, the larger saving first; in the catalogue a "Save with
 * a bundle" row above the courses, every bundle on All courses and a category's branch's bundles on its page, but never
 * with a search, on a later page or on a tag's page, where the one line about bundles stays. A company account sees
 * none. On the real services and controllers; every write rolls back.
 */
#[Group('commerce')]
final class BundleDisplayIntegrationTest extends TestCase
{
    private const PERMISSIONS = ['COMMERCE.CART.VIEW', 'COMMERCE.CART.MANAGE', 'COMMERCE.CHECKOUT.START'];
    private const LINE = 'Several courses for one price: <a href="/bundles">browse course bundles</a>.';

    private Database $db;
    private DevelopmentFixture $fixture;
    private BundleService $bundles;
    /** @var array{id:int,name:string,domain:string,founder:array{id:int,email:string,first:string,last:string,name:string}} */
    private array $company;
    /** @var array<string,array{id:int,slug:string,name:string}> */
    private array $categories = [];
    /** @var array<string,array{id:int,slug:string,title:string,code:string}> */
    private array $courses = [];
    /** @var array<string,array{id:int,offer:int,slug:string,title:string}> */
    private array $made = [];

    protected function setUp(): void
    {
        $this->db = IntegrationContainer::db();
        $this->db->beginTransaction();
        $this->fixture = new DevelopmentFixture($this->db);
        $this->bundles = IntegrationContainer::get()->get(BundleService::class);
        $this->company = $this->fixture->company();
        $owner = $this->company['founder']['id'];

        // A tree of its own, beside the site's: root > branch > leaf, and two other top-level categories.
        $names = (new CheckNames($this->db))->categories(['root', 'branch', 'leaf', 'other', 'empty']);
        $tree = new IntegrationDataFixture($this->db);
        foreach (['root' => null, 'branch' => 'root', 'leaf' => 'branch', 'other' => null, 'empty' => null] as $key => $parent) {
            $slug = trim((string) preg_replace('/[^a-z]+/', '-', strtolower($names[$key])), '-') . '-' . $this->fixture->suffix();
            $this->categories[$key] = ['id' => $tree->createCategory($names[$key], $slug, $parent === null ? 0 : $this->categories[$parent]['id']), 'slug' => $slug, 'name' => $names[$key]];
        }
        foreach (['a' => ['leaf', 50000], 'b' => ['branch', 70000], 'c' => ['root', 40000], 'd' => ['other', 30000], 'e' => ['other', 20000], 'f' => ['empty', 10000]] as $key => [$category, $price]) {
            $this->courses[$key] = $this->fixture->course($owner, $this->company['id'], 'published');
            $this->fixture->createPriceVariant($this->courses[$key]['id'], $owner, 31536000, $price, true);
            $this->db->executeStatement('UPDATE courses SET category_id = :c WHERE id = :id', ['c' => $this->categories[$category]['id'], 'id' => $this->courses[$key]['id']]);
        }
        // Savings: big R400 (its was price), small R200, plain R50 (no was price: R900 one by one), elsewhere R8,900 and
        // hidden R9,400, which would come first wherever it showed.
        $names = new CheckNames($this->db);
        foreach (['big' => [['a', 'b', 'c'], 120000, 160000], 'small' => [['a', 'b'], 100000, 120000], 'plain' => [['a', 'c'], 85000, null],
            'elsewhere' => [['d', 'e'], 10000, 900000], 'hidden' => [['a', 'b'], 60000, 1000000]] as $key => [$in, $price, $was]) {
            $this->made[$key] = BundleFixture::create($this->db, $owner, array_map(fn(string $c): int => $this->courses[$c]['id'], $in), $price,
                ['title' => $names->bundle()] + ($was === null ? [] : ['was_price' => $was]));
        }
        $_SESSION['csrf'] = str_repeat('c', 64);
    }

    protected function tearDown(): void
    {
        if ($this->db->isTransactionActive()) $this->db->rollBack();
        unset($_SESSION['csrf'], $_SESSION['flash']);
    }

    public function testACoursePageOffersEachBundleItIsInThatCanBeBoughtTheLargerSavingFirst(): void
    {
        $this->hide();
        $notes = $this->bundles->forCourse($this->courses['a']['id'], null);
        self::assertSame($this->titles('big', 'small', 'plain'), array_column($notes, 'title'), 'Every bundle holding the course, the larger saving first.');
        self::assertSame(['two', 'one', 'one'], array_column($notes, 'others_words'));
        self::assertSame([$this->rand(40000), $this->rand(20000), $this->rand(5000)], array_column($notes, 'save_label'), 'The was price less the price, or what the courses cost more one by one.');
        self::assertSame($this->titles('big', 'plain'), array_column($this->bundles->forCourse($this->courses['c']['id'], null), 'title'));
        self::assertSame([], $this->bundles->forCourse($this->courses['f']['id'], null), 'A course in no bundle has no note.');

        $html = $this->coursePage('a', null);
        self::assertMatchesRegularExpression('#id="course-access".*Add to cart.*Also in ' . preg_quote($this->made['big']['title'], '#') . '#s', $html, 'Under the buy button of the Course access card.');
        $at = array_map(fn(string $key): int|false => strpos($html, 'Also in ' . $this->made[$key]['title']), ['big', 'small', 'plain']);
        self::assertNotContains(false, $at);
        self::assertTrue($at[0] < $at[1] && $at[1] < $at[2], 'The larger saving first.');
        foreach (['This course and the two beside it for', 'This course and the one beside it for', 'with 1 year of access to each.', 'Save ' . $this->rand(40000), 'See the bundle', 'href="/bundles/' . $this->made['big']['slug'] . '"',
            '<s class="cl-price-was"><span class="visually-hidden">Was </span>' . $this->rand(160000) . '</s>', $this->rand(120000) . '</span></span></strong>, with'] as $shown) {
            self::assertStringContainsString($shown, $html, $shown);
        }
        self::assertStringNotContainsString($this->made['hidden']['title'], $html);
        self::assertStringNotContainsString('Also in', $this->coursePage('f', null));
    }

    public function testABundleThatCannotBeBoughtNowIsNeitherOfferedNorInTheRow(): void
    {
        $hidden = $this->made['hidden'];
        self::assertSame($hidden['title'], $this->bundles->forCourse($this->courses['a']['id'], null)[0]['title'], 'On sale, it saves the most and comes first.');
        self::assertSame($hidden['title'], $this->bundles->catalogueRow($this->categories['root']['id'], null)[0]['title']);
        $states = [
            'not on sale' => ['UPDATE bundle_offers SET is_active = FALSE WHERE bundle_id = :id', 'UPDATE bundle_offers SET is_active = TRUE WHERE bundle_id = :id'],
            'its sales ended' => ["UPDATE bundles SET available_until = NOW() - INTERVAL '1 day' WHERE id = :id", 'UPDATE bundles SET available_until = NULL WHERE id = :id'],
            // Its sale not begun and something else stopping it too: hidden, as that other thing hides it.
            'its sales not begun and not on sale' => ["UPDATE bundles SET available_from = NOW() + INTERVAL '1 day' WHERE id = :id; UPDATE bundle_offers SET is_active = FALSE WHERE bundle_id = :id",
                'UPDATE bundles SET available_from = NULL WHERE id = :id; UPDATE bundle_offers SET is_active = TRUE WHERE bundle_id = :id'],
            'a draft' => ["UPDATE bundles SET status = 'draft' WHERE id = :id", "UPDATE bundles SET status = 'published' WHERE id = :id"],
            'retired' => ["UPDATE bundles SET status = 'retired' WHERE id = :id", "UPDATE bundles SET status = 'published' WHERE id = :id"],
            'without a price' => ['DELETE FROM bundle_offers WHERE bundle_id = :id', null],
        ];
        foreach ($states as $state => [$make, $undo]) {
            foreach (explode('; ', $make) as $statement) $this->db->executeStatement($statement, ['id' => $hidden['id']]);
            self::assertNotContains($hidden['title'], array_column($this->bundles->forCourse($this->courses['a']['id'], null), 'title'), 'A course page, ' . $state);
            self::assertNotContains($hidden['title'], array_column($this->bundles->catalogueRow($this->categories['root']['id'], null), 'title'), 'A category, ' . $state);
            self::assertNotContains($hidden['title'], array_column($this->bundles->catalogueRow(null, null), 'title'), 'All courses, ' . $state);
            self::assertStringNotContainsString($hidden['title'], $this->coursePage('a', null), 'The page, ' . $state);
            if ($undo !== null) foreach (explode('; ', $undo) as $statement) $this->db->executeStatement($statement, ['id' => $hidden['id']]);
        }
    }

    public function testABundleWhoseSaleStartsLaterStaysWithWhenItGoesOnSale(): void
    {
        // Owner, 2026-11-06: "If I set a future date for start, do not remove the bundle from the catalogue - display it with
        // a start date. I don't want to see "This bundle is not on sale yet." - show me WHEN the bundle will be available".
        $later = $this->made['hidden'];
        $start = (new \DateTimeImmutable('tomorrow', new \DateTimeZone(date_default_timezone_get())))->modify('+9 days')->setTime(8, 0);
        $this->db->executeStatement('UPDATE bundles SET available_from = :at WHERE id = :id', ['at' => $start->format(DATE_ATOM), 'id' => $later['id']]);
        $short = 'On sale from ' . $start->format('j M Y');
        $long = 'On sale from ' . $start->format('l j F Y') . ' at 08:00';
        $note = array_values(array_filter($this->bundles->forCourse($this->courses['a']['id'], null), static fn(array $n): bool => $n['title'] === $later['title']))[0] ?? null;
        self::assertSame($short, $note['on_sale_from_short'] ?? null, 'A course page offers it, saying when.');
        $card = array_values(array_filter($this->bundles->catalogueRow(null, null), static fn(array $c): bool => $c['title'] === $later['title']))[0] ?? null;
        self::assertSame($short, $card['availability_badge'] ?? null, 'All courses shows it, saying when.');
        self::assertContains($later['title'], array_column($this->bundles->catalogueRow($this->categories['root']['id'], null), 'title'), 'And its category.');
        self::assertContains($later['title'], array_column($this->bundles->catalogue(50, 0), 'title'), 'And the Bundles page.');
        $coursePage = $this->coursePage('a', null);
        self::assertStringContainsString($short, $coursePage);
        $page = InProcessPage::run(null, \CattoLearning\Http\Controller\BundleController::class, 'show', ['slug' => $later['slug']])['body'];
        self::assertStringContainsString($long, $page, 'Its page says when, in full, where its buttons will be.');
        self::assertStringNotContainsString('action="/cart/add-bundle"', $page, 'Nothing to buy before then.');
        foreach ([$coursePage, $page, $this->catalogue(null), InProcessPage::run(null, \CattoLearning\Http\Controller\BundleController::class, 'index')['body']] as $html) {
            self::assertStringNotContainsString('This bundle is not on sale yet.', $html);
        }
        $buyer = $this->viewer($this->fixture->person());
        try {
            IntegrationContainer::get()->get(OrderService::class)->changeBundle($buyer, $later['offer']);
            self::fail('The cart refuses it before then.');
        } catch (\RuntimeException $refused) {
            self::assertSame('This bundle can be bought from ' . $start->format('l j F Y') . ' at 08:00.', $refused->getMessage(), 'And says when it can be bought.');
        }
    }

    public function testACompanyAccountIsOfferedNoBundleOnACoursePageOrInTheCatalogue(): void
    {
        $this->hide();
        $private = $this->viewer($this->fixture->person());
        self::assertCount(3, $this->bundles->forCourse($this->courses['a']['id'], $private), 'A private account is offered them.');
        $staff = $this->viewer($this->fixture->staff(['id' => $this->company['id'], 'domain' => $this->company['domain']]));
        self::assertSame([], $this->bundles->forCourse($this->courses['a']['id'], $staff));
        self::assertSame([], $this->bundles->catalogueRow(null, $staff));
        self::assertSame([], $this->bundles->catalogueRow($this->categories['root']['id'], $staff));
        $html = $this->coursePage('a', $staff);
        self::assertStringNotContainsString('Also in', $html);
        self::assertStringNotContainsString('Add to cart', $html, 'Nor a buy button.');
        $catalogue = $this->catalogue('root', [], $staff);
        self::assertStringNotContainsString('id="catalogue-bundles"', $catalogue);
        self::assertStringContainsString(self::LINE, $catalogue, 'The line stays where no row shows.');
    }

    public function testAVisitorWhoHasSomeOfItsCoursesIsStillOfferedItAndToldHowMany(): void
    {
        $this->hide();
        $person = $this->fixture->person();
        $viewer = $this->viewer($person);
        $this->fixture->createEnrolment($person['id'], $this->courses['b']['id'], $this->company['founder']['id']);
        self::assertSame([1, 1, 0], array_column($this->bundles->forCourse($this->courses['a']['id'], $viewer), 'owned_count'));
        $html = $this->coursePage('a', $viewer);
        self::assertStringContainsString('You already have access to 1 of the 3 courses in this bundle.', $html);
        self::assertStringContainsString('You already have access to 1 of the 2 courses in this bundle.', $html);
        self::assertStringContainsString($this->rand(120000), $html, 'The price stays the bundle price.');

        // The course they are reading is theirs too: the bundle is still offered, as its own page offers it.
        $this->fixture->createEnrolment($person['id'], $this->courses['a']['id'], $this->company['founder']['id']);
        $owned = $this->coursePage('a', $viewer);
        self::assertStringContainsString('This course is already in your library.', $owned);
        self::assertStringContainsString('Also in ' . $this->made['small']['title'], $owned);
        self::assertStringContainsString('You already have access to all 2 courses in this bundle.', $owned);
    }

    public function testABundleAnOrderOfTheirsAlreadyHoldsIsNotOfferedAgain(): void
    {
        $this->hide();
        $buyer = $this->viewer($this->fixture->person());
        $orders = IntegrationContainer::get()->get(OrderService::class);
        $orders->changeBundle($buyer, $this->made['small']['offer']);
        $cart = $orders->cart($buyer);
        $orders->place($buyer, (int) $cart['id'], (string) $cart['quote'], BillingFixture::person(), true);
        self::assertSame($this->titles('big', 'plain'), array_column($this->bundles->forCourse($this->courses['a']['id'], $buyer), 'title'), 'Checkout would refuse it.');
        self::assertSame($this->titles('big', 'small', 'plain'), array_column($this->bundles->forCourse($this->courses['a']['id'], null), 'title'), 'Anyone else is offered it.');
    }

    public function testTheCatalogueShowsTheBundlesOfACategorysBranchFirstAndEveryBundleOnAllCourses(): void
    {
        $this->hide();
        foreach (['root', 'branch', 'leaf'] as $category) {
            self::assertSame($this->titles('big', 'small', 'plain'), array_column($this->bundles->catalogueRow($this->categories[$category]['id'], null), 'title'), $category . ': the bundles with a course in its branch, the larger saving first.');
        }
        self::assertSame($this->titles('elsewhere'), array_column($this->bundles->catalogueRow($this->categories['other']['id'], null), 'title'));
        self::assertSame([], $this->bundles->catalogueRow($this->categories['empty']['id'], null));
        $all = array_column($this->bundles->catalogueRow(null, null), 'title');
        self::assertSame($this->titles('elsewhere', 'big', 'small', 'plain'), array_values(array_intersect($all, $this->titles('elsewhere', 'big', 'small', 'plain'))), 'All courses: every bundle, the larger saving first.');
        // Exactly the Bundles page's bundles that can be bought now (its cards add "Not available now" to the others).
        $onSale = array_column(array_filter($this->bundles->catalogue(500, 0), static fn(array $card): bool => count($card['facts']) === 3), 'title');
        sort($onSale);
        $sorted = $all;
        sort($sorted);
        self::assertSame($onSale, $sorted);

        $card = $this->bundles->catalogueRow($this->categories['root']['id'], null)[0];
        self::assertSame('Save ' . $this->rand(40000), $card['saving_badge'], 'The card says what it saves, as the mockup draws it.');
        self::assertSame(['label' => 'Price', 'value' => $this->rand(120000), 'was' => $this->rand(160000)], $card['facts'][2]);
        self::assertSame(['/bundles/' . $this->made['big']['slug'], 'View bundle'], [$card['href'], $card['link_label']], 'The Bundles page\'s card.');
        self::assertArrayNotHasKey('saving_percent', $card);

        $html = $this->catalogue('root');
        foreach (['id="catalogue-bundles"', '>Save with a bundle</h3>', 'Bundles with a course in ' . $this->categories['root']['name'] . '.', 'View all bundles', '>Courses</h3>'] as $shown) self::assertStringContainsString($shown, $html, $shown);
        self::assertStringNotContainsString(self::LINE, $html, 'The row replaces the line.');
        $row = (string) strstr((string) strstr($html, 'id="catalogue-bundles"'), '>Courses</h3>', true);
        $at = array_map(fn(string $key): int|false => strpos($row, '>' . $this->made[$key]['title'] . '</a>'), ['big', 'small', 'plain']);
        self::assertNotContains(false, $at, 'Each card is in the row, above the courses.');
        self::assertTrue($at[0] < $at[1] && $at[1] < $at[2]);
        self::assertStringNotContainsString($this->made['elsewhere']['title'], $html);
        self::assertStringNotContainsString($this->courses['a']['title'], $row, 'The courses come after the row.');

        $everything = $this->catalogue(null);
        self::assertStringContainsString('id="catalogue-bundles"', $everything);
        self::assertStringContainsString('Several courses for one price.</p>', $everything);
        self::assertStringNotContainsString(self::LINE, $everything);
        $none = $this->catalogue('empty');
        self::assertStringNotContainsString('id="catalogue-bundles"', $none, 'No bundle has a course here.');
        self::assertStringContainsString(self::LINE, $none, 'So the line stays.');
    }

    public function testNoRowWithASearchOnALaterPageOrOnATagPage(): void
    {
        $this->hide();
        $search = $this->catalogue('root', ['catalogue_q' => $this->courses['a']['title']]);
        self::assertStringContainsString('Search results', $search);
        self::assertStringNotContainsString('id="catalogue-bundles"', $search);
        self::assertStringContainsString(self::LINE, $search, 'The line stays with a search.');
        self::assertStringNotContainsString('id="catalogue-bundles"', $this->catalogue(null, ['catalogue_q' => $this->courses['a']['title']]));

        // A second page of the branch.
        for ($n = 0; $n < 24; $n++) {
            $id = $this->fixture->createCourse($this->company['founder']['id'], $this->company['id'], 'bundle-row-page-' . $n . '-' . $this->fixture->suffix(), 'Garden design practice set', 'published');
            $this->db->executeStatement('UPDATE courses SET category_id = :c WHERE id = :id', ['c' => $this->categories['root']['id'], 'id' => $id]);
        }
        self::assertStringContainsString('id="catalogue-bundles"', $this->catalogue('root', ['page' => '1']));
        $later = $this->catalogue('root', ['page' => '2']);
        self::assertStringContainsString('Pg 2 of 2', $later);
        self::assertStringNotContainsString('id="catalogue-bundles"', $later);
        self::assertStringContainsString(self::LINE, $later, 'The line stays on a later page.');

        $tag = 'bundle-row-tag-' . $this->fixture->suffix();
        $tagId = (int) $this->db->fetchOne('INSERT INTO tags (name, slug) VALUES (:name, :slug) RETURNING id', ['name' => 'Garden design practice', 'slug' => $tag]);
        $this->db->executeStatement('INSERT INTO course_tags (course_id, tag_id) VALUES (:course, :tag)', ['course' => $this->courses['a']['id'], 'tag' => $tagId]);
        $tagPage = InProcessPage::run(null, CourseTagController::class, 'tag', ['slug' => $tag]);
        self::assertSame(200, $tagPage['status']);
        self::assertStringContainsString($this->courses['a']['title'], $tagPage['body']);
        self::assertStringNotContainsString('id="catalogue-bundles"', $tagPage['body']);
        self::assertStringNotContainsString('Save with a bundle', $tagPage['body']);
    }

    /** Takes the bundle that saves the most off sale, for the tests that do not need it. */
    private function hide(): void
    {
        $this->db->executeStatement('UPDATE bundle_offers SET is_active = FALSE WHERE bundle_id = :id', ['id' => $this->made['hidden']['id']]);
    }

    /** @return list<string> */
    private function titles(string ...$keys): array
    {
        return array_map(fn(string $key): string => $this->made[$key]['title'], $keys);
    }

    /** @param array{id:int,email:string,name:string} $person */
    private function viewer(array $person): CurrentUser
    {
        return new CurrentUser($person['id'], Uuid::v4(), $person['email'], $person['name'], ['STUDENT'], self::PERMISSIONS, Uuid::v4());
    }

    private function coursePage(string $course, ?CurrentUser $viewer): string
    {
        $page = InProcessPage::run($viewer, CourseController::class, 'detail', ['slug' => $this->courses[$course]['slug']]);
        self::assertSame(200, $page['status']);
        return $page['body'];
    }

    /** @param array<string,string> $query */
    private function catalogue(?string $category, array $query = [], ?CurrentUser $viewer = null): string
    {
        $page = InProcessPage::run($viewer, CourseController::class, 'catalogue', $category === null ? [] : ['slug' => $this->categories[$category]['slug']], null, $query);
        self::assertSame(200, $page['status']);
        return $page['body'];
    }

    private function rand(int $minor): string
    {
        return Money::strictMinorUnits($minor, 'ZAR')->format();
    }
}
