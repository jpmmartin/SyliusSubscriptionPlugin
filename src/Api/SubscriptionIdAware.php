<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Api;

/** A shop API command about one of the customer's subscriptions: the id of the URI is given to its constructor. */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class SubscriptionIdAware
{
    public const DEFAULT_ARGUMENT_NAME = 'subscriptionId';

    public function __construct(public string $constructorArgumentName = self::DEFAULT_ARGUMENT_NAME)
    {
    }
}
