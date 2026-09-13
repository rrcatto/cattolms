<?php

declare(strict_types=1);
namespace CattoLearning\Commerce\Infrastructure\Payment;

use CattoLearning\Commerce\Contract\PaymentGatewayInterface;
use CattoLearning\Commerce\Domain\PaymentRequest;
use CattoLearning\Commerce\Domain\PaymentResult;
use Omnipay\Omnipay;
use Symfony\Component\Clock\ClockInterface;
use RuntimeException;
use Throwable;

/** Executes the initial Dummy driver through Omnipay; simulation tokens never contain customer cards. */
final class OmnipayPaymentGatewayAdapter implements PaymentGatewayInterface
{
    public function __construct(private readonly string $environment, private readonly ClockInterface $clock) {}
    public function id(): string { return 'dummy'; }
    /** @return list<string> */
    public function capabilities(): array { return ['purchase']; }
    public function purchase(PaymentRequest $request): PaymentResult
    {
        if (!in_array($this->environment, ['development','dev','test'], true)) {
            throw new RuntimeException('The Dummy payment gateway is unavailable outside development and tests.');
        }
        if (!in_array($request->methodToken, ['demo_success','demo_failure'], true)) {
            throw new RuntimeException('Choose a simulated payment outcome.');
        }
        try {
            $gateway = Omnipay::create('Dummy');
            $response = $gateway->purchase([
                'transactionId' => $request->transactionId,
                'amount' => $request->amount->toMajorUnits(),
                'currency' => $request->amount->currency,
                'card' => [
                    'firstName' => 'Development', 'lastName' => 'Simulation',
                    'number' => $request->methodToken === 'demo_success' ? '4929000000006' : '4444333322221111',
                    'expiryMonth' => '12', 'expiryYear' => $this->clock->now()->modify('+5 years')->format('Y'),
                    'cvv' => '123',
                ],
            ])->send();
            return new PaymentResult($request->transactionId, $response->isSuccessful() ? 'paid' : 'failed',
                $request->amount, $response->getTransactionReference(), null,
                $response->isSuccessful() ? 'Payment confirmed.' : 'The simulated payment was declined.');
        } catch (Throwable) {
            // An exception is not proof of failure; an actual provider might have accepted a charge.
            return new PaymentResult($request->transactionId, 'pending', $request->amount, message: 'Payment needs reconciliation.');
        }
    }
}
