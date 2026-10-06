<?php

declare(strict_types=1);

namespace CattoLearning\Commerce\Domain;

use RuntimeException;

/**
 * A promo code that cannot be applied to this cart now. The message is for the purchaser: it says
 * why in plain words and never names an internal id or rule.
 */
final class PromotionRejected extends RuntimeException
{
    public function __construct(string $message, public readonly ?string $promoCode = null)
    {
        parent::__construct($message);
    }
}
