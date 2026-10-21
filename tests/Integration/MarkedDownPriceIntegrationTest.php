<?php

declare(strict_types=1);

namespace CattoLearning\Tests\Integration;

use CattoLearning\Auth\AuthService;
use CattoLearning\Auth\CurrentUser;
use CattoLearning\Bundle\BundleService;
use CattoLearning\Commerce\Application\CartService;
use CattoLearning\Commerce\Application\OrderService;
use CattoLearning\Commerce\Infrastructure\CommerceRepository;
use CattoLearning\Course\CourseService;
use CattoLearning\Http\Controller\BundleController;
use CattoLearning\Http\Controller\CourseController;
use CattoLearning\Infrastructure\Persistence\Database;
use CattoLearning\Support\Money;
use CattoLearning\Support\Uuid;
use CattoLearning\Tests\Support\{BillingFixture, DevelopmentFixture, InProcessPage, IntegrationContainer, PromotionFixture, WalletFixture};
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * A price marked down from a higher was price (owner, 2026-10-20: "publish a larger price and then mark
 * it down to a lower price to create the impression it is on special"), and the automatic promotions a
 * course or bundle page announces. The was price is shown struck through everywhere the price is, kept
 * on the order as what was advertised, and never charged.
 */
#[Group('commerce')]
final class MarkedDownPriceIntegrationTest extends TestCase
{
    private const PERMISSIONS = ['COMMERCE.CART.VIEW', 'COMMERCE.CART.MANAGE', 'COMMERCE.CHECKOUT.START', 'COMMERCE.ORDER.VIEW', 'LEARNING.COURSE.START', 'ACCOUNT.PROFILE.EDIT'];

    private Database $db;
    private DevelopmentFixture $fixture;
    private CourseService $courses;
    private int $owner;
    private int $provider;

    protected function setUp(): void
    {
        $this->db = IntegrationContainer::db();
        $this->db->beginTransaction();
        WalletFixture::defaults($this->db);
        $this->fixture = new DevelopmentFixture($this->db);
        $this->courses = IntegrationContainer::get()->get(CourseService::class);
        $this->owner = $this->fixture->createUser('Marked-down course owner');
        $this->provider = $this->fixture->createCompany($this->owner, 'Marked-down provider ' . $this->fixture->suffix(), $this->fixture->suffix() . '.marked.example.test');
    }

    protected function tearDown(): void
    {
        if ($this->db->isTransactionActive()) $this->db->rollBack();
        unset($_SESSION['guest_cart'], $_SESSION['guest_bundles'], $_SESSION['flash']);
    }

    public function testAPriceOptionTakesAnOptionalWasPriceHigherThanItsPrice(): void
    {
        $course = $this->course('Was price course');
        $variant = $this->courses->createPriceVariant($course, ['access_period_value' => '12', 'access_period_unit' => 'months', 'price' => '1800', 'was_price' => '2 400.00', 'currency_code' => 'ZAR', 'is_active' => '1', 'is_default' => '1'], $this->owner);
        self::assertSame(240000, (int) $this->db->fetchOne('SELECT was_price_minor_units FROM course_price_variants WHERE id=:id', ['id' => $variant]));
        $price = $this->courses->activePriceVariants($course)[0];
        self::assertSame([$this->rand(180000), $this->rand(240000), $this->rand(60000) . ' (25%)', 25, '2400.00'],
            [$price['price_label'], $price['was_label'], $price['saving_label'], $price['saving_percent'], $price['was_price_input']]);

        foreach (['1800' => 'The was price must be higher than the price, or left empty.', '1500' => 'The was price must be higher than the price, or left empty.',
            'about 2000' => 'Enter the was price as an amount with no more than two decimal places, or leave it empty.'] as $was => $message) {
            try {
                $this->courses->updatePriceVariant($course, $variant, ['access_period_value' => '12', 'access_period_unit' => 'months', 'price' => '1800', 'was_price' => (string) $was, 'currency_code' => 'ZAR', 'is_active' => '1'], $this->owner);
                self::fail('A was price of ' . $was . ' is refused.');
            } catch (InvalidArgumentException $refused) {
                self::assertSame($message, $refused->getMessage());
            }
        }
        $this->courses->updatePriceVariant($course, $variant, ['access_period_value' => '12', 'access_period_unit' => 'months', 'price' => '1800', 'was_price' => '', 'currency_code' => 'ZAR', 'is_active' => '1'], $this->owner);
        self::assertNull($this->db->fetchOne('SELECT was_price_minor_units FROM course_price_variants WHERE id=:id', ['id' => $variant]), 'An empty field takes the markdown away.');
        self::assertNull($this->courses->activePriceVariants($course)[0]['was_label']);
        try {
            $this->db->executeStatement('UPDATE course_price_variants SET was_price_minor_units=price_minor_units WHERE id=:id', ['id' => $variant]);
            self::fail('The database refuses a was price that is not higher than the price.');
        } catch (\Doctrine\DBAL\Exception $refused) {
            self::assertStringContainsString('course_price_variants_was_price', $refused->getMessage());
            $this->db->rollBack();
            $this->db->beginTransaction();
        }
    }

    public function testTheCourseCardAndCoursePageStrikeThroughTheWasPrice(): void
    {
        $course = $this->course('Struck through course', 'published');
        $this->courses->createPriceVariant($course, ['access_period_value' => '12', 'access_period_unit' => 'months', 'price' => '1800', 'was_price' => '2400', 'currency_code' => 'ZAR', 'is_active' => '1', 'is_default' => '1'], $this->owner);
        $card = $this->courses->cardsByIds([$course])[0];
        self::assertSame([$this->rand(180000), $this->rand(240000), 25], [$card['default_price_label'], $card['default_was_label'], $card['saving_percent']], 'The card flags it as a special.');

        $page = $this->page((string) $this->db->fetchOne('SELECT slug FROM courses WHERE id=:id', ['id' => $course]));
        $purchase = '//*[contains(@class,"cl-course-purchase")]';
        self::assertSame(1, $page->query($purchase . '//s[contains(@class,"cl-price-was") and contains(normalize-space(),"' . $this->rand(240000) . '")]')->length, 'The was price is struck through.');
        self::assertSame(1, $page->query($purchase . '//*[contains(@class,"cl-price-now") and normalize-space()="' . $this->rand(180000) . '"]')->length);
        self::assertSame(1, $page->query($purchase . '//*[contains(@class,"cl-price-saving") and normalize-space()="Save ' . $this->rand(60000) . ' (25%)"]')->length);
        self::assertSame(1, $page->query($purchase . '//s//*[contains(@class,"visually-hidden") and normalize-space()="Was"]')->length, 'A screen reader hears that it was the earlier price.');
    }

    public function testTheCartShowsTheWasPriceAndTheOrderKeepsItButChargesOnlyThePrice(): void
    {
        $course = $this->course('Cart markdown course', 'published');
        $variant = $this->courses->createPriceVariant($course, ['access_period_value' => '12', 'access_period_unit' => 'months', 'price' => '1800', 'was_price' => '2400', 'currency_code' => 'ZAR', 'is_active' => '1', 'is_default' => '1'], $this->owner);
        $buyer = $this->buyer();
        $orders = IntegrationContainer::get()->get(OrderService::class);
        $orders->changeCart($buyer, $variant);
        $cart = $this->cart($buyer);
        self::assertSame([$this->rand(240000), $this->rand(60000) . ' (25%)', 180000], [$cart['items'][0]['was_label'], $cart['items'][0]['saving_label'], $cart['total_minor']], 'Only the price counts towards the total.');

        $priced = $orders->cart($buyer);
        $orderId = $orders->place($buyer, (int) $priced['id'], (string) $priced['quote'], BillingFixture::person('Markdown Buyer'), true, ['payment_method' => 'eft']);
        $snapshot = CommerceRepository::decode((string) (new CommerceRepository($this->db))->order($orderId)['snapshot']);
        self::assertSame([180000, 240000, 180000], [$snapshot['items'][0]['unit_price_minor'], $snapshot['items'][0]['was_price_minor'], $snapshot['total_minor']]);
    }

    public function testABundleOfferTakesAWasPriceAndItsPageStrikesItThrough(): void
    {
        $bundles = IntegrationContainer::get()->get(BundleService::class);
        $admin = new CurrentUser($this->owner, Uuid::v4(), 'admin-' . $this->owner . '@example.invalid', 'Administrator', ['ADMIN'], (new \CattoLearning\Auth\PermissionCatalog())->keys(), Uuid::v4());
        $id = $bundles->create($admin, ['title' => 'Marked-down bundle ' . $this->fixture->suffix(), 'short_description' => 'Two courses.']);
        foreach (['Bundle course one', 'Bundle course two'] as $title) {
            $course = $this->course($title, 'published');
            $this->fixture->createPriceVariant($course, $this->owner, 31536000, 150000);
            $bundles->addCourse($admin, $id, $course);
        }
        try {
            $bundles->saveOffer($admin, $id, ['price' => '2500', 'was_price' => '2000', 'access_period_value' => '1', 'access_period_unit' => 'years', 'is_active' => '1']);
            self::fail('A was price below the bundle price is refused.');
        } catch (RuntimeException $refused) {
            self::assertSame('The was price must be higher than the price, or left empty.', $refused->getMessage());
        }
        $bundles->saveOffer($admin, $id, ['price' => '2500', 'was_price' => '3200', 'access_period_value' => '1', 'access_period_unit' => 'years', 'is_active' => '1']);
        $bundles->setStatus($admin, $id, 'published');
        $slug = (string) $this->db->fetchOne('SELECT slug FROM bundles WHERE id=:id', ['id' => $id]);
        $detail = $bundles->detail($slug, null);
        self::assertSame([$this->rand(250000), $this->rand(320000), $this->rand(70000) . ' (21%)'], [$detail['price_label'], $detail['was_label'], $detail['saving_label']], '21.875% is rounded down, never up.');
        $card = array_values(array_filter($bundles->catalogue(200, 0), static fn(array $b): bool => $b['slug'] === $slug))[0];
        self::assertSame(['label' => 'Price', 'value' => $this->rand(250000), 'was' => $this->rand(320000)], $card['facts'][2]);
        self::assertSame(21, $card['saving_percent'], 'The bundle card flags it as a special.');

        $page = $this->html(InProcessPage::run(null, BundleController::class, 'show', ['slug' => $slug])['body']);
        self::assertSame(1, $page->query('//aside//s[contains(@class,"cl-price-was") and contains(normalize-space(),"' . $this->rand(320000) . '")]')->length);
        self::assertSame(1, $page->query('//aside//*[contains(@class,"cl-course-purchase-saving") and normalize-space()="You save ' . $this->rand(70000) . ' (21%)"]')->length);
    }

    public function testCourseAndBundlePagesAnnounceTheAutomaticPromotionsThatCoverThem(): void
    {
        $course = $this->course('Announced course', 'published');
        $this->fixture->createPriceVariant($course, $this->owner, 31536000, 200000);
        $other = $this->course('Other course', 'published');
        $this->fixture->createPriceVariant($other, $this->owner, 31536000, 120000);
        $code = 'SPRING' . strtoupper(bin2hex(random_bytes(3)));
        $minimum = 'BIGCART' . strtoupper(bin2hex(random_bytes(3)));
        PromotionFixture::create($this->db, $this->owner, ['code' => $code, 'name' => 'Spring special', 'discount_value' => 2500, 'applies_automatically' => true,
            'ends_at' => (new \DateTimeImmutable('2030-12-01 00:00', new \DateTimeZone(date_default_timezone_get())))->format(DATE_ATOM)], [$course]);
        PromotionFixture::create($this->db, $this->owner, ['code' => $minimum, 'discount_type' => 'fixed_amount', 'discount_value' => 30000, 'currency' => 'ZAR', 'minimum_order_minor' => 500000, 'applies_automatically' => true]);
        PromotionFixture::create($this->db, $this->owner, ['code' => 'TYPED' . strtoupper(bin2hex(random_bytes(3))), 'discount_value' => 5000]);
        PromotionFixture::create($this->db, $this->owner, ['code' => 'OFF' . strtoupper(bin2hex(random_bytes(3))), 'applies_automatically' => true, 'active' => false]);

        $promotions = PromotionFixture::service($this->db, new \Symfony\Component\Clock\MockClock(new \DateTimeImmutable()));
        self::assertSame([
            ['code' => $code, 'name' => 'Spring special', 'label' => '25% off', 'price_label' => $this->rand(150000), 'minimum_label' => null, 'until_label' => '30 November 2030'],
            ['code' => $minimum, 'name' => 'Test promotion', 'label' => $this->rand(30000) . ' off', 'price_label' => null, 'minimum_label' => $this->rand(500000), 'until_label' => null],
        ], $promotions->announcements('course', $course, 200000, 'ZAR'), 'A promo code typed at checkout, or one switched off, is never announced.');
        self::assertSame([$minimum], array_column($promotions->announcements('course', $other, 120000, 'ZAR'), 'code'), 'A promotion for selected courses is announced only on theirs.');

        $page = $this->page((string) $this->db->fetchOne('SELECT slug FROM courses WHERE id=:id', ['id' => $course]));
        $announced = '//*[contains(@class,"cl-course-purchase")]//p[contains(@class,"cl-promotion-announcement")]';
        self::assertSame(2, $page->query($announced)->length);
        self::assertSame(1, $page->query($announced . '[contains(normalize-space(),"' . $code . ' 25% off, applied automatically in your cart: you pay ' . $this->rand(150000) . ' instead of ' . $this->rand(200000) . '. Until 30 November 2030.")]')->length);
    }

    public function testTheAdministrationCourseListShowsWhatEachCourseCostsNow(): void
    {
        // Owner, 2026-10-20: "I need to see the current price in the table next to each course … If no price has been set, leave it blank".
        $marked = $this->course('Admin list priced course ' . $this->fixture->suffix(), 'published');
        $this->courses->createPriceVariant($marked, ['access_period_value' => '12', 'access_period_unit' => 'months', 'price' => '12000', 'was_price' => '25000', 'currency_code' => 'ZAR', 'is_active' => '1', 'is_default' => '1'], $this->owner);
        $unpriced = $this->course('Admin list unpriced course ' . $this->fixture->suffix());
        $admin = $this->fixture->createUser('Course list administrator');
        $search = 'Admin list';
        $data = IntegrationContainer::get()->get(\CattoLearning\Application\PlatformAdministrationService::class)
            ->sectionData('courses', $admin, ['courses_q' => $search], ['view_all_courses' => true]);
        $rows = array_column($data['courses'], null, 'id');
        self::assertSame(['price_label' => $this->rand(1200000), 'period_label' => '1 year', 'was_label' => $this->rand(2500000), 'saving_label' => $this->rand(1300000) . ' (52%)', 'saving_percent' => 52], $rows[$marked]['price']);
        self::assertNull($rows[$unpriced]['price'], 'No price set: the cell stays blank.');
        self::assertSame(['position', 'title', 'price', 'status', 'learners', 'updated', 'category', 'ownership', 'actions'], array_column($data['courses_sort_headers'], 'key'), 'The position leads (owner, 2026-10-20), the price follows the course; category and ownership come last; there is no Started column.');
    }

    private function course(string $title, string $status = 'draft'): int
    {
        return $this->fixture->createCourse($this->owner, $this->provider, 'marked-' . $this->fixture->suffix() . '-' . bin2hex(random_bytes(3)), $title, $status);
    }

    private function buyer(): CurrentUser
    {
        $id = $this->fixture->createUser('Markdown buyer', 'markdown-buyer-' . bin2hex(random_bytes(5)) . '@example.test');
        return new CurrentUser($id, Uuid::v4(), 'buyer-' . $id . '@example.invalid', 'Buyer ' . $id, ['STUDENT'], self::PERMISSIONS, Uuid::v4());
    }

    /** @return array<string,mixed> */
    private function cart(CurrentUser $buyer): array
    {
        $auth = clone IntegrationContainer::get()->get(AuthService::class);
        (new \ReflectionProperty(AuthService::class, 'currentUser'))->setValue($auth, $buyer);
        return (new CartService($auth, IntegrationContainer::get()->get(OrderService::class), new CommerceRepository($this->db), PromotionFixture::service($this->db, new \Symfony\Component\Clock\MockClock(new \DateTimeImmutable()))))->summary();
    }

    private function page(string $slug): \DOMXPath
    {
        $response = InProcessPage::run(null, CourseController::class, 'detail', ['slug' => $slug]);
        self::assertSame(200, $response['status']);
        return $this->html($response['body']);
    }

    private function html(string $body): \DOMXPath
    {
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $dom->loadHTML('<?xml encoding="UTF-8">' . $body);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        return new \DOMXPath($dom);
    }

    private function rand(int $minor): string
    {
        return Money::strictMinorUnits($minor, 'ZAR')->format();
    }
}
