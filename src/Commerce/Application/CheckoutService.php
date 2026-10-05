<?php

declare(strict_types=1);
namespace CattoLearning\Commerce\Application;

use CattoLearning\Analytics\{AnalyticsEventRecorder, AnalyticsEventType, AnalyticsSource};
use CattoLearning\Auth\{AuthService, CurrentUser};
use CattoLearning\Commerce\Domain\BillingDetails;
use CattoLearning\Commerce\Infrastructure\CommerceRepository;
use CattoLearning\Infrastructure\Persistence\TransactionManager;
use CattoLearning\Support\Uuid;
use RuntimeException;

/**
 * Coordinates the purchaser's details, payment selection and review; payment is attempted only
 * after order commit. The details step keeps two things apart: personal particulars go to the
 * person's profile, billing details to their reusable billing profile. Placement copies the billing
 * profile as it is at that moment into the order's immutable snapshot.
 */
final class CheckoutService
{
    /** Personal particulars the details step collects, with their maximum lengths. */
    private const PERSONAL = ['first_name'=>120,'last_name'=>120,'mobile_number'=>60];

    public function __construct(private readonly AuthService $auth,
        private readonly OrderService $orders, private readonly CommerceRepository $records,
        private readonly PaymentService $payments, private readonly FulfilmentService $fulfilment,
        private readonly TransactionManager $transactions, private readonly AnalyticsEventRecorder $analytics,
        private readonly BillingProfileService $billing) {}

    /**
     * A signed-in purchaser opened checkout with something in the cart. Recorded once per cart,
     * however often they move between the checkout steps; viewing a course or the cart is not a
     * checkout. Course ids and totals only.
     *
     * @param array<string,mixed> $cart the cart summary checkout shows
     */
    public function begin(CurrentUser $actor, array $cart): void
    {
        if ((int) ($cart['id'] ?? 0) < 1 || ($cart['items'] ?? []) === []) return;
        $courses = []; $total = 0; $currencies = [];
        foreach ((array) $cart['items'] as $item) {
            if ((int) ($item['course_id'] ?? 0) > 0) $courses[] = (int) $item['course_id'];
            $total += (int) ($item['price_minor_units'] ?? 0);
            $currencies[(string) ($item['currency_code'] ?? '')] = true;
        }
        $this->analytics->recordSafely(AnalyticsEventType::CheckoutStarted, AnalyticsSource::Checkout, ['user_id' => $actor->id],
            ['cart_id' => (int) $cart['id'], 'line_count' => count((array) $cart['items']), 'course_ids' => array_slice(array_values(array_unique($courses)), 0, 50), 'total_minor' => $total, 'currency' => count($currencies) === 1 ? (string) array_key_first($currencies) : null, 'purchaser' => 'individual'],
            'checkout_started:cart:' . (int) $cart['id']);
    }

    /** @return array<string,mixed> */
    public function state(CurrentUser $actor): array
    {
        return (array) ($_SESSION['checkout'][$actor->id] ?? []);
    }

    /**
     * The details step: personal particulars and billing details, both validated before either is
     * saved, then saved together.
     *
     * @param array<string,mixed> $fields
     */
    public function saveProfile(CurrentUser $actor, array $fields): void
    {
        $personal = [];
        foreach (self::PERSONAL as $field=>$limit) {
            $personal[$field] = trim((string) ($fields[$field] ?? ''));
            if ($personal[$field] === '' || mb_strlen($personal[$field]) > $limit) throw new RuntimeException('Complete your names and cell number.');
        }
        $personal['identification_number'] = (string) ($fields['identification_number'] ?? '');
        if (preg_match('/^[0-9]{13}$/D', $personal['identification_number']) !== 1) throw new RuntimeException('Enter your 13-digit RSA ID number.');
        BillingDetails::forPerson($fields);
        $this->transactions->run(function () use ($actor, $personal, $fields): void {
            $this->auth->updateProfile($actor->id, $personal);
            $this->billing->saveOwn($actor, $fields);
        });
        $_SESSION['checkout'][$actor->id] = ['profile_confirmed'=>true];
    }

