<?php

declare(strict_types=1);

namespace CattoLearning\Tests\Integration;

use CattoLearning\Commerce\Document\FinancialDocumentDataBuilder;
use CattoLearning\Commerce\Document\FinancialDocuments;
use CattoLearning\Document\DocumentPdfRenderer;
use CattoLearning\Document\DocumentTemplateRenderer;
use CattoLearning\Analytics\AnalyticsEventRecorder;
use CattoLearning\Analytics\AnalyticsEventRepository;
use CattoLearning\Application\CliBootstrap;
use CattoLearning\Auth\AuthService;
use CattoLearning\Auth\CurrentUser;
use CattoLearning\Commerce\Application\{AccessService,BillingProfileService,CartService,CheckoutService,CompanyCreditFulfilment,FulfilmentService,OrderService,PaymentService,PromotionAdministrationService,PromotionService,RefundAdministrationService};
use CattoLearning\Commerce\Domain\{PaymentResult,PromotionRejected};
use CattoLearning\Commerce\Http\{CommerceController,PaymentAdministrationController};
use CattoLearning\Commerce\Infrastructure\{CommerceRepository,PromotionRepository};
use CattoLearning\Commerce\Infrastructure\Payment\OmnipayPaymentGatewayAdapter;
use CattoLearning\Commerce\Policy\CommercePolicy;
use CattoLearning\Commerce\Workflow\TransitionService;
use CattoLearning\Course\CourseRepository;
use CattoLearning\Infrastructure\Persistence\{AdministrationRepository,AuditRepository,Database,TransactionManager};
use CattoLearning\Support\{Money,Uuid};
use CattoLearning\Tests\Support\{BillingFixture,DevelopmentFixture,InProcessPage,IntegrationContainer,PromotionFixture};
use Doctrine\DBAL\Exception as DbalException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Promo codes at individual checkout, end to end on this test's own connection and clock: applying
 * and removing a code, every rejection, selected-course eligibility, revalidation at placement, the
 * server's sole authority over figures, the order snapshot and its documents, refunds bounded by
 * what each line was paid, usage held at placement and redeemed once when paid, the final use under
 * concurrency, a fully discounted order and the analytics events. Every write rolls back, except the
 * concurrency test's fixtures, which it commits on a second connection and removes afterwards.
 */
#[Group('commerce')]
final class PromotionCheckoutIntegrationTest extends TestCase
{
    private const PERMISSIONS = ['COMMERCE.CART.VIEW','COMMERCE.CART.MANAGE','COMMERCE.CHECKOUT.START','COMMERCE.ORDER.VIEW','LEARNING.COURSE.START','ACCOUNT.PROFILE.EDIT'];

    private Database $db;
    private DevelopmentFixture $fixture;
    private MockClock $clock;
    private CommerceRepository $records;
    private OrderService $orders;
    private PaymentService $payments;
    private FulfilmentService $fulfilment;
    private PromotionService $promotions;
    private BillingProfileService $billing;
    private int $owner;
    private int $provider;
    private int $courseA;
    private int $courseB;
    private int $variantA;
    private int $variantB;

    protected function setUp(): void
    {
        $this->db = IntegrationContainer::db();
        $this->db->beginTransaction();
        $this->fixture = new DevelopmentFixture($this->db);
        // Near real time: the customer order page cancels overdue orders by the real clock.
        $this->clock = new MockClock(new \DateTimeImmutable());
        [$this->records, $this->orders, $this->fulfilment, $this->payments, $this->promotions] = $this->services($this->db);
        $this->billing = BillingFixture::service($this->db, $this->clock);
        $this->owner = $this->fixture->createUser('Promotion course owner');
        $this->provider = $this->fixture->createCompany($this->owner, 'Promotion provider ' . $this->fixture->suffix(), $this->fixture->suffix() . '.promo.example.test');
        [$this->courseA, $this->variantA] = $this->paidCourse('Course A', 12345);
        [$this->courseB, $this->variantB] = $this->paidCourse('Course B', 5000);
        $_SESSION['csrf'] = str_repeat('p', 64);
    }

    protected function tearDown(): void
    {
        if ($this->db->isTransactionActive()) $this->db->rollBack();
        unset($_SESSION['checkout'], $_SESSION['csrf'], $_SESSION['flash'], $_SESSION['guest_cart']);
    }

    public function testAValidCodeIsAcceptedCaseInsensitivelyAndTheTotalsShowTheDiscount(): void
    {
        $buyer = $this->buyer();
        $this->fill($buyer, $this->variantA, $this->variantB);
        $code = $this->code('SAVE');
        $promotionId = PromotionFixture::create($this->db, $this->owner, ['code' => $code]);

        $discount = $this->promotions->apply($buyer, '  ' . strtolower($code) . ' ');
        self::assertSame([$code, 17345, 1735], [$discount->promotion->code, $discount->eligibleMinor, $discount->discountMinor], '10% of R173.45 is R17.345, rounded half up.');
        $cart = $this->summary($buyer);
        self::assertSame([17345, 1735, 15610], [$cart['subtotal_minor'], $cart['discount_minor'], $cart['total_minor']]);
        self::assertSame([['label' => 'Subtotal', 'value' => $this->rand(17345)], ['label' => 'Promo ' . $code . ' (10% off)', 'value' => '−' . $this->rand(1735)], ['label' => 'Total', 'value' => $this->rand(15610)]], $cart['totals']);
        self::assertSame(['code' => $code, 'applied' => true, 'rejection' => null, 'label' => '10% off', 'discount_label' => '−' . $this->rand(1735)], $cart['promo']);

        $events = $this->events('promo_code_applied', $buyer->id);
        self::assertCount(1, $events, 'The accepted code is recorded once.');
        self::assertSame(['bundle_ids', 'cart_id', 'course_ids', 'currency', 'discount_minor', 'discount_type', 'eligible_minor', 'promotion_id'], array_keys(self::sorted($events[0]['metadata'])), 'Ids and figures only: no description, name or customer details.');
        self::assertSame([$promotionId, 1735, 17345, [$this->courseA, $this->courseB]], [$events[0]['metadata']['promotion_id'], $events[0]['metadata']['discount_minor'], $events[0]['metadata']['eligible_minor'], $events[0]['metadata']['course_ids']]);

        $this->promotions->apply($buyer, $code);
        self::assertCount(1, $this->events('promo_code_applied', $buyer->id), 'Applying the code already applied changes nothing and records nothing.');
        $this->expectRejected('We don’t recognise that promo code.', fn() => $this->promotions->apply($buyer, 'NO-SUCH-CODE'));
        $this->expectRejected('We don’t recognise that promo code.', fn() => $this->promotions->apply($buyer, 'bad code!'));
        self::assertCount(1, $this->events('promo_code_applied', $buyer->id), 'A rejected code records no application.');
        self::assertSame($code, $this->summary($buyer)['promo']['code'], 'and leaves the applied code in place.');
    }

