<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Payment;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;

/**
 * A gateway integration's page where the customer changes the card their subscription is charged
 * with, without a renewal to pay. The card is the gateway's, kept its own way, so only the
 * integration knows where and how. Tag an implementation with
 * `jpm_martin_sylius_subscription.card_update_provider`, or let autoconfiguration do it; the
 * customer's account offers it when one supports the subscription. The plugin registers none.
 */
interface CardUpdateProviderInterface
{
    /** Whether the customer can change the card of this subscription here, usually by its payment method. */
    public function supports(SubscriptionInterface $subscription): bool;

    /** Where the customer changes it: the integration's own page, absolute or on the store. */
    public function getUrl(SubscriptionInterface $subscription): string;
}
