<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\EventListener\Workflow\Subscription;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use JpmMartin\SyliusSubscriptionPlugin\Pricing\PendingPriceApplier;
use Symfony\Component\Workflow\Event\GuardEvent;
use Webmozart\Assert\Assert;

/**
 * A subscription with a price increase its customer must accept, and has not, cannot be resumed: it
 * would renew at a price nobody agreed to. The customer's account offers to accept it and resume.
 */
final class RequirePriceIncreaseAcceptanceToResumeListener
{
    public function __construct(private readonly PendingPriceApplier $pendingPriceApplier)
    {
    }

    public function __invoke(GuardEvent $event): void
    {
        $subscription = $event->getSubject();
        Assert::isInstanceOf($subscription, SubscriptionInterface::class);

        if ($this->pendingPriceApplier->awaitsAcceptance($subscription)) {
            $event->setBlocked(true, 'Its customer must accept the new price first.');
        }
    }
}
