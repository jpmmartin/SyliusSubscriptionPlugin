<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Integration\Cycle;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionCycleInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionFrequencyInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionIntervalUnit;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Integration\Lifecycle\LifecycleTestCase;

/** Disabling a store frequency stops it being offered; the subscriptions already on it renew as before. */
final class RenewingWithADisabledFrequencyTest extends LifecycleTestCase
{
    public function testASubscriptionRepeatedWithAFrequencyRenewsAfterTheFrequencyIsDisabled(): void
    {
        $monthly = $this->storeFrequency('MONTHLY', 1, SubscriptionIntervalUnit::Month, 5, $this->tea);
        $this->itIsNow('2027-01-01 09:00');
        $order = $this->cart();
        $this->addLine($order, $this->tea, 1, null);
        $this->repeat($order, $monthly);
        $this->placeWithConsent($order);
        $this->pay($order);

        $frequency = $this->entityManager()->find($monthly::class, $monthly->getId());
        self::assertInstanceOf(SubscriptionFrequencyInterface::class, $frequency);
        $frequency->disable();
        $this->entityManager()->flush();

        $this->itIsNow('2027-02-01 09:00');
        $this->runTheCycleCommand();

        $subscription = $this->subscriptionsByPlan()['MONTHLY'];
        self::assertSame(SubscriptionInterface::STATE_ACTIVE, $subscription->getState());
        $cycles = $this->storedCycles($subscription);
        self::assertSame(SubscriptionCycleInterface::STATE_PAID, $cycles[1]->getState());
        self::assertSame(4750, $cycles[1]->getOrder()?->getItemsTotal(), 'Charged at the price frozen with the frequency\'s discount.');
        self::assertSame(2, $this->onlyItemOf($subscription)->getPaidCycles());
    }
}
