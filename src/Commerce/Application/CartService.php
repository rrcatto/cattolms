<?php

declare(strict_types=1);
namespace CattoLearning\Commerce\Application;

use CattoLearning\Auth\AuthService;
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
        if ($actor !== null && $actor->hasPermission('COMMERCE.CART.VIEW')) {
            foreach ($guest as $id) {
                try { $this->orders->changeCart($actor, $id); }
                catch (RuntimeException $e) { $_SESSION['flash'][] = ['type'=>'warning', 'message'=>$e->getMessage()]; }
            }
            unset($_SESSION['guest_cart']);
            $cart = $this->orders->cart($actor);
        } else {
            $items = [];
            foreach ($guest as $id) {
                try { $items[] = $this->records->offer($id); }
                catch (RuntimeException) { /* A withdrawn offer can no longer be purchased. */ }
            }
            $cart = ['id'=>0, 'quote'=>'', 'items'=>$items];
        }
        $total = Money::strictMinorUnits(0, 'ZAR');
        foreach ($cart['items'] as &$item) {
            $money = Money::strictMinorUnits((int) $item['price_minor_units'], (string) $item['currency_code']);
            $item['price_label'] = $money->format();
            $item['days'] = (int) $item['access_period_seconds'] / 86400;
            $total = $total->plus($money);
        }
        unset($item);
        $cart['count'] = count($cart['items']);
        $cart['total_label'] = $total->format();
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
        foreach ($this->summary()['items'] as $item) {
            if ((int) $item['course_id'] === (int) $offer['course_id']) throw new RuntimeException('Choose one access period per course.');
        }
        $_SESSION['guest_cart'] = [...$ids, $variantId];
    }
}
