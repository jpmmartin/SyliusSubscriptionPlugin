<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Validator\Constraints;

use Symfony\Component\Validator\Constraint;

/**
 * An order with subscription lines can only be completed by a customer with an account, with a
 * payment method the renewal charger can charge, and with the current recurring-charge consent
 * accepted. Declared on the order for the shop's checkout and on CompleteOrder for the API.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class SubscriptionCheckoutRequirements extends Constraint
{
    public string $accountRequiredMessage = 'jpm_martin_sylius_subscription.checkout.account_required';

    public string $paymentMethodNotSupportedMessage = 'jpm_martin_sylius_subscription.checkout.payment_method_not_supported';

    public string $consentRequiredMessage = 'jpm_martin_sylius_subscription.checkout.consent_required';

    public function validatedBy(): string
    {
        return SubscriptionCheckoutRequirementsValidator::class;
    }

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
