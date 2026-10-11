<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Modifier;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionFrequencyInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionPlanAwareInterface;
use JpmMartin\SyliusSubscriptionPlugin\Frequency\CartRepeaterInterface;
use JpmMartin\SyliusSubscriptionPlugin\Frequency\RepeatableVariantsInterface;
use Sylius\Component\Core\Model\OrderInterface as CoreOrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface as CoreOrderItemInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Order\Model\OrderInterface;
use Sylius\Component\Order\Model\OrderItemInterface;
use Sylius\Component\Order\Modifier\OrderItemQuantityModifierInterface;
use Sylius\Component\Order\Modifier\OrderModifierInterface;
use Sylius\Component\Order\Processor\OrderProcessorInterface;

/**
 * Keeps a one-off line and a subscription line of the same variant apart in the cart, and keeps apart two one-off
 * lines of the same variant that would enter repeated with different frequencies (choose-subscription-per-cart-line).
 *
 * Sylius adds a line to the first cart line with the same variant, whatever the plan, so buying a bag of coffee
 * once and subscribing to it would end up as one line and the subscription would be lost. Here a line is merged
 * only into a line of the same variant, the same plan (or both without one), and the same frequency the new line
 * would itself enter the cart repeated with; otherwise it is added as a line of its own. The rest is Sylius's: the
 * quantity is changed through its modifier, the cart is processed afterwards, and removing a line is delegated.
 *
 * Overriding OrderItem::equals() would have done the same with less code, but it would change what
 * "equal lines" means everywhere else in Sylius too.
 */
final class SubscriptionAwareOrderModifier implements OrderModifierInterface
{
    public function __construct(
        private readonly OrderModifierInterface $decorated,
        private readonly OrderProcessorInterface $orderProcessor,
        private readonly OrderItemQuantityModifierInterface $orderItemQuantityModifier,
        private readonly CartRepeaterInterface $cartRepeater,
        private readonly RepeatableVariantsInterface $repeatableVariants,
    ) {
    }

    public function addToOrder(OrderInterface $cart, OrderItemInterface $cartItem): void
    {
        if (!$cartItem instanceof SubscriptionPlanAwareInterface) {
            $this->decorated->addToOrder($cart, $cartItem);

            return;
        }

        $frequency = $this->enteringFrequency($cart, $cartItem);

        foreach ($cart->getItems() as $existingItem) {
            if (
                $cartItem->equals($existingItem) &&
                $this->haveTheSamePlan($cartItem, $existingItem) &&
                $this->haveTheSameFrequency($frequency, $existingItem)
            ) {
                $this->orderItemQuantityModifier->modify(
                    $existingItem,
                    $existingItem->getQuantity() + $cartItem->getQuantity(),
                );
                $this->orderProcessor->process($cart);

                return;
            }
        }

        $cart->addItem($cartItem);
        $this->orderProcessor->process($cart);
    }

    public function removeFromOrder(OrderInterface $cart, OrderItemInterface $item): void
    {
        $this->decorated->removeFromOrder($cart, $item);
    }

    /**
     * The frequency the cart's own processor would give this line once it is added: the cart's own frequency, if
     * the line has no plan and its variant can be repeated; none otherwise. Computed here only to decide whether
     * to merge; the processor is what actually sets it, right after addToOrder() processes the cart.
     */
    private function enteringFrequency(OrderInterface $cart, SubscriptionPlanAwareInterface $cartItem): ?SubscriptionFrequencyInterface
    {
        if (null !== $cartItem->getSubscriptionPlan() || !$cart instanceof CoreOrderInterface || !$cartItem instanceof CoreOrderItemInterface) {
            return null;
        }

        $variant = $cartItem->getVariant();
        if (!$variant instanceof ProductVariantInterface || [] === $this->repeatableVariants->filterRepeatable([$variant])) {
            return null;
        }

        return $this->cartRepeater->getFrequency($cart);
    }

    private function haveTheSamePlan(SubscriptionPlanAwareInterface $item, OrderItemInterface $existingItem): bool
    {
        $plan = $item->getSubscriptionPlan();
        $existingPlan = $existingItem instanceof SubscriptionPlanAwareInterface ? $existingItem->getSubscriptionPlan() : null;

        if (null === $plan || null === $existingPlan) {
            return $plan === $existingPlan;
        }

        return $plan === $existingPlan || (null !== $plan->getId() && $plan->getId() === $existingPlan->getId());
    }

    private function haveTheSameFrequency(?SubscriptionFrequencyInterface $frequency, OrderItemInterface $existingItem): bool
    {
        $existingFrequency = $existingItem instanceof SubscriptionPlanAwareInterface ? $existingItem->getSubscriptionFrequency() : null;

        if (null === $frequency || null === $existingFrequency) {
            return $frequency === $existingFrequency;
        }

        return $frequency === $existingFrequency || (null !== $frequency->getId() && $frequency->getId() === $existingFrequency->getId());
    }
}
