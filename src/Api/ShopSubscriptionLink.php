<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Api;

/** An absolute address the customer is sent to: to pay a renewal, to recover their subscription or to change their card. */
final readonly class ShopSubscriptionLink
{
    public function __construct(public string $url)
    {
    }
}
