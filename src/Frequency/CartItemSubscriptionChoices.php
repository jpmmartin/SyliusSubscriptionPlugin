<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Frequency;

use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\CartItemSubscriptionChoiceInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionPlanAwareInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Resource\Factory\FactoryInterface;

/**
 * Whether a cart line's subscription was chosen on the line itself (choose-subscription-per-cart-line, D1), shared by
 * the cart's frequency processor, the line changer and "repeat this cart": all three need to see a mark made in the
 * same request before it is flushed, the way CartRepeater::find() already does for the cart's own frequency.
 */
final class CartItemSubscriptionChoices
{
    /**
     * @param FactoryInterface<CartItemSubscriptionChoiceInterface> $choiceFactory
     * @param class-string<CartItemSubscriptionChoiceInterface> $choiceClass
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FactoryInterface $choiceFactory,
        private readonly string $choiceClass,
    ) {
    }

    public function has(OrderItemInterface $item): bool
    {
        return null !== $this->find($item);
    }

    /** Marks the line as owning its own choice. Idempotent: calling it again changes nothing. */
    public function mark(OrderItemInterface $item): void
    {
        if (null !== $this->find($item)) {
            return;
        }

        $choice = $this->choiceFactory->createNew();
        $choice->setOrderItem($item);
        $this->entityManager->persist($choice);
    }

    /** Clears the mark, so the line goes back to following its cart's frequency. Idempotent. */
    public function clear(OrderItemInterface $item): void
    {
        $choice = $this->find($item);
        if (null !== $choice) {
            $this->entityManager->remove($choice);
        }
    }

    /**
     * Clears the mark of every non-plan line of the cart, so that repeating it, or stopping it, applies to all of
     * them uniformly, overriding what any single line had chosen for itself (choose-subscription-per-cart-line,
     * spec "Repetir el carrito pisa lo elegido en una línea" and "Dejar de repetir"). Lines with a plan are left
     * alone: "repeat this cart" never touches them.
     *
     * This is deliberately not called from inside the cart's frequency processor when the cart's own frequency
     * stops being offered: that is housekeeping, not a customer choosing to repeat or stop repeating, and it must
     * not wipe a different line's own, still valid, choice.
     */
    public function clearForCart(OrderInterface $cart): void
    {
        foreach ($cart->getItems() as $item) {
            if ($item instanceof SubscriptionPlanAwareInterface && !$item->isImmutable() && null === $item->getSubscriptionPlan()) {
                $this->clear($item);
            }
        }
    }

    private function find(OrderItemInterface $item): ?CartItemSubscriptionChoiceInterface
    {
        $unitOfWork = $this->entityManager->getUnitOfWork();

        // A mark made in this request is not in the database until the caller flushes.
        foreach ($unitOfWork->getScheduledEntityInsertions() as $entity) {
            if ($entity instanceof CartItemSubscriptionChoiceInterface && $entity->getOrderItem() === $item) {
                return $entity;
            }
        }

        if (null === $item->getId()) {
            return null;
        }

        $choice = $this->entityManager->getRepository($this->choiceClass)->findOneBy(['orderItem' => $item]);
        if (null === $choice || $unitOfWork->isScheduledForDelete($choice)) {
            return null;
        }

        return $choice;
    }
}
