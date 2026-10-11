<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Frequency;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionFrequencyInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionPlanAwareInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionPlanInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Order\Modifier\OrderItemQuantityModifierInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Webmozart\Assert\Assert;

final class CartItemSubscriptionChanger implements CartItemSubscriptionChangerInterface
{
    /** @param RepositoryInterface<SubscriptionPlanInterface> $subscriptionPlanRepository */
    public function __construct(
        private readonly CartRepeaterInterface $cartRepeater,
        private readonly RepeatableVariantsInterface $repeatableVariants,
        private readonly RepositoryInterface $subscriptionPlanRepository,
        private readonly CartItemSubscriptionChoices $choices,
        private readonly OrderItemQuantityModifierInterface $orderItemQuantityModifier,
    ) {
    }

    public function getOptions(OrderItemInterface $item): array
    {
        // Not ready, or not even a line yet: no option is offered, the way adding to the cart offers none either.
        if (!$item instanceof SubscriptionPlanAwareInterface || $item->isImmutable()) {
            return [];
        }

        $options = [new CartItemSubscriptionOption(null, null, null === $item->getSubscriptionPlan() && null === $item->getSubscriptionFrequency())];

        $variant = $item->getVariant();
        if (null === $variant) {
            return $options;
        }

        $order = $item->getOrder();
        if ($order instanceof OrderInterface && [] !== $this->repeatableVariants->filterRepeatable([$variant])) {
            foreach ($this->cartRepeater->getOfferedFrequencies($order) as $frequency) {
                $options[] = new CartItemSubscriptionOption(null, $frequency, $this->sameFrequency($frequency, $item->getSubscriptionFrequency()));
            }
        }

        if (null !== $variant->getId()) {
            foreach ($this->plansOf($variant) as $plan) {
                $options[] = new CartItemSubscriptionOption($plan, null, $this->samePlan($plan, $item->getSubscriptionPlan()));
            }
        }

        return $options;
    }

    public function change(OrderItemInterface $item, ?SubscriptionPlanInterface $plan, ?SubscriptionFrequencyInterface $frequency): void
    {
        if (null !== $plan && null !== $frequency) {
            throw new \InvalidArgumentException('A line cannot be given a plan and a frequency at the same time.');
        }

        if (!$this->isAmongTheOptions($item, $plan, $frequency)) {
            throw new \InvalidArgumentException('This line does not have that subscription option.');
        }

        Assert::isInstanceOf($item, SubscriptionPlanAwareInterface::class);

        if (null !== $plan) {
            $item->setSubscriptionPlan($plan);
            $item->setSubscriptionFrequency(null);
            $this->choices->clear($item);
        } else {
            $item->setSubscriptionPlan(null);
            $item->setSubscriptionFrequency($frequency);
            $this->choices->mark($item);
        }

        $this->mergeWithAMatchingLine($item);
    }

    private function isAmongTheOptions(OrderItemInterface $item, ?SubscriptionPlanInterface $plan, ?SubscriptionFrequencyInterface $frequency): bool
    {
        foreach ($this->getOptions($item) as $option) {
            if ($this->samePlan($option->plan, $plan) && $this->sameFrequency($option->frequency, $frequency)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Joins the changed line into another of the same variant, plan and frequency, exactly as adding a line
     * already does; the loser is removed outright, never processed or flushed here, like every other change this
     * service makes. Its mark, if it had one, is cleared explicitly rather than left to the foreign key's
     * ON DELETE CASCADE: the mark may have just been persisted in this very request, before this call, and
     * nothing here has flushed yet, so Doctrine's unit of work does not know the two are related and could try to
     * insert a row that points at an item it is about to delete in the same flush. Clearing it through
     * CartItemSubscriptionChoices either cancels that pending insert or schedules the row's own removal, so it
     * never reaches the database either way.
     */
    private function mergeWithAMatchingLine(OrderItemInterface $item): void
    {
        Assert::isInstanceOf($item, SubscriptionPlanAwareInterface::class);

        $order = $item->getOrder();
        if (!$order instanceof OrderInterface) {
            return;
        }

        foreach ($order->getItems() as $other) {
            if ($other === $item || !$item->equals($other) || !$other instanceof SubscriptionPlanAwareInterface) {
                continue;
            }

            if (!$this->samePlan($item->getSubscriptionPlan(), $other->getSubscriptionPlan()) || !$this->sameFrequency($item->getSubscriptionFrequency(), $other->getSubscriptionFrequency())) {
                continue;
            }

            $this->orderItemQuantityModifier->modify($other, $other->getQuantity() + $item->getQuantity());
            $this->choices->clear($item);
            $order->removeItem($item);

            return;
        }
    }

    /** @return list<SubscriptionPlanInterface> */
    private function plansOf(ProductVariantInterface $variant): array
    {
        /** @var list<SubscriptionPlanInterface> $plans */
        $plans = $this->subscriptionPlanRepository->findBy(['productVariant' => $variant, 'enabled' => true], ['id' => 'ASC']);

        return $plans;
    }

    private function samePlan(?SubscriptionPlanInterface $a, ?SubscriptionPlanInterface $b): bool
    {
        if (null === $a || null === $b) {
            return $a === $b;
        }

        return $a === $b || (null !== $a->getId() && $a->getId() === $b->getId());
    }

    private function sameFrequency(?SubscriptionFrequencyInterface $a, ?SubscriptionFrequencyInterface $b): bool
    {
        if (null === $a || null === $b) {
            return $a === $b;
        }

        return $a === $b || (null !== $a->getId() && $a->getId() === $b->getId());
    }
}
