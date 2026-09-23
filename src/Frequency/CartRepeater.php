<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Frequency;

use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\CartFrequencyInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionFrequencyInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionPlanAwareInterface;
use JpmMartin\SyliusSubscriptionPlugin\Repository\SubscriptionFrequencyRepositoryInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Resource\Factory\FactoryInterface;

final class CartRepeater implements CartRepeaterInterface
{
    /**
     * @param FactoryInterface<CartFrequencyInterface> $cartFrequencyFactory
     * @param SubscriptionFrequencyRepositoryInterface<SubscriptionFrequencyInterface> $frequencyRepository
     * @param class-string<CartFrequencyInterface> $cartFrequencyClass
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FactoryInterface $cartFrequencyFactory,
        private readonly SubscriptionFrequencyRepositoryInterface $frequencyRepository,
        private readonly RepeatableVariantsInterface $repeatableVariants,
        private readonly string $cartFrequencyClass,
    ) {
    }

    public function getFrequency(OrderInterface $cart): ?SubscriptionFrequencyInterface
    {
        return $this->find($cart)?->getFrequency();
    }

    public function repeat(OrderInterface $cart, SubscriptionFrequencyInterface $frequency): void
    {
        if (!$this->isOffered($cart, $frequency)) {
            throw new \InvalidArgumentException(\sprintf('The "%s" subscription frequency is not offered to this cart.', (string) $frequency->getCode()));
        }

        $cartFrequency = $this->find($cart);
        if (null === $cartFrequency) {
            $cartFrequency = $this->cartFrequencyFactory->createNew();
            $cartFrequency->setOrder($cart);
            $this->entityManager->persist($cartFrequency);
        }

        $cartFrequency->setFrequency($frequency);
    }

    public function stopRepeating(OrderInterface $cart): void
    {
        $cartFrequency = $this->find($cart);
        if (null !== $cartFrequency) {
            $this->entityManager->remove($cartFrequency);
        }
    }

    public function getOfferedFrequencies(OrderInterface $cart): array
    {
        $channel = $cart->getChannel();

        return null === $channel ? [] : $this->frequencyRepository->findEnabledByChannel($channel);
    }

    public function isOffered(OrderInterface $cart, SubscriptionFrequencyInterface $frequency): bool
    {
        $channel = $cart->getChannel();

        return null !== $channel && $frequency->isEnabled() && $frequency->hasChannel($channel);
    }

    public function hasRepeatableLines(OrderInterface $cart): bool
    {
        $variants = [];
        foreach ($cart->getItems() as $item) {
            $variant = $item->getVariant();
            if ($item instanceof SubscriptionPlanAwareInterface && !$item->isImmutable() && null === $item->getSubscriptionPlan() && $variant instanceof ProductVariantInterface) {
                $variants[] = $variant;
            }
        }

        return [] !== $this->repeatableVariants->filterRepeatable($variants);
    }

    private function find(OrderInterface $cart): ?CartFrequencyInterface
    {
        $unitOfWork = $this->entityManager->getUnitOfWork();

        // A choice made in this request is not in the database until the caller flushes.
        foreach ($unitOfWork->getScheduledEntityInsertions() as $entity) {
            if ($entity instanceof CartFrequencyInterface && $entity->getOrder() === $cart) {
                return $entity;
            }
        }

        if (null === $cart->getId()) {
            return null;
        }

        $cartFrequency = $this->entityManager->getRepository($this->cartFrequencyClass)->findOneBy(['order' => $cart]);
        if (null === $cartFrequency || $unitOfWork->isScheduledForDelete($cartFrequency)) {
            return null;
        }

        return $cartFrequency;
    }
}