    public function testACodeThatCannotApplySaysWhyAndChangesNothing(): void
    {
        $buyer = $this->buyer();
        $this->fill($buyer, $this->variantA);
        $now = $this->clock->now();
        $cases = [
            'That promo code is not currently available.' => ['active' => false],
            'That promo code is not valid yet.' => ['starts_at' => $now->modify('+1 hour')->format(DATE_ATOM)],
            'That promo code has expired.' => ['ends_at' => $now->format(DATE_ATOM)],
            'That promo code needs at least ' . $this->rand(20000) . ' of eligible items in your cart.' => ['minimum_order_minor' => 20000, 'currency' => 'ZAR'],
            'That promo code cannot be used with this currency.' => ['discount_type' => 'fixed_amount', 'discount_value' => 1000, 'currency' => 'USD'],
        ];
        foreach ($cases as $message => $overrides) {
            $code = PromotionFixture::create($this->db, $this->owner, $overrides) > 0 ? (string) $this->db->fetchOne('SELECT code FROM promotions ORDER BY id DESC LIMIT 1') : '';
            $this->expectRejected($message, fn() => $this->promotions->apply($buyer, $code));
        }
        $selected = $this->code('ONLYB');
        PromotionFixture::create($this->db, $this->owner, ['code' => $selected], [$this->courseB]);
        $this->expectRejected('That promo code does not apply to anything in your cart.', fn() => $this->promotions->apply($buyer, $selected));
        self::assertNull($this->cartPromotion($buyer), 'No rejected code reached the cart.');
        self::assertSame([], $this->events('promo_code_applied', $buyer->id));
    }

    public function testUsageLimitsCountRealOrdersAndACancelledOrderReleasesItsUse(): void
    {
        $code = $this->code('ONCE');
        PromotionFixture::create($this->db, $this->owner, ['code' => $code, 'maximum_total_uses' => 1]);
        $first = $this->buyer();
        $this->fill($first, $this->variantA);
        $this->promotions->apply($first, $code);
        $order = $this->placeDirect($first);
        $second = $this->buyer();
        $this->fill($second, $this->variantA);
        $this->expectRejected('That promo code has reached its usage limit.', fn() => $this->promotions->apply($second, $code), 'An unpaid order holds the only use.');

        $this->clock->modify('+8 days');
        self::assertTrue($this->orders->cancelDue($order), 'The unpaid order passes its payment deadline.');
        self::assertSame($code, $this->promotions->apply($second, $code)->promotion->code, 'Its cancellation released the use.');

        $mine = $this->code('MINE');
        PromotionFixture::create($this->db, $this->owner, ['code' => $mine, 'maximum_uses_per_customer' => 1]);
        $third = $this->buyer();
        $this->fill($third, $this->variantA);
        $this->promotions->apply($third, $mine);
        $this->placeDirect($third);
        $this->fill($third, $this->variantB);
        $this->expectRejected('You have already used that promo code as many times as it allows. An unpaid order that uses it counts until it is paid or cancelled.', fn() => $this->promotions->apply($third, $mine));
        self::assertSame($mine, $this->promotions->apply($second, $mine)->promotion->code, 'Another customer still can: the limit is per authenticated purchaser.');
    }

    public function testASelectedCoursePromotionDiscountsOnlyItsLinesThroughToTheOrder(): void
    {
        $buyer = $this->buyer();
        $this->fill($buyer, $this->variantA, $this->variantB);
        $code = $this->code('ACOURSE');
        PromotionFixture::create($this->db, $this->owner, ['code' => $code, 'discount_value' => 2000], [$this->courseA]);
        self::assertSame(2469, $this->promotions->apply($buyer, $code)->discountMinor, '20% of course A only: R24.69.');
        $order = $this->records->order($this->placeDirect($buyer));
        self::assertSame([14876, 2469], [(int) $order['total_minor'], (int) $order['discount_minor']]);
        $lines = [];
        foreach ($this->records->items((int) $order['id']) as $item) $lines[(int) $item['course_id']] = [(int) $item['amount_minor'], (int) $item['discount_minor']];
        self::assertSame([$this->courseA => [12345, 2469], $this->courseB => [5000, 0]], $lines, 'Line prices are unchanged; only the eligible line carries a discount.');
        $snapshot = CommerceRepository::decode((string) $order['snapshot']);
        self::assertSame([[$this->courseA], 12345, 2469], [$snapshot['promotion']['eligible_course_ids'], $snapshot['promotion']['eligible_minor'], $snapshot['promotion']['discount_minor']]);
        self::assertSame([[12345, 2469, 9876, true], [5000, 0, 5000, false]], array_map(static fn(array $l): array => [$l['line_total_minor'], $l['discount_minor'], $l['paid_minor'], $l['promotion_eligible']], $snapshot['items']));
    }

