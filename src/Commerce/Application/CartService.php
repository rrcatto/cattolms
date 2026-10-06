<?php

declare(strict_types=1);
namespace CattoLearning\Commerce\Application;

use CattoLearning\Auth\AuthService;
use CattoLearning\Commerce\Domain\OrderTotals;
use CattoLearning\Support\AccessPeriod;
use CattoLearning\Commerce\Infrastructure\CommerceRepository;
use CattoLearning\Support\Money;
use RuntimeException;

/** Keeps anonymous selections in the browser session and merges them into the signed-in cart. */
final class CartService
{
    public function __construct(private readonly AuthService $auth, private readonly OrderService $orders, private readonly CommerceRepository $records) {}

    /** @return array<string,mixed> */
    public function summary(): array
    {
        $actor = $this->auth->currentUser();
        $guest = array_map('intval', (array) ($_SESSION['guest_cart'] ?? []));
        $guestBundles = array_map('intval', (array) ($_SESSION['guest_bundles'] ?? []));
        if ($actor !== null && $actor->hasPermission('COMMERCE.CART.VIEW')) {
            foreach ($guest as $id) {
                try { $this->orders->changeCart($actor, $id); }
                catch (RuntimeException $e) { $_SESSION['flash'][] = ['type'=>'warning', 'message'=>$e->getMessage()]; }
            }
            foreach ($guestBundles as $id) {
                try { $this->explainRemoved($this->orders->changeBundle($actor, $id)); }
                catch (RuntimeException $e) { $_SESSION['flash'][] = ['type'=>'warning', 'message'=>$e->getMessage()]; }
            }
            unset($_SESSION['guest_cart'], $_SESSION['guest_bundles']);
            $cart = $this->orders->cart($actor);
        } else {
            $items = [];
            foreach ($guest as $id) {
                try { $items[] = $this->records->offer($id); }
                catch (RuntimeException) { /* A withdrawn offer can no longer be purchased. */ }
            }
            $bundles = [];
            foreach ($guestBundles as $id) {
                try { $bundle = $this->records->bundleOffer($id); }
                catch (RuntimeException) { continue; /* A deleted bundle can no longer be purchased. */ }
                $bundle['courses'] = $this->records->bundleCourses((int) $bundle['bundle_id']);
                $bundles[] = $bundle;
            }
            $cart = ['id'=>0, 'quote'=>'', 'items'=>$items, 'bundles'=>$bundles, 'promotion'=>['code'=>null, 'discount'=>null, 'rejection'=>null]];
        }
        $total = Money::strictMinorUnits(0, 'ZAR');
        foreach ($cart['items'] as &$item) {
            $money = Money::strictMinorUnits((int) $item['price_minor_units'], (string) $item['currency_code']);
            $item['price_label'] = $money->format();
            $item['days'] = (int) $item['access_period_seconds'] / 86400;
            $total = $total->plus($money);
        }
        unset($item);
        // A bundle line shows what it includes, which of its courses the learner already has (the
        // price stays the bundle's price), and any course two bundle lines both include.
        $seen = [];
        $cart['overlaps'] = [];
        foreach ($cart['bundles'] as &$bundle) {
            $money = Money::strictMinorUnits((int) $bundle['price_minor_units'], (string) $bundle['currency_code']);
            $bundle['price_label'] = $money->format();
            $bundle['access_label'] = AccessPeriod::label((int) $bundle['access_period_seconds']);
            $bundle['course_count'] = count($bundle['courses']);
            $bundle['course_titles'] = array_map(static fn(array $c): string => (string) $c['title'], $bundle['courses']);
            $bundle['owned_count'] = 0;
            foreach ($bundle['courses'] as $course) {
                if ($actor !== null && $this->records->hasOpenEnrolment($actor->id, (int) $course['course_id'])) $bundle['owned_count']++;
                $courseId = (int) $course['course_id'];
                if (isset($seen[$courseId])) $cart['overlaps'][] = $course['title'] . ' is included in both ' . $seen[$courseId] . ' and ' . $bundle['title'] . '. Each bundle is a purchase of its own, so you would pay for both.';
                $seen[$courseId] ??= (string) $bundle['title'];
            }
            $total = $total->plus($money);
        }
        unset($bundle);
        // The promotion was calculated by the server when the cart was priced; nothing here trusts a
        // figure from the browser. A code that no longer applies is shown with its reason and no discount.
        $promotion = $cart['promotion'];
        $discount = $promotion['discount'];
        $discountMinor = $discount->discountMinor ?? 0;
        $cart['count'] = count($cart['items']) + count($cart['bundles']);
        $cart['subtotal_minor'] = $total->minorUnits;
        $cart['discount_minor'] = $discountMinor;
        $cart['total_minor'] = $total->minorUnits - $discountMinor;
        $cart['total_label'] = Money::strictMinorUnits($cart['total_minor'], $total->currency)->format();
        $cart['totals'] = OrderTotals::rows($total->minorUnits, $discountMinor, $cart['total_minor'], $total->currency, $promotion['code'], $discount?->promotion->discountLabel());
        $cart['promo'] = ['code'=>$promotion['code'], 'applied'=>$discount !== null, 'rejection'=>$promotion['rejection'],
            'label'=>$discount?->promotion->discountLabel(), 'discount_label'=>$discount === null ? null : OrderTotals::negative($discountMinor, $total->currency)];
        // Templates receive plain values only; the calculation object stays with the services.
        unset($cart['promotion']);
        return $cart;
    }

