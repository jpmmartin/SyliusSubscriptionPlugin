<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Validator\Constraints;

use JpmMartin\SyliusSubscriptionPlugin\Consent\SubscriptionConsentRecorderInterface;
use JpmMartin\SyliusSubscriptionPlugin\Order\SubscriptionLines;
use JpmMartin\SyliusSubscriptionPlugin\Payment\RenewalChargerInterface;
use Sylius\Bundle\ApiBundle\Command\Checkout\CompleteOrder;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\Repository\OrderRepositoryInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Webmozart\Assert\Assert;

final class SubscriptionCheckoutRequirementsValidator extends ConstraintValidator
{
    /** @param OrderRepositoryInterface<OrderInterface> $orderRepository */
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly RenewalChargerInterface $renewalCharger,
        private readonly SubscriptionConsentRecorderInterface $consentRecorder,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        Assert::isInstanceOf($constraint, SubscriptionCheckoutRequirements::class);

        $order = $this->resolveOrder($value);
        if (null === $order || !SubscriptionLines::existIn($order)) {
            return;
        }

        $customer = $order->getCustomer();
        if (!$customer instanceof CustomerInterface || null === $customer->getUser()) {
            $this->context->buildViolation($constraint->accountRequiredMessage)->addViolation();
        }

        $paymentMethod = $order->getLastPayment()?->getMethod();
        if (!$paymentMethod instanceof PaymentMethodInterface || !$this->renewalCharger->supports($paymentMethod)) {
            $this->context
                ->buildViolation($constraint->paymentMethodNotSupportedMessage)
                ->setParameter('%payment_method%', (string) $paymentMethod?->getName())
                ->addViolation()
            ;
        }

        if (!$this->consentRecorder->isGivenFor($order)) {
            $this->context->buildViolation($constraint->consentRequiredMessage)->addViolation();
        }
    }

    private function resolveOrder(mixed $value): ?OrderInterface
    {
        if ($value instanceof OrderInterface) {
            return $value;
        }

        if ($value instanceof CompleteOrder) {
            $cart = $this->orderRepository->findCartByTokenValue($value->orderTokenValue);

            return $cart instanceof OrderInterface ? $cart : null;
        }

        return null;
    }
}