    public function testOneCodeAppliesAtATimeAndRemovingItRestoresTheTotal(): void
    {
        $buyer = $this->buyer();
        $this->fill($buyer, $this->variantA, $this->variantB);
        [$ten, $fifty, $off] = [$this->code('TEN'), $this->code('FIFTY'), $this->code('OFF')];
        PromotionFixture::create($this->db, $this->owner, ['code' => $ten]);
        PromotionFixture::create($this->db, $this->owner, ['code' => $fifty, 'discount_type' => 'fixed_amount', 'discount_value' => 5000, 'currency' => 'ZAR']);
        PromotionFixture::create($this->db, $this->owner, ['code' => $off, 'active' => false]);
        $this->promotions->apply($buyer, $ten);
        $this->promotions->apply($buyer, $fifty);
        self::assertSame([$fifty, 5000, 12345], [$this->summary($buyer)['promo']['code'], $this->summary($buyer)['discount_minor'], $this->summary($buyer)['total_minor']], 'An accepted second code replaces the first; the codes never stack.');
        $this->expectRejected('That promo code is not currently available.', fn() => $this->promotions->apply($buyer, $off));
        self::assertSame($fifty, $this->summary($buyer)['promo']['code'], 'A rejected code leaves the current one.');
        self::assertTrue($this->promotions->remove($buyer));
        $cart = $this->summary($buyer);
        self::assertSame([null, 0, 17345], [$cart['promo']['code'], $cart['discount_minor'], $cart['total_minor']]);
        self::assertSame([['label' => 'Total', 'value' => $this->rand(17345)]], $cart['totals'], 'Without a promotion only the undiscounted total is shown.');
        self::assertFalse($this->promotions->remove($buyer), 'Nothing left to remove.');
        self::assertSame(5000, (int) $this->db->fetchOne('SELECT price_minor_units FROM course_price_variants WHERE id=:id', ['id' => $this->variantB]), 'No price was changed.');
    }

    public function testPlacementRevalidatesThePromotionUnderItsLock(): void
    {
        $buyer = $this->buyer();
        $this->ready($buyer);
        $code = $this->code('SOON');
        $id = PromotionFixture::create($this->db, $this->owner, ['code' => $code, 'ends_at' => $this->clock->now()->modify('+10 minutes')->format(DATE_ATOM)]);
        $this->fill($buyer, $this->variantA, $this->variantB);
        $this->promotions->apply($buyer, $code);
        $cart = $this->orders->cart($buyer);
        $this->clock->modify('+10 minutes');
        $this->expectRejected('That promo code has expired.', fn() => $this->checkout($buyer)->place($buyer, (int) $cart['id'], (string) $cart['quote'], true), 'Valid when the review was shown is not enough.');
        self::assertSame(0, $this->orderCount($buyer));

        $this->db->executeStatement('UPDATE promotions SET ends_at=NULL WHERE id=:id', ['id' => $id]);
        $this->db->executeStatement("UPDATE promotions SET course_scope='selected' WHERE id=:id", ['id' => $id]);
        $this->db->executeStatement('INSERT INTO promotion_courses(promotion_id,course_id) VALUES (:p,:c)', ['p' => $id, 'c' => $this->fixture->createCourse($this->owner, $this->provider, 'promo-other-' . $this->fixture->suffix(), 'Other course', 'published')]);
        $this->expectRejected('That promo code does not apply to anything in your cart.', fn() => $this->checkout($buyer)->place($buyer, (int) $cart['id'], (string) $cart['quote'], true), 'The cart\'s courses left the promotion.');

        $this->db->executeStatement("UPDATE promotions SET course_scope='all', maximum_total_uses=1 WHERE id=:id", ['id' => $id]);
        $other = $this->buyer();
        $this->fill($other, $this->variantA);
        $this->promotions->apply($other, $code);
        $this->placeDirect($other);
        $this->expectRejected('That promo code has reached its usage limit.', fn() => $this->checkout($buyer)->place($buyer, (int) $cart['id'], (string) $cart['quote'], true), 'Another customer took the last use meanwhile.');

        $this->db->executeStatement('UPDATE promotions SET maximum_total_uses=NULL, discount_value=1500 WHERE id=:id', ['id' => $id]);
        try {
            $this->checkout($buyer)->place($buyer, (int) $cart['id'], (string) $cart['quote'], true);
            self::fail('A changed discount must be reviewed before ordering.');
        } catch (\RuntimeException $changed) {
            self::assertNotInstanceOf(PromotionRejected::class, $changed);
            self::assertSame('Your cart or prices changed. Review your order again.', $changed->getMessage());
        }
        self::assertSame(0, $this->orderCount($buyer), 'Nothing was placed at an outdated price.');
    }

