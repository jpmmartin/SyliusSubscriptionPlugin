<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Validator\Constraints;

use JpmMartin\SyliusSubscriptionPlugin\Command\ChangeCartItemSubscriptionTerms;
use JpmMartin\SyliusSubscriptionPlugin\Frequency\CartItemSubscriptionChangerInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Order\Repository\OrderItemRepositoryInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Webmozart\Assert\Assert;

final class CartItemSubscriptionTermsOfferedValidator extends ConstraintValidator
{
    /** @param OrderItemRepositoryInterface<OrderItemInterface> $orderItemRepository */
    public function __construct(
        private readonly OrderItemRepositoryInterface $orderItemRepository,
        private readonly CartItemSubscriptionChangerInterface $changer,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        Assert::isInstanceOf($value, ChangeCartItemSubscriptionTerms::class);
        Assert::isInstanceOf($constraint, CartItemSubscriptionTermsOffered::class);

        if (null !== $value->subscriptionPlan && null !== $value->subscriptionFrequency) {
            $this->context
                ->buildViolation($constraint->bothMessage)
                ->atPath('subscriptionPlan')
                ->addViolation()
            ;

            return;
        }

        // An item that cannot be found here, because it does not exist or belongs to a different cart, is the
        // handler's 404 to give, not a validation failure.
        $item = $this->orderItemRepository->findOneByIdAndCartTokenValue($value->orderItemId, $value->orderTokenValue);
        if (!$item instanceof OrderItemInterface) {
            return;
        }

        $requested = $this->requestedValue($value);
        foreach ($this->changer->getOptions($item) as $option) {
            if ($option->value() === $requested) {
                return;
            }
        }

        $this->context
            ->buildViolation($constraint->notOfferedMessage)
            ->atPath(null !== $value->subscriptionPlan ? 'subscriptionPlan' : 'subscriptionFrequency')
            ->addViolation()
        ;
    }

    /** The same encoding CartItemSubscriptionOption::value() gives each option, so the two compare directly. */
    private function requestedValue(ChangeCartItemSubscriptionTerms $value): string
    {
        if (null !== $value->subscriptionPlan) {
            return 'plan:' . $value->subscriptionPlan;
        }

        if (null !== $value->subscriptionFrequency) {
            return 'frequency:' . $value->subscriptionFrequency;
        }

        return '';
    }
}
