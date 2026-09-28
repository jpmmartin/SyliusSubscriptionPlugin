<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\StateProvider\Shop;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use JpmMartin\SyliusSubscriptionPlugin\Api\ShopSubscriptionActions;
use JpmMartin\SyliusSubscriptionPlugin\Api\ShopSubscriptionLink;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;

/**
 * The link one of the shop API's link operations asks for, on one of the customer's subscriptions; none
 * answers 404, as the account shows no link then.
 *
 * @implements ProviderInterface<ShopSubscriptionLink>
 */
final class SubscriptionLinkProvider implements ProviderInterface
{
    public const RENEWAL_PAYMENT = 'renewal_payment';

    public const RECOVERY = 'recovery';

    public const CARD_UPDATE = 'card_update';

    /** @param ProviderInterface<SubscriptionInterface> $subscriptionProvider */
    public function __construct(
        private readonly ProviderInterface $subscriptionProvider,
        private readonly ShopSubscriptionActions $actions,
        private readonly string $link,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): ?ShopSubscriptionLink
    {
        $subscription = $this->subscriptionProvider->provide($operation, $uriVariables, $context);
        if (!$subscription instanceof SubscriptionInterface) {
            return null;
        }

        $url = match ($this->link) {
            self::RENEWAL_PAYMENT => $this->actions->renewalPaymentLink($subscription),
            self::RECOVERY => $this->actions->recoveryLink($subscription),
            self::CARD_UPDATE => $this->actions->cardUpdateLink($subscription),
            default => null,
        };

        return null === $url ? null : new ShopSubscriptionLink($url);
    }
}