    public function testThePlaceRouteRemovesACodeThatStoppedApplyingAndReturnsToTheReview(): void
    {
        $buyer = $this->buyer();
        $this->ready($buyer);
        $code = $this->code('LATE');
        $id = PromotionFixture::create($this->db, $this->owner, ['code' => $code]);
        $this->fill($buyer, $this->variantA);
        $this->promotions->apply($buyer, $code);
        $cart = $this->orders->cart($buyer);
        $this->db->executeStatement('UPDATE promotions SET active=false WHERE id=:id', ['id' => $id]);

        $page = InProcessPage::run($buyer, CommerceController::class, 'place', [], ['csrf' => $_SESSION['csrf'], 'cart_id' => (string) $cart['id'], 'quote' => (string) $cart['quote'], 'accept_terms' => 'yes']);
        self::assertSame('/checkout/review', $page['location']);
        self::assertSame(['type' => 'warning', 'message' => 'Promo code ' . $code . ' was removed from your order. That promo code is not currently available. Check your new total before placing your order.'], end($_SESSION['flash']));
        self::assertNull($this->cartPromotion($buyer));
        self::assertSame(0, $this->orderCount($buyer));
        $review = InProcessPage::run($buyer, CommerceController::class, 'checkout', ['step' => 'review']);
        self::assertStringContainsString($this->rand(12345), $review['body']);
        self::assertStringNotContainsString('Promo ' . $code, $review['body'], 'The review shows the corrected, undiscounted total.');
    }

    public function testTheServerIgnoresAnyDiscountOrTotalTheBrowserSends(): void
    {
        $buyer = $this->buyer();
        $this->ready($buyer);
        $this->fill($buyer, $this->variantA, $this->variantB);
        $code = $this->code('HONEST');
        PromotionFixture::create($this->db, $this->owner, ['code' => $code]);
        $forged = ['discount_minor' => '17344', 'discount' => '17344', 'percentage' => '100', 'discount_value' => '10000', 'total' => '1', 'total_minor' => '1'];

        $applied = InProcessPage::run($buyer, CommerceController::class, 'applyPromo', [], ['csrf' => $_SESSION['csrf'], 'promo_code' => strtolower($code)] + $forged);
        self::assertSame('/checkout/review', $applied['location']);
        self::assertSame(1735, $this->summary($buyer)['discount_minor'], 'Only the code was used: the discount is the server\'s 10%.');
        $review = InProcessPage::run($buyer, CommerceController::class, 'checkout', ['step' => 'review']);
        foreach ([$code, '10% off', 'Promo ' . $code . ' (10% off)', $this->rand(17345), '−' . $this->rand(1735), $this->rand(15610)] as $shown) {
            self::assertStringContainsString(htmlspecialchars($shown, ENT_QUOTES), $review['body'], $shown);
        }
        foreach (['action="/checkout/promo/remove"', 'action="/checkout/promo"', 'name="promo_code"'] as $markup) self::assertStringContainsString($markup, $review['body']);
        $cart = $this->orders->cart($buyer);
        $placed = InProcessPage::run($buyer, CommerceController::class, 'place', [], ['csrf' => $_SESSION['csrf'], 'cart_id' => (string) $cart['id'], 'quote' => (string) $cart['quote'], 'accept_terms' => 'yes'] + $forged);
        $order = $this->records->order((int) substr((string) $placed['location'], strlen('/account/orders/')));
        self::assertSame([15610, 1735], [(int) $order['total_minor'], (int) $order['discount_minor']], 'The order carries the server\'s figures, not the forged ones.');
    }

    public function testTheOrderAndItsDocumentsKeepThePromotionAsItWasApplied(): void
    {
        $buyer = $this->buyer();
        $this->ready($buyer, 'dummy');
        $this->fill($buyer, $this->variantA, $this->variantB);
        $code = $this->code('KEEP');
        $id = PromotionFixture::create($this->db, $this->owner, ['code' => $code, 'name' => 'Spring offer']);
        $this->promotions->apply($buyer, $code);
        $cart = $this->orders->cart($buyer);
        $orderId = (int) $this->checkout($buyer)->place($buyer, (int) $cart['id'], (string) $cart['quote'], true);
        $order = $this->records->order($orderId);
        self::assertSame('fulfilled', $order['state']);
        $snapshot = CommerceRepository::decode((string) $order['snapshot']);
        self::assertSame([17345, 1735, 15610], [$snapshot['subtotal_minor'], $snapshot['discount_minor'], $snapshot['total_minor']]);
        $expected = ['promotion_id' => $id, 'code' => $code, 'name' => 'Spring offer', 'discount_type' => 'percentage', 'discount_value' => 1000, 'label' => '10% off',
            'course_scope' => 'all', 'bundle_scope' => 'none', 'eligible_course_ids' => [$this->courseA, $this->courseB], 'eligible_bundle_ids' => [], 'minimum_order_minor' => null, 'starts_at' => null, 'ends_at' => null,
            'currency' => 'ZAR', 'eligible_minor' => 17345, 'discount_minor' => 1735, 'allocation' => 'proportional_largest_remainder'];
        self::assertEquals($expected, $snapshot['promotion']);
        $before = [$order, $this->documentsHtml($orderId), $this->db->fetchAllAssociative('SELECT * FROM commerce_order_items WHERE order_id=:id ORDER BY id', ['id' => $orderId])];

        $admin = $this->admin();
        $administration = $this->administration();
        $administration->update($admin, $id, ['code' => $code . 'X', 'name' => 'Renamed offer', 'discount_type' => 'percentage', 'discount_value' => '50', 'course_scope' => 'selected', 'bundle_scope' => 'none', 'course_ids' => [(string) $this->courseB], 'active' => '1']);
        $administration->setActive($admin, $id, false);
        self::assertEquals($before, [$this->records->order($orderId), $this->documentsHtml($orderId), $this->db->fetchAllAssociative('SELECT * FROM commerce_order_items WHERE order_id=:id ORDER BY id', ['id' => $orderId])], 'Editing and deactivating the promotion changed nothing about the order or its documents.');
        foreach ($this->documentsHtml($orderId) as $kind => $html) {
            foreach (['Promotion ' . $code . ' (10% off)', '−' . $this->rand(1735), $this->rand(17345), $this->rand(15610)] as $shown) self::assertStringContainsString(htmlspecialchars($shown, ENT_QUOTES), $html, $kind . ': ' . $shown);
            self::assertStringNotContainsString('Renamed offer', $html);
        }
        $customer = InProcessPage::run($buyer, CommerceController::class, 'order', ['id' => (string) $orderId]);
        $administrator = InProcessPage::run($admin, PaymentAdministrationController::class, 'order', ['id' => (string) $orderId]);
        foreach ([$customer['body'], $administrator['body']] as $page) {
            self::assertStringContainsString(htmlspecialchars('Promo ' . $code . ' (10% off)', ENT_QUOTES), $page);
            self::assertStringContainsString(htmlspecialchars('−' . $this->rand(1735), ENT_QUOTES), $page);
            self::assertStringNotContainsString('50% off', $page);
        }
        self::assertStringContainsString('Spring offer', $administrator['body'], 'The ADMIN order page names the promotion as applied.');
    }

