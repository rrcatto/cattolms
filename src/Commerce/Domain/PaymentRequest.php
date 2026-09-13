<?php

declare(strict_types=1);
namespace CattoLearning\Commerce\Domain;
use CattoLearning\Support\Money;

/** Provider-neutral purchase instruction; method tokens never enter financial history. */
final readonly class PaymentRequest
{
    public function __construct(public string $transactionId, public Money $amount, public string $methodToken) {}
}
