<?php

declare(strict_types=1);
namespace CattoLearning\Commerce\Domain;
use CattoLearning\Support\Money;
use InvalidArgumentException;

/** Normalized gateway evidence, distinct from browser redirect parameters. */
final readonly class PaymentResult
{
    public function __construct(
        public string $transactionId,
        public string $state,
        public Money $amount,
        public ?string $providerReference = null,
        public ?string $eventId = null,
        public string $message = ''
    ) {
        if (!in_array($state, ['paid','failed','pending'], true)) {
            throw new InvalidArgumentException('Unknown payment result.');
        }
    }
}