    public function testDiscountedLinesRefundOnlyWhatWasPaidForThem(): void
    {
        $buyer = $this->buyer();
        $this->ready($buyer, 'dummy');
        $this->fill($buyer, $this->variantA, $this->variantB);
        $code = $this->code('REFUND');
        PromotionFixture::create($this->db, $this->owner, ['code' => $code]);
        $this->promotions->apply($buyer, $code);
        $cart = $this->orders->cart($buyer);
        $orderId = (int) $this->checkout($buyer)->place($buyer, (int) $cart['id'], (string) $cart['quote'], true);
        $items = [];
        foreach ($this->records->items($orderId) as $item) $items[(int) $item['course_id']] = $item;
        // 1735 over 12345 and 5000: shares 1234.79… and 500.20…; the left-over cent goes to the larger remainder.
        self::assertSame([[12345, 1235], [5000, 500]], [[(int) $items[$this->courseA]['amount_minor'], (int) $items[$this->courseA]['discount_minor']], [(int) $items[$this->courseB]['amount_minor'], (int) $items[$this->courseB]['discount_minor']]]);
        self::assertSame([11110, 4500], [RefundAdministrationService::paidAmount($items[$this->courseA]), RefundAdministrationService::paidAmount($items[$this->courseB])]);

        $refunds = $this->refunds();
        $admin = $this->admin();
        $itemA = (int) $items[$this->courseA]['id'];
        $itemB = (int) $items[$this->courseB]['id'];
        foreach ([12345, 11111] as $tooMuch) {
            try {
                $refunds->approve($admin, $orderId, $itemA, Uuid::v4(), 'voluntary', 'Customer request', 1, $tooMuch);
                self::fail('A refund above what was paid for the line must be refused.');
            } catch (\RuntimeException $refused) {
                self::assertSame('The refund exceeds this item’s unrefunded paid amount.', $refused->getMessage());
            }
        }
        $refundA = $refunds->approve($admin, $orderId, $itemA, Uuid::v4(), 'voluntary', 'Customer request', 1, 11110);
        self::assertSame('partially_refunded', $this->records->order($orderId)['state']);
        self::assertSame('revoked', (string) $this->db->fetchOne('SELECT state FROM commerce_entitlements WHERE order_item_id=:id', ['id' => $itemA]), 'Refunding everything paid for the line is a full refund.');
        $note = $this->db->fetchAssociative("SELECT * FROM commerce_documents WHERE order_id=:o AND kind='credit_note' AND source_key=:k", ['o' => $orderId, 'k' => 'refund:' . $refundA]) ?: [];
        $noteSnapshot = CommerceRepository::decode((string) $note['snapshot']);
        self::assertSame(11110, $noteSnapshot['total_minor']);
        self::assertEquals(['price_minor' => 12345, 'discount_minor' => 1235, 'paid_minor' => 11110, 'quantity' => 1, 'promotion_code' => $code], $noteSnapshot['refund_line'], 'The credit note states the line\'s price, its promotion share and what was paid for it.');
        self::assertArrayNotHasKey('promotion', $noteSnapshot, 'The credit note states its own amount, not the order\'s totals.');
        $noteHtml = IntegrationContainer::get()->get(FinancialDocuments::class)->render($note)->html;
        self::assertStringContainsString('<th>Amount credited</th><td>' . htmlspecialchars($this->rand(11110), ENT_QUOTES) . '</td>', $noteHtml);
        // The line as it was paid: its price, its share of the promotion, what was paid and what this refund credits.
        foreach ([$this->rand(12345), '−' . $this->rand(1235), $this->rand(11110), 'Discount from promotion ' . $code] as $shown) self::assertStringContainsString(htmlspecialchars($shown, ENT_QUOTES), $noteHtml, $shown);

        $refunds->approve($admin, $orderId, $itemB, Uuid::v4(), 'voluntary', 'Customer request', 1, 4000);
        try {
            $refunds->approve($admin, $orderId, $itemB, Uuid::v4(), 'voluntary', 'Customer request', 1, 501);
            self::fail('Only R5.00 of what was paid for course B is left to refund.');
        } catch (\RuntimeException $refused) {
            self::assertSame('The refund exceeds this item’s unrefunded paid amount.', $refused->getMessage());
        }
        $refunds->approve($admin, $orderId, $itemB, Uuid::v4(), 'voluntary', 'Customer request', 1, 500);
        self::assertSame([15610, 15610, 'refunded'], [$this->records->refundedAmount($orderId), (int) $this->records->order($orderId)['total_minor'], $this->records->order($orderId)['state']], 'Everything paid, and no more, was refunded.');
        self::assertSame(15610, (int) $this->db->fetchOne("SELECT COALESCE(SUM((snapshot->>'total_minor')::bigint),0) FROM commerce_documents WHERE order_id=:o AND kind='credit_note'", ['o' => $orderId]), 'The credit notes add up to what was paid.');
    }

