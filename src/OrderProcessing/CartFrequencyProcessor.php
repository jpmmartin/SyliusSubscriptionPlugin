<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\OrderProcessing;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionPlanAwareInterface;
use JpmMartin\SyliusSubscriptionPlugin\Frequency\CartRepeaterInterface;
use JpmMartin\SyliusSubscriptionPlugin\Frequency\RepeatableVariantsInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Order\Model\OrderInterface as BaseOrderInterface;
use Sylius\Component\Order\Processor\OrderProcessorInterface;
use Webmozart\Assert\Assert;

/**
 * Gives the frequency a cart is repeated with to each of its one-off lines of a variant that can be
 * repeated, and takes it from every other line.
 *
 * Runs before the subscriber price (45), which then prices those lines by the frequency's discount.
 * Because it runs on every processing, a line added later is repeated too, and a frequency the cart
 * can no longer have, disabled or taken off its channel, stops the cart being repeated. Lines with a
 * plan keep their plan; immutable lines, those of renewal orders, are left alone.
 */
final class CartFrequencyProcessor implements OrderProcessorInterface
{
    public function __construct(
        private readonly CartRepeaterInterface $cartRepeater,
        private readonly RepeatableVariantsInterface $repeatableVariants,
    ) {
    }

    public function process(BaseOrderInterface $order): void
    {
        Assert::isInstanceOf($order, OrderInterface::class);

        if (!$order->canBeProcessed()) {
            return;
        }

        $frequency = $this->cartRepeater->getFrequency($order);
        if (null !== $frequency && !$this->cartRepeater->isOffered($order, $frequency)) {
            $this->cartRepeater->stopRepeating($order);
            $frequency = null;
        }

        /** @var list<array{SubscriptionPlanAwareInterface, ProductVariantInterface}> $oneOffLines */
        $oneOffLines = [];
        foreach ($order->getItems() as $item) {
            if (!$item instanceof SubscriptionPlanAwareInterface || $item->isImmutable()) {
                continue;
            }

            $item->setSubscriptionFrequency(null);
            $variant = $item->getVariant();
            if (null === $item->getSubscriptionPlan() && null !== $variant) {
                $oneOffLines[] = [$item, $variant];
            }
        }

        if (null === $frequency || [] === $oneOffLines) {
            return;
        }

        $repeatable = $this->repeatableVariants->filterRepeatable(array_column($oneOffLines, 1));
        foreach ($oneOffLines as [$item, $variant]) {
            if (in_array($variant, $repeatable, true)) {
                $item->setSubscriptionFrequency($frequency);
            }
        }
    }
}