    public function selectMethod(CurrentUser $actor, string $method, string $token, bool $emailInvoice): void
    {
        if (!($this->state($actor)['profile_confirmed'] ?? false)) throw new RuntimeException('Complete your details first.');
        self::validateMethod($method, $token);
        $_SESSION['checkout'][$actor->id]['payment_method'] = $method;
        $_SESSION['checkout'][$actor->id]['method_token'] = $token;
        $_SESSION['checkout'][$actor->id]['invoice_email'] = $emailInvoice;
        $_SESSION['checkout'][$actor->id]['payment_key'] = Uuid::v4();
    }

    public static function validateMethod(string $method, string $token): void
    {
        if (!in_array($method, ['dummy','eft'], true)) throw new RuntimeException('Choose a payment method.');
        if ($method === 'dummy' && !in_array($token, ['demo_success','demo_failure'], true)) throw new RuntimeException('Choose a simulated card outcome.');
    }

    /** A replay of the review submission returns its original order instead of charging again. */
    public function place(CurrentUser $actor, int $cartId, string $quote, bool $accepted): ?int
    {
        $state = $this->state($actor);
        if (isset($state['completed_cart']) && (int) $state['completed_cart'] === $cartId) return isset($state['order_id']) ? (int) $state['order_id'] : null;
        if (!($state['profile_confirmed'] ?? false) || !isset($state['payment_method'])) throw new RuntimeException('Complete your details and choose a payment method first.');
        if (!$accepted) throw new RuntimeException('Accept the purchase terms before placing your order.');
        $billing = $this->billing->forUser($actor->id) ?? throw new RuntimeException('Complete your billing details first.');
        $id = $this->transactions->run(function () use ($actor,$cartId,$quote,$billing,$state): ?int {
            $this->records->lockPurchaser($actor->id);
            $cart = $this->orders->cart($actor);
            if ((int) $cart['id'] !== $cartId || !hash_equals((string) $cart['quote'], $quote)) throw new RuntimeException('Your cart or prices changed. Review your order again.');
            if ($cart['items'] === []) throw new RuntimeException('Your cart is empty.');
            foreach ($cart['items'] as $item) {
                if ((int) $item['price_minor_units'] !== 0) continue;
                $this->fulfilment->acceptFree($actor, (int) $item['id']);
                $this->orders->changeCart($actor, (int) $item['id'], true);
            }
            $cart = $this->orders->cart($actor);
            if ($cart['items'] === []) return null;
            return $this->orders->place($actor, $cartId, (string) $cart['quote'], $billing, true, $state);
        });
        $_SESSION['checkout'][$actor->id]['completed_cart'] = $cartId;
        $_SESSION['checkout'][$actor->id]['order_id'] = $id;
        if ($id !== null && $state['payment_method'] === 'dummy') $this->payments->purchase($actor, $id, (string) $state['payment_key'], (string) $state['method_token']);
        return $id;
    }

    public function retry(CurrentUser $actor, int $orderId, string $key, string $method, string $token): void
    {
        self::validateMethod($method, $token);
        $this->orders->view($actor, $orderId);
        if ($this->records->paymentForRequest($orderId, $key) !== null) return;
        $this->orders->cancelDue($orderId);
        $this->transactions->run(function () use ($actor,$orderId,$method): void {
            $this->records->lockPurchaser($actor->id);
            $order = $this->records->order($orderId, true);
            if ($order['state'] !== 'awaiting_payment') throw new RuntimeException('This order is no longer awaiting payment.');
            if ($order['request_id'] !== null && $method !== 'dummy') throw new RuntimeException('A request purchase needs immediate confirmed payment to enrol the learner. Use the simulated card payment.');
            foreach ($this->records->payments($orderId) as $attempt) {
                if (in_array($attempt['state'], ['created','pending','paid'], true)) throw new RuntimeException('A payment is already being processed.');
            }
            $this->records->selectPaymentMethod($orderId, $method);
            $this->records->audit($orderId, $actor->id, 'payment.method_selected', ['method'=>$method], gmdate(DATE_ATOM));
        });
        if ($method === 'dummy') $this->payments->purchase($actor, $orderId, $key, $token);
    }
}
