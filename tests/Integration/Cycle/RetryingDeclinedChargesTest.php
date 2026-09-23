<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Integration\Cycle;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionChargeAttemptInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionCycleInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use JpmMartin\SyliusSubscriptionPlugin\Payment\ChargeOutcome;
use JpmMartin\SyliusSubscriptionPlugin\Payment\RenewalChargerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Core\OrderPaymentStates;
use Sylius\Component\Payment\Model\PaymentMethodInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Integration\Lifecycle\LifecycleTestCase;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Payment\ScriptedGateway;

/**
 * A monthly batch of Coffee and Tea activated on 1 January, whose second cycle is due on 1 February,
 * with the default delays of 1, 3 and 7 days. Coffee is tracked: ten on hand, one sold by the initial
 * order.
 */
final class RetryingDeclinedChargesTest extends LifecycleTestCase
{
    private int $subscriptionId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->coffee->setTracked(true);
        $this->coffee->setOnHand(10);
        $this->itIsNow('2027-01-01 09:00');
        $this->pay($this->placedBatchOrder());
        $this->subscriptionId = (int) $this->subscriptionsByPlan()['COFFEE_MONTHLY+TEA_MONTHLY']->getId();
    }

    public function testADeclineRetriedTheNextDayAndApprovedPaysTheCycleAndKeepsTheDeclineInItsHistory(): void
    {
        $this->scriptedGateway()->willAnswer(ScriptedGateway::DECLINE, 'Insufficient funds.');
        $this->itIsNow('2027-02-01 09:00');
        $this->runTheCycleCommand();

        $this->itIsNow('2027-02-02 09:00');
        $this->runTheCycleCommand();

        $cycles = $this->storedCycles($this->subscription());
        $second = $cycles[1];
        self::assertSame(SubscriptionCycleInterface::STATE_PAID, $second->getState());
        self::assertSame(
            [['declined', 'Insufficient funds.', '2027-02-01 09:00'], ['approved', null, '2027-02-02 09:00']],
            $this->attemptsOf($second),
        );
        self::assertNull($second->getNextAttemptAt());
        self::assertSame(OrderPaymentStates::STATE_PAID, $second->getOrder()?->getPaymentState());
        self::assertSame(
            [PaymentInterface::STATE_FAILED, PaymentInterface::STATE_COMPLETED],
            $this->paymentStatesOf($second->getOrder()),
            'The retry charged the payment Sylius opened when the first one failed.',
        );
        self::assertSame('2027-03-01 09:00', $cycles[2]->getScheduledAt()?->format('Y-m-d H:i'), 'The retry does not move the calendar.');
        self::assertSame(SubscriptionInterface::STATE_ACTIVE, $this->subscription()->getState());
    }

    public function testWhenTheLastRetryIsDeclinedTooTheCycleFailsItsOrderIsCancelledTheStockGoesBackAndTheNextCycleIsScheduled(): void
    {
        foreach (['2027-02-01 09:00', '2027-02-02 09:00', '2027-02-04 09:00'] as $day) {
            $this->scriptedGateway()->willAnswer(ScriptedGateway::DECLINE, 'Insufficient funds.');
            $this->itIsNow($day);
            $this->runTheCycleCommand();
            self::assertSame(1, $this->variant()->getOnHold(), 'The renewal keeps its stock reserved while it is retried.');
        }

        $this->scriptedGateway()->willAnswer(ScriptedGateway::DECLINE, 'Insufficient funds.');
        $this->itIsNow('2027-02-08 09:00');
        $this->runTheCycleCommand();
        $this->itIsNow('2027-02-20 09:00');
        $this->runTheCycleCommand();

        self::assertSame(['capture', 'capture', 'capture', 'capture'], $this->scriptedGateway()->requests());
        [, $second, $third] = $this->storedCycles($this->subscription());
        self::assertSame(SubscriptionCycleInterface::STATE_FAILED, $second->getState());
        self::assertSame('Insufficient funds.', $second->getCancellationReason());
        self::assertNull($second->getNextAttemptAt());
        self::assertCount(4, $second->getAttempts());
        self::assertSame(OrderInterface::STATE_CANCELLED, $second->getOrder()?->getState());
        self::assertSame(0, $this->variant()->getOnHold());
        self::assertSame(9, $this->variant()->getOnHand());

        self::assertSame(3, $third->getNumber());
        self::assertSame(SubscriptionCycleInterface::STATE_SCHEDULED, $third->getState());
        self::assertSame('2027-03-01 09:00', $third->getScheduledAt()?->format('Y-m-d H:i'), 'The failure does not move the calendar.');

        $subscription = $this->subscription();
        self::assertSame(SubscriptionInterface::STATE_ACTIVE, $subscription->getState());
        self::assertSame(1, $subscription->getConsecutiveFailedCycles());
        foreach ($subscription->getItems() as $item) {
            self::assertSame(1, $item->getPaidCycles(), 'An item of a cycle that was not charged has not been paid again.');
        }
    }

    public function testAChargeThatCouldNotBeAttemptedIsRetriedLikeADecline(): void
    {
        self::getContainer()->set('jpm_martin_sylius_subscription.payment.renewal_charger', new class() implements RenewalChargerInterface {
            public function supports(PaymentMethodInterface $paymentMethod): bool
            {
                return true;
            }

            public function charge(PaymentInterface $payment): ChargeOutcome
            {
                return ChargeOutcome::notAttempted('The card on file has expired.');
            }

            public function status(PaymentInterface $payment): ChargeOutcome
            {
                return ChargeOutcome::unknown();
            }
        });
        $this->itIsNow('2027-02-01 09:00');

        $this->runTheCycleCommand();

        $second = $this->storedCycles($this->subscription())[1];
        self::assertSame(SubscriptionCycleInterface::STATE_AWAITING_PAYMENT, $second->getState());
        self::assertSame([['not_attempted', 'The card on file has expired.', '2027-02-01 09:00']], $this->attemptsOf($second));
        self::assertSame('2027-02-02 09:00', $second->getNextAttemptAt()?->format('Y-m-d H:i'));
    }

    private function subscription(): SubscriptionInterface
    {
        /** @var RepositoryInterface<SubscriptionInterface> $subscriptions */
        $subscriptions = self::getContainer()->get('jpm_martin_sylius_subscription.repository.subscription');
        $subscription = $subscriptions->find($this->subscriptionId);
        self::assertInstanceOf(SubscriptionInterface::class, $subscription);
        $this->entityManager()->refresh($subscription);

        return $subscription;
    }

    private function variant(): ProductVariantInterface
    {
        /** @var RepositoryInterface<ProductVariantInterface> $variants */
        $variants = self::getContainer()->get('sylius.repository.product_variant');
        $variant = $variants->findOneBy(['code' => $this->coffee->getCode()]);
        self::assertInstanceOf(ProductVariantInterface::class, $variant);
        $this->entityManager()->refresh($variant);

        return $variant;
    }

    /** @return list<array{string, ?string, string}> outcome, reason and time of each attempt */
    private function attemptsOf(SubscriptionCycleInterface $cycle): array
    {
        $attempts = [];
        foreach ($cycle->getAttempts() as $attempt) {
            self::assertSame(SubscriptionChargeAttemptInterface::TYPE_CHARGE, $attempt->getType());
            $attempts[] = [$attempt->getOutcome(), $attempt->getReason(), (string) $attempt->getAttemptedAt()?->format('Y-m-d H:i')];
        }

        return $attempts;
    }

    /** @return list<string> */
    private function paymentStatesOf(?OrderInterface $order): array
    {
        self::assertNotNull($order);
        $states = [];
        foreach ($order->getPayments() as $payment) {
            $states[] = $payment->getState();
        }

        return $states;
    }
}
