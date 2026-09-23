<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Modifier;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionPlanAwareInterface;
use Sylius\Component\Order\Model\OrderInterface;
use Sylius\Component\Order\Model\OrderItemInterface;
use Sylius\Component\Order\Modifier\OrderItemQuantityModifierInterface;
use Sylius\Component\Order\Modifier\OrderModifierInterface;
use Sylius\Component\Order\Processor\OrderProcessorInterface;

/**
 * Keeps a one-off line and a subscription line of the same variant apart in the cart.
 *
 * Sylius adds a line to the first cart line with the same variant, whatever the plan, so buying a
 * bag of coffee once and subscribing to it would end up as one line and the subscription would be
 * lost. Here a line is merged only into a line with the same variant and the same plan (or both
 * without one); otherwise it is added as a line of its own. The rest is Sylius's: the quantity is
 * changed through its modifier, the cart is processed afterwards, and removing a line is delegated.
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
    ) {
    }

    public function addToOrder(OrderInterface $cart, OrderItemInterface $cartItem): void
    {
        if (!$cartItem instanceof SubscriptionPlanAwareInterface) {
            $this->decorated->addToOrder($cart, $cartItem);

            return;
        }

        foreach ($cart->getItems() as $existingItem) {
            if ($cartItem->equals($existingItem) && $this->haveTheSamePlan($cartItem, $existingItem)) {
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

    private function haveTheSamePlan(SubscriptionPlanAwareInterface $item, OrderItemInterface $existingItem): bool
    {
        $plan = $item->getSubscriptionPlan();
        $existingPlan = $existingItem instanceof SubscriptionPlanAwareInterface ? $existingItem->getSubscriptionPlan() : null;

        if (null === $plan || null === $existingPlan) {
            return $plan === $existingPlan;
        }

        return $plan === $existingPlan || (null !== $plan->getId() && $plan->getId() === $existingPlan->getId());
    }
}
