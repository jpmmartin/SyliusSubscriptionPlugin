<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Validator\Constraints;

use JpmMartin\SyliusSubscriptionPlugin\Command\RepeatCart;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionFrequencyInterface;
use JpmMartin\SyliusSubscriptionPlugin\Frequency\CartRepeaterInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Repository\OrderRepositoryInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Webmozart\Assert\Assert;

final class SubscriptionFrequencyOfferedValidator extends ConstraintValidator
{
    /** @param OrderRepositoryInterface<OrderInterface> $orderRepository */
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly CartRepeaterInterface $cartRepeater,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        Assert::isInstanceOf($value, RepeatCart::class);
        Assert::isInstanceOf($constraint, SubscriptionFrequencyOffered::class);

        // Stopping repeating a cart needs no frequency.
        if (null === $value->subscriptionFrequencyCode) {
            return;
        }

        $cart = $this->orderRepository->findCartByTokenValue($value->orderTokenValue);
        $offeredCodes = $cart instanceof OrderInterface ? array_map(
            static fn (SubscriptionFrequencyInterface $frequency): ?string => $frequency->getCode(),
            $this->cartRepeater->getOfferedFrequencies($cart),
        ) : [];

        if (!\in_array($value->subscriptionFrequencyCode, $offeredCodes, true)) {
            $this->context
                ->buildViolation($constraint->message)
                ->atPath('subscriptionFrequencyCode')
                ->addViolation()
            ;
        }
    }
}
