<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Integration\Event;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use JpmMartin\SyliusSubscriptionPlugin\Event\IntroductoryPriceEnding;
use JpmMartin\SyliusSubscriptionPlugin\Event\RenewalUpcoming;
use JpmMartin\SyliusSubscriptionPlugin\Event\TrialEnding;
use JpmMartin\SyliusSubscriptionPlugin\Management\SubscriptionRenewalSkipperInterface;
use Sylius\Component\Payment\Model\PaymentInterface;
use Sylius\Component\Payment\PaymentTransitions;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Event\EventCollector;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Integration\Lifecycle\LifecycleTestCase;

/**
 * Coffee at $100.00 on its monthly plan with 10% off and 14 days of free trial, subscribed to on 1
 * March: its first charge is on 15 March, announced three days before.
 */
final class TrialEndingTest extends LifecycleTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->coffeeMonthly->setTrialDays(14);
        $this->entityManager()->flush();
        $this->itIsNow('2027-03-01 09:00');
        $order = $this->placedCoffeeOrder();
        $payment = $order->getLastPayment(PaymentInterface::STATE_NEW);
        self::assertNotNull($payment);
        $this->apply($payment, PaymentTransitions::GRAPH, PaymentTransitions::TRANSITION_AUTHORIZE);
        $this->entityManager()->flush();
        $this->collector()->clear();
    }

    public function testTheFirstChargeAfterTheTrialIsAnnouncedWithItsTotal(): void
    {
        $this->itIsNow('2027-03-12 09:00');
        $this->runTheCycleCommand();

        [, $second] = $this->storedCycles($this->subscription());
        $subscriptionId = (int) $this->subscription()->getId();
        self::assertEquals([
            new RenewalUpcoming($subscriptionId, (int) $second->getId(), 2, new \DateTimeImmutable('2027-03-15 09:00')),
            new TrialEnding($subscriptionId, (int) $second->getId(), 2, new \DateTimeImmutable('2027-03-15 09:00'), 9000),
        ], $this->collector()->events());
        self::assertSame([], $this->collector()->events(IntroductoryPriceEnding::class), 'A free trial is no introductory price.');

        $this->itIsNow('2027-03-15 09:00');
        $this->runTheCycleCommand();
        $this->itIsNow('2027-04-12 09:00');
        $this->runTheCycleCommand();
        self::assertCount(1, $this->collector()->events(TrialEnding::class), 'The renewal after the first charge ends nothing.');
    }

    public function testAfterASkippedFirstRenewalTheNextOneIsAnnouncedAsTheFirstCharge(): void
    {
        /** @var SubscriptionRenewalSkipperInterface $skipper */
        $skipper = self::getContainer()->get('jpm_martin_sylius_subscription.management.renewal_skipper');
        $skipper->skip($this->subscription());
        $this->entityManager()->flush();

        $this->itIsNow('2027-04-12 09:00');
        $this->runTheCycleCommand();

        $events = $this->collector()->events(TrialEnding::class);
        self::assertCount(1, $events);
        self::assertInstanceOf(TrialEnding::class, $events[0]);
        self::assertSame([3, '2027-04-15'], [$events[0]->cycleNumber, $events[0]->scheduledAt->format('Y-m-d')]);
    }

    private function subscription(): SubscriptionInterface
    {
        $subscriptions = $this->storedSubscriptions();
        self::assertCount(1, $subscriptions);

        return $subscriptions[0];
    }

    private function collector(): EventCollector
    {
        $collector = self::getContainer()->get('jpm_martin_sylius_subscription.test.event_collector');
        self::assertInstanceOf(EventCollector::class, $collector);

        return $collector;
    }
}
