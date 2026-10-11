<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\OrderProcessing;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionFrequencyInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionPlanAwareInterface;
use JpmMartin\SyliusSubscriptionPlugin\Frequency\CartItemSubscriptionChoices;
use JpmMartin\SyliusSubscriptionPlugin\Frequency\CartRepeaterInterface;
use JpmMartin\SyliusSubscriptionPlugin\Frequency\RepeatableVariantsInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Order\Model\OrderInterface as BaseOrderInterface;
use Sylius\Component\Order\Processor\OrderProcessorInterface;
use Webmozart\Assert\Assert;

/**
 * Gives each of a cart's non-plan lines its frequency: the cart's own, unless the line chose one for itself
 * (choose-subscription-per-cart-line), in which case that choice wins as long as it is still valid. A line that
 * cannot repeat with its own choice any more, because its variant stopped being repeatable or because the
 * frequency it picked is no longer offered, falls back to buying once; its mark stays, so it does not later
 * inherit the cart's frequency instead (the customer chose not to follow the cart).
 *
 * Runs before the subscriber price (45), which then prices those lines by their frequency's discount. Because it
 * runs on every processing, a line added later is repeated too, and a frequency the cart can no longer have,
 * disabled or taken off its channel, stops the cart being repeated. Lines with a plan keep their plan; immutable
 * lines, those of renewal orders, are left alone.
 */
final class CartFrequencyProcessor implements OrderProcessorInterface
{
    public function __construct(
        private readonly CartRepeaterInterface $cartRepeater,
        private readonly RepeatableVariantsInterface $repeatableVariants,
        private readonly CartItemSubscriptionChoices $choices,
    ) {
    }

    public function process(BaseOrderInterface $order): void
    {
        Assert::isInstanceOf($order, OrderInterface::class);

        if (!$order->canBeProcessed()) {
            return;
        }

        $cartFrequency = $this->cartRepeater->getFrequency($order);
        if (null !== $cartFrequency && !$this->cartRepeater->isOffered($order, $cartFrequency)) {
            $this->cartRepeater->stopRepeating($order);
            $cartFrequency = null;
        }

        /** @var list<array{SubscriptionPlanAwareInterface, ProductVariantInterface, bool, ?SubscriptionFrequencyInterface}> $lines */
        $lines = [];
        foreach ($order->getItems() as $item) {
            if (!$item instanceof SubscriptionPlanAwareInterface || $item->isImmutable()) {
                continue;
            }

            // Read before clearing: a choice made earlier in this same request is already on the line and would
            // otherwise be lost to the blanket reset below, before it is even flushed.
            $hasChoice = $this->choices->has($item);
            $chosenFrequency = $hasChoice ? $item->getSubscriptionFrequency() : null;
            $item->setSubscriptionFrequency(null);

            $variant = $item->getVariant();
            if (null === $item->getSubscriptionPlan() && null !== $variant) {
                $lines[] = [$item, $variant, $hasChoice, $chosenFrequency];
            }
        }

        if ([] === $lines) {
            return;
        }

        $repeatable = $this->repeatableVariants->filterRepeatable(array_column($lines, 1));
        foreach ($lines as [$item, $variant, $hasChoice, $chosenFrequency]) {
            $canRepeat = in_array($variant, $repeatable, true);

            if ($hasChoice) {
                if ($canRepeat && null !== $chosenFrequency && $this->cartRepeater->isOffered($order, $chosenFrequency)) {
                    $item->setSubscriptionFrequency($chosenFrequency);
                }
                // Otherwise the line stays a one-off purchase; its mark is left in place.

                continue;
            }

            if ($canRepeat && null !== $cartFrequency) {
                $item->setSubscriptionFrequency($cartFrequency);
            }
        }
    }
}
