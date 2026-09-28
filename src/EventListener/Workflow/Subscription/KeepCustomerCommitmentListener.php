<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\EventListener\Workflow\Subscription;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use JpmMartin\SyliusSubscriptionPlugin\Management\SubscriptionCommitmentInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Workflow\Event\GuardEvent;
use Webmozart\Assert\Assert;

/**
 * A customer cannot cancel or pause a subscription within its minimum commitment. The customer's
 * requests are told apart by their route's `_subscription_actor: customer`, which the account's routes
 * declare and the admin's do not: an administrator, the cycles command and every listener still can.
 */
final class KeepCustomerCommitmentListener
{
    public const ACTOR_ATTRIBUTE = '_subscription_actor';

    public const CUSTOMER = 'customer';

    public function __construct(
        private readonly SubscriptionCommitmentInterface $commitment,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function __invoke(GuardEvent $event): void
    {
        $subscription = $event->getSubject();
        Assert::isInstanceOf($subscription, SubscriptionInterface::class);

        if (
            self::CUSTOMER === $this->requestStack->getMainRequest()?->attributes->get(self::ACTOR_ATTRIBUTE) &&
            $this->commitment->isCommitted($subscription)
        ) {
            $event->setBlocked(true, 'It is still within its minimum commitment.');
        }
    }
}
