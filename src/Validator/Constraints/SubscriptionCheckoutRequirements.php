<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Validator\Constraints;

use Symfony\Component\Validator\Constraint;

/**
 * An order with subscription lines can only be completed by a customer with an account, with a
 * payment method the renewal charger can charge, one that keeps the card without charging it when a
 * line has a free trial, and with the current recurring-charge consent accepted. A free trial is not
 * taken through the API yet. Declared on the order for the shop's checkout and on CompleteOrder for the API.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class SubscriptionCheckoutRequirements extends Constraint
{
    public string $accountRequiredMessage = 'jpm_martin_sylius_subscription.checkout.account_required';

    public string $paymentMethodNotSupportedMessage = 'jpm_martin_sylius_subscription.checkout.payment_method_not_supported';

    public string $consentRequiredMessage = 'jpm_martin_sylius_subscription.checkout.consent_required';

    public string $trialPaymentMethodRequiredMessage = 'jpm_martin_sylius_subscription.checkout.trial_payment_method_required';

    public string $trialNotThroughTheApiMessage = 'jpm_martin_sylius_subscription.checkout.trial_not_through_the_api';

    public function validatedBy(): string
    {
        return SubscriptionCheckoutRequirementsValidator::class;
    }

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
