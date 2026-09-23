<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\EventListener\Workflow\OrderCheckout;

use Doctrine\Persistence\ObjectManager;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionCycleInterface;
use JpmMartin\SyliusSubscriptionPlugin\Factory\SubscriptionFactoryInterface;
use JpmMartin\SyliusSubscriptionPlugin\Order\SubscriptionLines;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Symfony\Component\Workflow\Event\CompletedEvent;
use Webmozart\Assert\Assert;

/**
 * Starts one pending subscription per interval among the subscription lines of a placed order, so the
 * lines renewed on the same days arrive in one order, whether they renew on a plan or on the
 * frequency their cart was repeated with. A renewal order is the order of a cycle and
 * starts none, so a cycle has to be linked to its renewal order, and flushed, before that order's
 * checkout is completed. Cycle 1 is only created once the initial order is paid, so no cycle points
 * at an initial order here.
 */
final class CreateSubscriptionsListener
{
    /** @param RepositoryInterface<SubscriptionCycleInterface> $cycleRepository */
    public function __construct(
        private readonly SubscriptionFactoryInterface $subscriptionFactory,
        private readonly ObjectManager $subscriptionManager,
        private readonly RepositoryInterface $cycleRepository,
    ) {
    }

    public function __invoke(CompletedEvent $event): void
    {
        $order = $event->getSubject();
        Assert::isInstanceOf($order, OrderInterface::class);

        if ($this->isRenewal($order)) {
            return;
        }

        $linesByInterval = [];
        foreach (SubscriptionLines::of($order) as $orderItem) {
            $terms = $orderItem->getSubscriptionTerms();
            Assert::notNull($terms);
            $linesByInterval[$terms->getIntervalCount() . ' ' . $terms->getIntervalUnit()->value][] = $orderItem;
        }

        foreach ($linesByInterval as $lines) {
            $this->subscriptionManager->persist($this->subscriptionFactory->createFromOrderItems($lines));
        }
    }

    private function isRenewal(OrderInterface $order): bool
    {
        return null !== $order->getId() && null !== $this->cycleRepository->findOneBy(['order' => $order]);
    }
}
