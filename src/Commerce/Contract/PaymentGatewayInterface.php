<?php

declare(strict_types=1);
namespace CattoLearning\Commerce\Contract;
use CattoLearning\Commerce\Domain\PaymentRequest;
use CattoLearning\Commerce\Domain\PaymentResult;

/** Catto-owned gateway boundary; implementations never expose vendor objects. */
interface PaymentGatewayInterface
{
    public function id(): string;
    /** @return list<string> */
    public function capabilities(): array;
    public function purchase(PaymentRequest $request): PaymentResult;
}
