<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Command\Shop;

use JpmMartin\SyliusSubscriptionPlugin\Api\SubscriptionIdAware;

/** Resumes the customer's paused subscription. Through the shop API: the id is the URI's. */
#[SubscriptionIdAware]
final readonly class ResumeSubscription
{
    public function __construct(
        public string $subscriptionId,
    ) {
    }
}
