<?php

declare(strict_types=1);
namespace CattoLearning\Tests\Unit;

use CattoLearning\Commerce\Domain\PaymentRequest;
use CattoLearning\Commerce\Infrastructure\Payment\OmnipayPaymentGatewayAdapter;
use CattoLearning\Support\Money;
use CattoLearning\Support\Uuid;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/** Tests actual Dummy requests, not a production bypass or live payment provider. */
#[Group('commerce')]
final class CommercePaymentContractTest extends TestCase
{
    public function testActualDummySuccessAndFailurePreserveMerchantIdentity(): void
    {
        $gateway=new OmnipayPaymentGatewayAdapter('test',new MockClock('2026-09-12T12:00:00+02:00'));
        foreach(['demo_success'=>'paid','demo_failure'=>'failed'] as $token=>$state) {
            $id=Uuid::v4(); $money=Money::strictMinorUnits(12345,'ZAR');
            $result=$gateway->purchase(new PaymentRequest($id,$money,$token));
            self::assertSame($state,$result->state);
            self::assertSame($id,$result->transactionId);
            self::assertNotEmpty($result->providerReference);
            self::assertSame(12345,$result->amount->minorUnits);
        }
        self::assertSame(['purchase'],$gateway->capabilities());
    }
    public function testDummyIsRejectedInProduction(): void
    {
        $this->expectException(\RuntimeException::class);
        (new OmnipayPaymentGatewayAdapter('production',new MockClock()))->purchase(new PaymentRequest(Uuid::v4(),Money::strictMinorUnits(100,'ZAR'),'demo_success'));
    }
    public function testPaymentAmountSerializationNeverLosesAnIntegerCent(): void
    {
        self::assertSame('92233720368547758.07',Money::strictMinorUnits(PHP_INT_MAX,'ZAR')->toMajorUnits());
    }
    public function testMixedCurrenciesAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Money::strictMinorUnits(100,'ZAR')->plus(Money::strictMinorUnits(100,'GBP'));
    }
    public function testOverflowIsRejected(): void
    {
        $this->expectException(\OverflowException::class);
        Money::strictMinorUnits(PHP_INT_MAX,'ZAR')->plus(Money::strictMinorUnits(1,'ZAR'));
    }
    public function testStrictMoneyRejectsNegativeAmountsInsteadOfTurningThemIntoFreeCourses(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Money::strictMinorUnits(-1,'ZAR');
    }
}