    public function testAUseIsHeldWhenPlacedAndRedeemedOnceWhenPaid(): void
    {
        $buyer = $this->buyer();
        $this->ready($buyer, 'dummy', 'demo_failure');
        $code = $this->code('USE');
        $id = PromotionFixture::create($this->db, $this->owner, ['code' => $code, 'maximum_total_uses' => 5]);
        $repository = new PromotionRepository($this->db);
        $this->fill($buyer, $this->variantA);
        $this->promotions->apply($buyer, $code);
        self::assertSame(['total' => 0, 'customer' => 0], $repository->usage($id, $buyer->id), 'Typing a code and viewing checkout holds nothing: an abandoned checkout uses nothing.');

        $cart = $this->orders->cart($buyer);
        $orderId = (int) $this->checkout($buyer)->place($buyer, (int) $cart['id'], (string) $cart['quote'], true);
        self::assertSame('awaiting_payment', $this->records->order($orderId)['state'], 'The simulated card was declined.');
        self::assertSame(['total' => 1, 'customer' => 1], $repository->usage($id, $buyer->id), 'The unpaid order holds a use against the limits');
        self::assertSame(['redeemed' => 0, 'pending' => 1, 'discount_minor' => 0], $repository->usageSummary($id), 'but a failed payment redeems nothing.');
        self::assertSame([], $this->events('promo_code_redeemed', $buyer->id));

        $key = Uuid::v4();
        $this->checkout($buyer)->retry($buyer, $orderId, $key, 'dummy', 'demo_success');
        $payment = (string) $this->db->fetchOne("SELECT id FROM commerce_payments WHERE order_id=:o AND state='paid'", ['o' => $orderId]);
        $this->payments->confirm('dummy', new PaymentResult($payment, 'paid', Money::strictMinorUnits(11110, 'ZAR'), (string) $this->records->payment($payment)['provider_reference']));
        $this->checkout($buyer)->retry($buyer, $orderId, $key, 'dummy', 'demo_success');
        self::assertSame('fulfilled', $this->records->order($orderId)['state']);
        self::assertSame(['redeemed' => 1, 'pending' => 0, 'discount_minor' => 1235], $repository->usageSummary($id), 'Paid: one redemption, however often the payment is confirmed.');
        $redeemed = $this->events('promo_code_redeemed', $buyer->id);
        self::assertCount(1, $redeemed);
        self::assertSame([$orderId, $id, 1235, 12345], [(int) $redeemed[0]['order_id'], $redeemed[0]['metadata']['promotion_id'], $redeemed[0]['metadata']['discount_minor'], $redeemed[0]['metadata']['eligible_minor']]);
        self::assertSame([11110, 1235], [$this->events('course_purchased', $buyer->id)[0]['metadata']['amount_minor'], $this->events('course_purchased', $buyer->id)[0]['metadata']['discount_minor']], 'The purchase records what was paid.');
        try {
            $this->db->executeStatement('UPDATE promotion_redemptions SET discount_minor=1 WHERE order_id=:o', ['o' => $orderId]);
            self::fail('A redemption is history.');
        } catch (DbalException $immutable) {
            self::assertStringContainsString('Commerce history is immutable', $immutable->getMessage());
        }
    }

    public function testAHundredPercentPromotionCompletesTheOrderWithoutAPayment(): void
    {
        $buyer = $this->buyer();
        $this->ready($buyer, 'eft');
        $this->fill($buyer, $this->variantA, $this->variantB);
        $code = $this->code('FREE');
        $id = PromotionFixture::create($this->db, $this->owner, ['code' => $code, 'discount_value' => 10000]);
        $this->promotions->apply($buyer, $code);
        self::assertSame(0, $this->summary($buyer)['total_minor']);
        $cart = $this->orders->cart($buyer);
        $orderId = (int) $this->checkout($buyer)->place($buyer, (int) $cart['id'], (string) $cart['quote'], true);
        $order = $this->records->order($orderId);
        self::assertSame(['fulfilled', 0, 17345], [$order['state'], (int) $order['total_minor'], (int) $order['discount_minor']]);
        self::assertSame([], $this->records->payments($orderId), 'Nothing was charged.');
        self::assertSame(2, (int) $this->db->fetchOne("SELECT COUNT(*) FROM commerce_entitlements e JOIN commerce_order_items i ON i.id=e.order_item_id WHERE i.order_id=:o", ['o' => $orderId]), 'Both courses were granted.');
        self::assertSame(1, (new PromotionRepository($this->db))->usageSummary($id)['redeemed']);
        self::assertSame(['invoice'], array_column($this->records->documents($orderId), 'kind'), 'An invoice for nothing; no receipt, because nothing was received.');
        self::assertStringContainsString(htmlspecialchars($this->rand(0), ENT_QUOTES), $this->documentsHtml($orderId)['invoice']);
        try {
            $this->refunds()->approve($this->admin(), $orderId, (int) $this->records->items($orderId)[0]['id'], Uuid::v4(), 'voluntary', 'Customer request', 1, 1);
            self::fail('A line nothing was paid for cannot be refunded.');
        } catch (\RuntimeException $refused) {
            self::assertSame('The refund exceeds this item’s unrefunded paid amount.', $refused->getMessage());
        }
    }

