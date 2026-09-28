<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Api;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use JpmMartin\SyliusSubscriptionPlugin\Management\SubscriptionAddressChangerInterface;
use JpmMartin\SyliusSubscriptionPlugin\Management\SubscriptionFrequencyChangerInterface;
use JpmMartin\SyliusSubscriptionPlugin\Management\SubscriptionItemEditorInterface;
use JpmMartin\SyliusSubscriptionPlugin\Management\SubscriptionRecoveryInterface;
use JpmMartin\SyliusSubscriptionPlugin\Management\SubscriptionRenewalSkipperInterface;
use JpmMartin\SyliusSubscriptionPlugin\Payment\CardUpdateProviderRegistry;
use JpmMartin\SyliusSubscriptionPlugin\Payment\RenewalPaymentLinkGeneratorInterface;
use JpmMartin\SyliusSubscriptionPlugin\Pricing\PendingPriceApplier;
use JpmMartin\SyliusSubscriptionPlugin\StateMachine\SubscriptionTransitions;
use Sylius\Abstraction\StateMachine\StateMachineInterface;

/**
 * What the customer's account would offer on a subscription, by name, with the same checks: the shop API
 * lists them on its detail, and refuses the others. Asked within a request of the customer, so the
 * minimum commitment is kept.
 */
final class ShopSubscriptionActions
{
    public const CANCEL = 'cancel';

    public const PAUSE = 'pause';

    public const RESUME = 'resume';

    public const SKIP_RENEWAL = 'skip_renewal';

    public const CHANGE_FREQUENCY = 'change_frequency';

    public const CHANGE_ADDRESS = 'change_address';

    public const CHANGE_ITEMS = 'change_items';

    public const ADD_ITEM = 'add_item';

    public const ACCEPT_PRICE_INCREASE = 'accept_price_increase';

    public const PAY_RENEWAL = 'pay_renewal';

    public const RECOVER = 'recover';

    public const UPDATE_CARD = 'update_card';

    public function __construct(
        private readonly StateMachineInterface $stateMachine,
        private readonly SubscriptionRenewalSkipperInterface $renewalSkipper,
        private readonly SubscriptionFrequencyChangerInterface $frequencyChanger,
        private readonly SubscriptionAddressChangerInterface $addressChanger,
        private readonly SubscriptionItemEditorInterface $itemEditor,
        private readonly PendingPriceApplier $pendingPriceApplier,
        private readonly SubscriptionRecoveryInterface $recovery,
        private readonly RenewalPaymentLinkGeneratorInterface $paymentLinkGenerator,
        private readonly CardUpdateProviderRegistry $cardUpdateProviderRegistry,
    ) {
    }

    /** @return list<string> */
    public function of(SubscriptionInterface $subscription): array
    {
        $actions = [];
        if ($this->can($subscription, SubscriptionTransitions::TRANSITION_CANCEL) && !$subscription->cancelsAfterPrepaidDeliveries()) {
            $actions[] = self::CANCEL;
        }
        if ($this->can($subscription, SubscriptionTransitions::TRANSITION_PAUSE)) {
            $actions[] = self::PAUSE;
        }
        if ($this->can($subscription, SubscriptionTransitions::TRANSITION_RESUME)) {
            $actions[] = self::RESUME;
        }
        if ($this->renewalSkipper->canSkip($subscription)) {
            $actions[] = self::SKIP_RENEWAL;
        }
        if ([] !== $this->frequencyChanger->frequenciesToChangeTo($subscription)) {
            $actions[] = self::CHANGE_FREQUENCY;
        }
        if ($this->addressChanger->canChange($subscription)) {
            $actions[] = self::CHANGE_ADDRESS;
        }
        if ([] !== $this->itemEditor->editableItems($subscription)) {
            $actions[] = self::CHANGE_ITEMS;
            if ([] !== $this->itemEditor->variantsToAdd($subscription)) {
                $actions[] = self::ADD_ITEM;
            }
        }
        if ($this->pendingPriceApplier->awaitsAcceptance($subscription)) {
            $actions[] = self::ACCEPT_PRICE_INCREASE;
        }
        if (null !== $this->renewalPaymentLink($subscription)) {
            $actions[] = self::PAY_RENEWAL;
        }
        if ($this->recovery->canRecover($subscription)) {
            $actions[] = self::RECOVER;
        }
        if (null !== $this->cardUpdateLink($subscription)) {
            $actions[] = self::UPDATE_CARD;
        }

        return $actions;
    }

    public function allows(SubscriptionInterface $subscription, string $action): bool
    {
        return \in_array($action, $this->of($subscription), true);
    }

    /** Where the customer pays the renewal whose charge was declined, the latest first; null when none waits for them. */
    public function renewalPaymentLink(SubscriptionInterface $subscription): ?string
    {
        foreach (array_reverse($subscription->getCycles()->toArray()) as $cycle) {
            $link = $this->paymentLinkGenerator->generate($cycle);
            if (null !== $link) {
                return $link;
            }
        }

        return null;
    }

    public function recoveryLink(SubscriptionInterface $subscription): ?string
    {
        return $this->paymentLinkGenerator->generateRecovery($subscription);
    }

    public function cardUpdateLink(SubscriptionInterface $subscription): ?string
    {
        if (\in_array($subscription->getState(), [SubscriptionInterface::STATE_CANCELLED, SubscriptionInterface::STATE_COMPLETED], true)) {
            return null;
        }

        return $this->cardUpdateProviderRegistry->urlFor($subscription);
    }

    private function can(SubscriptionInterface $subscription, string $transition): bool
    {
        return $this->stateMachine->can($subscription, SubscriptionTransitions::GRAPH, $transition);
    }
}