    public function change(int $variantId, bool $remove = false): void
    {
        $actor = $this->auth->currentUser();
        if ($actor !== null) {
            $this->summary();
            $this->orders->changeCart($actor, $variantId, $remove);
            return;
        }
        $ids = array_map('intval', (array) ($_SESSION['guest_cart'] ?? []));
        if ($remove) {
            $_SESSION['guest_cart'] = array_values(array_diff($ids, [$variantId]));
            return;
        }
        $offer = $this->records->offer($variantId);
        $this->orders->validateOffer($offer, 0);
        if (in_array($variantId, $ids, true)) return;
        if (count($ids) >= 50) throw new RuntimeException('Check out before adding more courses.');
        $summary = $this->summary();
        foreach ($summary['items'] as $item) {
            if ((int) $item['course_id'] === (int) $offer['course_id']) throw new RuntimeException('Choose one access period per course.');
        }
        foreach ($summary['bundles'] as $bundle) {
            foreach ($bundle['courses'] as $course) {
                if ((int) $course['course_id'] === (int) $offer['course_id']) throw new RuntimeException((string) $offer['title'] . ' is already included in ' . $bundle['title'] . ' in your cart.');
            }
        }
        $_SESSION['guest_cart'] = [...$ids, $variantId];
    }

    /**
     * Adds or removes a bundle. A course already in the cart that the bundle includes is taken out,
     * with a note saying so, rather than charged twice.
     */
    public function changeBundle(int $offerId, bool $remove = false): void
    {
        $actor = $this->auth->currentUser();
        if ($actor !== null) {
            $this->summary();
            $this->explainRemoved($this->orders->changeBundle($actor, $offerId, $remove));
            return;
        }
        $ids = array_map('intval', (array) ($_SESSION['guest_bundles'] ?? []));
        if ($remove) {
            $_SESSION['guest_bundles'] = array_values(array_diff($ids, [$offerId]));
            return;
        }
        $offer = $this->records->bundleOffer($offerId);
        $courses = $this->records->bundleCourses((int) $offer['bundle_id']);
        $this->orders->validateBundle($offer, $courses, 0);
        if (in_array($offerId, $ids, true)) return;
        if (count($ids) >= 20) throw new RuntimeException('Check out before adding more bundles.');
        $included = array_map(static fn(array $c): int => (int) $c['course_id'], $courses);
        $removed = [];
        $kept = [];
        foreach (array_map('intval', (array) ($_SESSION['guest_cart'] ?? [])) as $variantId) {
            try { $course = $this->records->offer($variantId); }
            catch (RuntimeException) { continue; }
            if (in_array((int) $course['course_id'], $included, true)) $removed[] = (string) $course['title'];
            else $kept[] = $variantId;
        }
        $_SESSION['guest_cart'] = $kept;
        $_SESSION['guest_bundles'] = [...$ids, $offerId];
        $this->explainRemoved($removed);
    }

    /** @param list<string> $titles courses taken out of the cart because a bundle added to it includes them */
    private function explainRemoved(array $titles): void
    {
        foreach ($titles as $title) {
            $_SESSION['flash'][] = ['type'=>'info', 'message'=>$title . ' is included in the bundle you added, so it was taken out of your cart. You will not pay for it twice.'];
        }
    }
}