    /**
     * Two purchasers, each with the promotion applied, place orders for its single remaining use at
     * the same time. The second placement waits on the promotion's row lock until the first has
     * finished; it cannot read the usage until then. Here the first stays open, so the second times
     * out without placing anything; once the first is rolled back, the use is free and the second
     * succeeds. (After a commit, the second's usage read sees the first order and is refused: the
     * limit test above shows that count.) The fixtures must be committed to be seen by a second
     * connection, so they are removed afterwards; no order is ever committed.
     */
    public function testTwoPurchasersCannotBothTakeTheFinalUse(): void
    {
        $other = CliBootstrap::boot()['container'];
        $second = $other->get(Database::class);
        self::assertNotSame($this->db, $second);
        $committed = new DevelopmentFixture($second);
        $owner = $committed->createUser('Concurrency owner');
        $company = $committed->createCompany($owner, 'Concurrency provider ' . $committed->suffix(), $committed->suffix() . '.concurrency.example.test');
        $course = $committed->createCourse($owner, $company, 'promo-concurrency-' . $committed->suffix(), 'Concurrency course', 'published');
        $variant = $committed->createPriceVariant($course, $owner, 86400, 12345, true);
        $code = 'LAST' . strtoupper($committed->suffix());
        $promotion = PromotionFixture::create($second, $owner, ['code' => $code, 'maximum_total_uses' => 1]);
        $firstBuyer = $this->actor($committed->createUser('First purchaser'));
        $secondBuyer = $this->actor($committed->createUser('Second purchaser'));
        [, $ordersB, , , $promotionsB] = $this->services($second);
        try {
            foreach ([$firstBuyer, $secondBuyer] as $buyer) {
                $ordersB->changeCart($buyer, $variant);
                $promotionsB->apply($buyer, $code);
            }
            $firstCart = $this->orders->cart($firstBuyer);
            $this->orders->place($firstBuyer, (int) $firstCart['id'], (string) $firstCart['quote'], BillingFixture::person(), true);
            $secondCart = $ordersB->cart($secondBuyer);
            self::assertSame(1235, $secondCart['promotion']['discount']->discountMinor, 'The second purchaser was still shown the discount: the first order is not committed.');

            $second->executeStatement("SET lock_timeout = '1500ms'");
            $second->beginTransaction();
            try {
                $ordersB->place($secondBuyer, (int) $secondCart['id'], (string) $secondCart['quote'], BillingFixture::person(), true);
                self::fail('The second placement must wait for the first to finish with the final use.');
            } catch (DbalException $waiting) {
                self::assertStringContainsString('lock timeout', $waiting->getMessage());
            } finally {
                $second->rollBack();
            }

            $this->db->rollBack();
            $this->db->beginTransaction();
            $second->beginTransaction();
            try {
                $placed = $ordersB->place($secondBuyer, (int) $secondCart['id'], (string) $secondCart['quote'], BillingFixture::person(), true);
                self::assertSame($promotion, (int) $second->fetchOne('SELECT promotion_id FROM commerce_orders WHERE id=:id', ['id' => $placed]), 'With the first order gone, the second takes the use.');
            } finally {
                $second->rollBack();
            }
        } finally {
            $second->executeStatement('RESET lock_timeout');
            $users = [$firstBuyer->id, $secondBuyer->id, $owner];
            $second->executeStatement('DELETE FROM analytics_events WHERE user_id = ANY(CAST(:ids AS BIGINT[]))', ['ids' => '{' . implode(',', $users) . '}']);
            $second->executeStatement('DELETE FROM commerce_carts WHERE user_id = ANY(CAST(:ids AS BIGINT[]))', ['ids' => '{' . implode(',', $users) . '}']);
            $second->executeStatement('DELETE FROM promotions WHERE id=:id', ['id' => $promotion]);
            $second->executeStatement('DELETE FROM course_price_variants WHERE course_id=:id', ['id' => $course]);
            $committed->rememberUser($firstBuyer->id);
            $committed->rememberUser($secondBuyer->id);
            $committed->cleanup();
        }
        self::assertFalse((bool) $this->db->fetchOne('SELECT 1 FROM promotions WHERE id=:id', ['id' => $promotion]), 'The committed fixtures were removed.');
    }

    /** @return array{0:CommerceRepository,1:OrderService,2:FulfilmentService,3:PaymentService,4:PromotionService} */
    private function services(Database $db): array
    {
        $container = IntegrationContainer::get();
        $records = new CommerceRepository($db);
        $tx = new TransactionManager($db);
        $transitions = $container->get(TransitionService::class);
        $promotions = PromotionFixture::service($db, $this->clock);
        // Documents are issued through this connection's repository, inside its transaction.
        $documents = new FinancialDocuments($records, $container->get(FinancialDocumentDataBuilder::class), $container->get(DocumentTemplateRenderer::class), $container->get(DocumentPdfRenderer::class));
        $orders = new OrderService($records, $tx, new CommercePolicy(dirname(__DIR__, 2)), $transitions, $this->clock, $promotions, $documents);
        $access = new AccessService($records, $tx, $transitions, $this->clock);
        $companyCredits = new CompanyCreditFulfilment($records, $container->get(AdministrationRepository::class), $container->get(CourseRepository::class), $this->clock);
        $fulfilment = new FulfilmentService($records, $transitions, $access, $tx, $orders, $this->clock, $companyCredits, new AnalyticsEventRecorder(new AnalyticsEventRepository($db), $this->clock), $promotions);
        $payments = new PaymentService($records, $tx, $orders, new OmnipayPaymentGatewayAdapter('test', $this->clock), $fulfilment, $transitions, $this->clock, $documents);
        return [$records, $orders, $fulfilment, $payments, $promotions];
    }

