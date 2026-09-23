<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Integration\Payment;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionChargeAttemptInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionCycleInterface;
use JpmMartin\SyliusSubscriptionPlugin\Order\RenewalOrderPlacerInterface;
use JpmMartin\SyliusSubscriptionPlugin\Payment\ChargeOutcome;
use JpmMartin\SyliusSubscriptionPlugin\Payment\RenewalChargerInterface;
use Sylius\Behat\Context\Setup\PaymentContext;
use Sylius\Behat\Service\SharedStorageInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\OrderPaymentStates;
use Sylius\Component\Payment\Model\PaymentMethodInterface as BasePaymentMethodInterface;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Integration\Lifecycle\LifecycleTestCase;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Payment\ScriptedGateway;

/**
 * The default charging service, through Sylius's payment requests and the test store's scripted card
 * gateway, on the payment of a real renewal order of a monthly batch of Coffee and Tea.
 */
final class RenewalChargingTest extends LifecycleTestCase
{
    private SubscriptionCycleInterface $cycle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->itIsNow('2027-01-01 09:00');
        $this->pay($this->placedBatchOrder());
        $this->cycle = $this->storedCycles($this->subscriptionsByPlan()['COFFEE_MONTHLY+TEA_MONTHLY'])[1];
        $this->itIsNow('2027-02-01 09:00');
    }

    public function testAnApprovedChargeCompletesThePaymentAndPaysTheOrder(): void
    {
        $order = $this->renewalOrder();

        $outcome = $this->charger()->charge($this->paymentOf($order));
        $this->entityManager()->flush();

        self::assertTrue($outcome->isApproved());
        self::assertSame(['capture'], $this->scriptedGateway()->requests());
        self::assertSame(PaymentInterface::STATE_COMPLETED, $order->getLastPayment()?->getState());
        self::assertSame(OrderPaymentStates::STATE_PAID, $order->getPaymentState());
    }

    public function testADeclinedChargeCarriesTheIssuersReasonAndTheGatewaysCodeWhichTheAttemptKeeps(): void
    {
        $this->scriptedGateway()->willAnswer(ScriptedGateway::DECLINE, 'Insufficient funds.', 'insufficient_funds');
        $order = $this->renewalOrder();
        $payment = $this->paymentOf($order);

        $outcome = $this->charger()->charge($payment);
        $this->entityManager()->flush();

        self::assertTrue($outcome->isFailure());
        self::assertSame(SubscriptionChargeAttemptInterface::OUTCOME_DECLINED, $outcome->outcome);
        self::assertSame('Insufficient funds.', $outcome->reason);
        self::assertSame('insufficient_funds', $outcome->code);
        self::assertSame(PaymentInterface::STATE_FAILED, $payment->getState());
        self::assertSame(OrderPaymentStates::STATE_AWAITING_PAYMENT, $order->getPaymentState());
    }

    public function testADeclineWithoutACodeCarriesNone(): void
    {
        $this->scriptedGateway()->willAnswer(ScriptedGateway::DECLINE, 'Insufficient funds.');

        $outcome = $this->charger()->charge($this->paymentOf($this->renewalOrder()));

        self::assertNull($outcome->code);
    }

    public function testTheAttemptOfADeclinedRenewalKeepsTheReasonAndTheCode(): void
    {
        $this->scriptedGateway()->willAnswer(ScriptedGateway::DECLINE, 'Insufficient funds.', 'insufficient_funds');

        $this->runTheCycleCommand();

        $attempt = $this->storedCycles($this->subscriptionsByPlan()['COFFEE_MONTHLY+TEA_MONTHLY'])[1]->getAttempts()->first();
        self::assertInstanceOf(SubscriptionChargeAttemptInterface::class, $attempt);
        self::assertSame(SubscriptionChargeAttemptInterface::OUTCOME_DECLINED, $attempt->getOutcome());
        self::assertSame('Insufficient funds.', $attempt->getReason());
        self::assertSame('insufficient_funds', $attempt->getCode());
    }

    public function testAChargeTheGatewayNeverAnsweredIsUnknownAndItsStatusIsAskedWithoutChargingAgain(): void
    {
        $this->scriptedGateway()->willAnswer(ScriptedGateway::NO_ANSWER);
        $order = $this->renewalOrder();
        $payment = $this->paymentOf($order);

        $outcome = $this->charger()->charge($payment);

        self::assertTrue($outcome->isUnknown());
        self::assertSame(PaymentInterface::STATE_NEW, $payment->getState());

        $status = $this->charger()->status($payment);
        $this->entityManager()->flush();

        self::assertTrue($status->isApproved());
        self::assertSame(['capture', 'status'], $this->scriptedGateway()->requests());
        self::assertSame(OrderPaymentStates::STATE_PAID, $order->getPaymentState());
    }

    public function testOnlyTheConfiguredMethodsAreSuitableAndAnyOtherIsNotAttempted(): void
    {
        /** @var PaymentContext $payments */
        $payments = self::getContainer()->get('sylius.behat.context.setup.payment');
        $payments->storeAllowsPaying('Offline');
        /** @var SharedStorageInterface $sharedStorage */
        $sharedStorage = self::getContainer()->get('sylius.behat.shared_storage');
        /** @var PaymentMethodInterface $offline */
        $offline = $sharedStorage->get('payment_method');

        self::assertTrue($this->charger()->supports($this->paymentMethod));
        self::assertFalse($this->charger()->supports($offline));

        $payment = $this->paymentOf($this->renewalOrder());
        $payment->setMethod($offline);
        $outcome = $this->charger()->charge($payment);

        self::assertSame(SubscriptionChargeAttemptInterface::OUTCOME_NOT_ATTEMPTED, $outcome->outcome);
        self::assertSame('The "Offline" payment method is not configured to charge renewals.', $outcome->reason);
        self::assertSame([], $this->scriptedGateway()->requests());
    }

    public function testAStoresOwnChargingServiceChargesTheCyclesInstead(): void
    {
        $storeCharger = new class() implements RenewalChargerInterface {
            public function supports(BasePaymentMethodInterface $paymentMethod): bool
            {
                return true;
            }

            public function charge(PaymentInterface $payment): ChargeOutcome
            {
                return ChargeOutcome::declined('Declined by the store\'s own service.');
            }

            public function status(PaymentInterface $payment): ChargeOutcome
            {
                return ChargeOutcome::unknown();
            }
        };
        self::getContainer()->set('jpm_martin_sylius_subscription.payment.renewal_charger', $storeCharger);

        $this->runTheCycleCommand();

        self::assertSame([], $this->scriptedGateway()->requests());
        $attempts = $this->storedCycles($this->subscriptionsByPlan()['COFFEE_MONTHLY+TEA_MONTHLY'])[1]->getAttempts();
        self::assertCount(1, $attempts);
        self::assertSame('Declined by the store\'s own service.', $attempts->first() ? $attempts->first()->getReason() : null);
    }

    private function renewalOrder(): OrderInterface
    {
        $placer = self::getContainer()->get(RenewalOrderPlacerInterface::class);
        self::assertInstanceOf(RenewalOrderPlacerInterface::class, $placer);
        $order = $placer->place($this->cycle);
        self::assertNotNull($order);
        self::assertCount(2, $order->getItems());
        $this->entityManager()->flush();

        return $order;
    }

    private function paymentOf(OrderInterface $order): PaymentInterface
    {
        $payment = $order->getLastPayment(PaymentInterface::STATE_NEW);
        self::assertNotNull($payment);

        return $payment;
    }

    private function charger(): RenewalChargerInterface
    {
        $charger = self::getContainer()->get(RenewalChargerInterface::class);
        self::assertInstanceOf(RenewalChargerInterface::class, $charger);

        return $charger;
    }
}
