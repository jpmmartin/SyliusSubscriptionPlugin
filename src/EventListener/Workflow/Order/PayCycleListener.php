<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\EventListener\Workflow\Order;

use JpmMartin\SyliusSubscriptionPlugin\Cycle\CyclePayer;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionCycleInterface;
use JpmMartin\SyliusSubscriptionPlugin\Repository\SubscriptionCycleRepositoryInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Symfony\Component\Workflow\Event\CompletedEvent;
use Webmozart\Assert\Assert;

/**
 * A paid renewal order pays its cycle, however it came to be paid: by the renewal charge, by a later
 * status check, by an administrator's retry or by hand in the admin. Each item it carried counts one
 * more paid cycle, the run of failures ends and the schedule goes on, unless a cycle is already open.
 */
final class PayCycleListener
{
    /** @param SubscriptionCycleRepositoryInterface<SubscriptionCycleInterface> $cycleRepository */
    public function __construct(
        private readonly SubscriptionCycleRepositoryInterface $cycleRepository,
        private readonly CyclePayer $cyclePayer,
    ) {
    }

    public function __invoke(CompletedEvent $event): void
    {
        $order = $event->getSubject();
        Assert::isInstanceOf($order, OrderInterface::class);

        $cycle = $this->cycleRepository->findOneByOrder($order);
        if (null === $cycle || !$this->cyclePayer->canPay($cycle)) {
            return;
        }

        $this->cyclePayer->pay($cycle);
    }
}