    /** @return array{0:int,1:int} course and price variant */
    private function paidCourse(string $title, int $price): array
    {
        $course = $this->fixture->createCourse($this->owner, $this->provider, 'promo-' . $this->fixture->suffix(), $title, 'published');
        return [$course, $this->fixture->createPriceVariant($course, $this->owner, 86400, $price, true)];
    }

    private function buyer(): CurrentUser
    {
        return $this->actor($this->fixture->createUser('Promotion buyer', 'promo-buyer-' . bin2hex(random_bytes(5)) . '@example.test'));
    }

    private function actor(int $id): CurrentUser
    {
        return new CurrentUser($id, Uuid::v4(), 'buyer-' . $id . '@example.invalid', 'Buyer ' . $id, ['STUDENT'], self::PERMISSIONS, Uuid::v4());
    }

    private function admin(): CurrentUser
    {
        $id = $this->fixture->createUser('Promotion administrator', 'promo-admin-' . bin2hex(random_bytes(5)) . '@example.test');
        return new CurrentUser($id, Uuid::v4(), 'admin-' . $id . '@example.invalid', 'Administrator', ['ADMIN'], (new \CattoLearning\Auth\PermissionCatalog())->keys(), Uuid::v4());
    }

    private function fill(CurrentUser $buyer, int ...$variants): void
    {
        foreach ($variants as $variant) $this->orders->changeCart($buyer, $variant);
    }

    /** Details confirmed and a payment method chosen, as the first checkout steps leave them. */
    private function ready(CurrentUser $buyer, string $method = 'eft', string $token = 'demo_success'): void
    {
        $this->billing->saveOwn($buyer, BillingFixture::personFields('Promotion Buyer'));
        $_SESSION['checkout'][$buyer->id] = ['profile_confirmed' => true, 'payment_method' => $method, 'method_token' => $method === 'dummy' ? $token : '', 'invoice_email' => false, 'payment_key' => Uuid::v4()];
    }

    private function placeDirect(CurrentUser $buyer): int
    {
        $cart = $this->orders->cart($buyer);
        return $this->orders->place($buyer, (int) $cart['id'], (string) $cart['quote'], BillingFixture::person(), true);
    }

    /** @return array<string,mixed> */
    private function summary(CurrentUser $buyer): array
    {
        $auth = clone IntegrationContainer::get()->get(AuthService::class);
        (new \ReflectionProperty(AuthService::class, 'currentUser'))->setValue($auth, $buyer);
        return (new CartService($auth, $this->orders, $this->records))->summary();
    }

    private function checkout(CurrentUser $buyer): CheckoutService
    {
        $auth = clone IntegrationContainer::get()->get(AuthService::class);
        (new \ReflectionProperty(AuthService::class, 'currentUser'))->setValue($auth, $buyer);
        return new CheckoutService($auth, $this->orders, $this->records, $this->payments, $this->fulfilment, new TransactionManager($this->db),
            new AnalyticsEventRecorder(new AnalyticsEventRepository($this->db), $this->clock), $this->billing);
    }

    private function refunds(): RefundAdministrationService
    {
        return new RefundAdministrationService($this->records, new TransactionManager($this->db), IntegrationContainer::get()->get(TransitionService::class), $this->clock,
            new AnalyticsEventRecorder(new AnalyticsEventRepository($this->db), $this->clock),new \CattoLearning\Commerce\Application\AccessService($this->records,new TransactionManager($this->db),IntegrationContainer::get()->get(TransitionService::class),$this->clock),IntegrationContainer::get()->get(FinancialDocuments::class));
    }

    private function administration(): PromotionAdministrationService
    {
        return new PromotionAdministrationService(new PromotionRepository($this->db), new TransactionManager($this->db), IntegrationContainer::get()->get(AuditRepository::class), $this->clock);
    }

    /** @return array<string,string> rendered HTML of each of the order's documents, by kind */
    private function documentsHtml(int $orderId): array
    {
        $html = [];
        foreach ($this->records->documents($orderId) as $document) $html[(string) $document['kind']] = IntegrationContainer::get()->get(FinancialDocuments::class)->render($document)->html;
        return $html;
    }

    private function cartPromotion(CurrentUser $buyer): ?int
    {
        $id = $this->db->fetchOne("SELECT promotion_id FROM commerce_carts WHERE user_id=:u AND state='open'", ['u' => $buyer->id]);
        return $id === null || $id === false ? null : (int) $id;
    }

    private function orderCount(CurrentUser $buyer): int
    {
        return (int) $this->db->fetchOne('SELECT COUNT(*) FROM commerce_orders WHERE purchaser_user_id=:u', ['u' => $buyer->id]);
    }

    private function code(string $prefix): string
    {
        return $prefix . strtoupper(bin2hex(random_bytes(4)));
    }

    private function rand(int $minor): string
    {
        return Money::strictMinorUnits($minor, 'ZAR')->format();
    }

    private function expectRejected(string $message, callable $attempt, string $why = ''): void
    {
        try {
            $attempt();
            self::fail('Expected the promo code to be rejected: ' . $message);
        } catch (PromotionRejected $rejected) {
            self::assertSame($message, $rejected->getMessage(), $why);
        }
    }

    /** @return list<array<string,mixed>> */
    private function events(string $type, int $userId): array
    {
        return array_map(static function (array $row): array {
            $row['metadata'] = json_decode((string) $row['metadata'], true, 512, JSON_THROW_ON_ERROR);
            return $row;
        }, $this->db->fetchAllAssociative('SELECT * FROM analytics_events WHERE event_type=:t AND user_id=:u ORDER BY id', ['t' => $type, 'u' => $userId]));
    }

    /**
     * @param array<string,mixed> $values
     * @return array<string,mixed>
     */
    private static function sorted(array $values): array
    {
        ksort($values);
        return $values;
    }
}
