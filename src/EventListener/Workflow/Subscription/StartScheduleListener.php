<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\EventListener\Workflow\Subscription;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionCycleInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionCycleItemInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use JpmMartin\SyliusSubscriptionPlugin\Schedule\SubscriptionSchedulerInterface;
use Psr\Clock\ClockInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Symfony\Component\Workflow\Event\CompletedEvent;
use Webmozart\Assert\Assert;

/**
 * Activation anchors the calendar at the clock's now. The initial order is cycle 1, already paid with
 * every item in it, and the next cycle is scheduled from there. After a free trial, cycle 1 charged
 * nothing and cycle 2 is anchored on the day the trial ends.
 */
final class StartScheduleListener
{
    /**
     * @param FactoryInterface<SubscriptionCycleInterface> $cycleFactory
     * @param FactoryInterface<SubscriptionCycleItemInterface> $cycleItemFactory
     */
    public function __construct(
        private readonly FactoryInterface $cycleFactory,
        private readonly FactoryInterface $cycleItemFactory,
        private readonly SubscriptionSchedulerInterface $scheduler,
        private readonly ClockInterface $clock,
    ) {
    }

    public function __invoke(CompletedEvent $event): void
    {
        $subscription = $event->getSubject();
        Assert::isInstanceOf($subscription, SubscriptionInterface::class);

        $now = $this->clock->now();
        $trialDays = $subscription->getTrialDays();
        $subscription->setActivatedAt($now);
        $subscription->setScheduleAnchorAt(null === $trialDays ? $now : $now->add(new \DateInterval(\sprintf('P%dD', $trialDays))));
        $subscription->setScheduleAnchorCycle(null === $trialDays ? 1 : 2);
        $subscription->setConsecutiveFailedCycles(0);

        $first = $this->cycleFactory->createNew();
        Assert::isInstanceOf($first, SubscriptionCycleInterface::class);
        $first->setNumber(1);
        $first->setScheduledAt($now);
        $first->setState(SubscriptionCycleInterface::STATE_PAID);

        foreach ($subscription->getItems() as $item) {
            $initialOrder = $item->getOriginOrderItem()?->getOrder();
            if (null === $first->getOrder() && $initialOrder instanceof OrderInterface) {
                $first->setOrder($initialOrder);
            }

            $cycleItem = $this->cycleItemFactory->createNew();
            Assert::isInstanceOf($cycleItem, SubscriptionCycleItemInterface::class);
            $cycleItem->setSubscriptionItem($item);
            $cycleItem->setQuantity($item->getQuantity());
            // Before the cycle counts as paid, so an introductory price is the one of the initial order.
            $cycleItem->setUnitPrice(null === $trialDays ? $item->getUnitPriceForCycle() : 0);
            $first->addItem($cycleItem);

            $item->setPaidCycles($item->getPaidCycles() + 1);
        }

        $subscription->addCycle($first);

        $this->scheduler->scheduleNext($subscription);
    }
}
