<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Validator\Constraints;

use JpmMartin\SyliusSubscriptionPlugin\Command\AddSubscriptionItemToCart;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionPlanInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Core\Repository\OrderRepositoryInterface;
use Sylius\Component\Core\Repository\ProductVariantRepositoryInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Webmozart\Assert\Assert;

final class SubscriptionItemAddableValidator extends ConstraintValidator
{
    /**
     * @param ProductVariantRepositoryInterface<ProductVariantInterface> $productVariantRepository
     * @param RepositoryInterface<SubscriptionPlanInterface> $subscriptionPlanRepository
     * @param OrderRepositoryInterface<OrderInterface> $orderRepository
     */
    public function __construct(
        private readonly ProductVariantRepositoryInterface $productVariantRepository,
        private readonly RepositoryInterface $subscriptionPlanRepository,
        private readonly OrderRepositoryInterface $orderRepository,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        Assert::isInstanceOf($value, AddSubscriptionItemToCart::class);
        Assert::isInstanceOf($constraint, SubscriptionItemAddable::class);

        $variant = $this->productVariantRepository->findOneBy(['code' => $value->productVariantCode]);
        $cart = $this->orderRepository->findCartByTokenValue($value->orderTokenValue);

        if (!$variant instanceof ProductVariantInterface || !$this->isSold($variant, $cart instanceof OrderInterface ? $cart : null)) {
            $this->context
                ->buildViolation($constraint->productVariantNotAvailableMessage)
                ->atPath('productVariantCode')
                ->addViolation()
            ;

            return;
        }

        $plan = $this->subscriptionPlanRepository->findOneBy(['code' => $value->subscriptionPlanCode]);

        if (
            !$plan instanceof SubscriptionPlanInterface ||
            !$plan->isEnabled() ||
            $plan->getProductVariant()?->getId() !== $variant->getId()
        ) {
            $this->context
                ->buildViolation($constraint->subscriptionPlanNotOfferedMessage)
                ->atPath('subscriptionPlanCode')
                ->addViolation()
            ;
        }
    }

    private function isSold(ProductVariantInterface $variant, ?OrderInterface $cart): bool
    {
        $product = $variant->getProduct();
        if (!$variant->isEnabled() || !$product instanceof ProductInterface || !$product->isEnabled()) {
            return false;
        }

        $channel = $cart?->getChannel();

        return null === $channel || $product->hasChannel($channel);
    }
}
