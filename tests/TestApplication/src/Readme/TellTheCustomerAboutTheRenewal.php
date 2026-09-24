<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Readme;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use JpmMartin\SyliusSubscriptionPlugin\Event\RenewalUpcoming;
use JpmMartin\SyliusSubscriptionPlugin\Repository\SubscriptionRepositoryInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(bus: 'sylius.event_bus')]
final class TellTheCustomerAboutTheRenewal
{
    /** @param SubscriptionRepositoryInterface<SubscriptionInterface> $subscriptions */
    public function __construct(private readonly SubscriptionRepositoryInterface $subscriptions)
    {
    }

    public function __invoke(RenewalUpcoming $event): void
    {
        $subscription = $this->subscriptions->find($event->subscriptionId);
        if (!$subscription instanceof SubscriptionInterface) {
            return;
        }

        // Email $subscription->getCustomer() that it renews on $event->scheduledAt, with your own sender.
    }
}
