<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Behat\Context\Ui\Shop;

use Behat\Behat\Context\Context;
use Behat\Step\Then;
use Behat\Step\When;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Behat\Page\Shop\Checkout\SubscriptionConsentPage;
use Webmozart\Assert\Assert;

final class SubscriptionCheckoutContext implements Context
{
    public function __construct(private readonly SubscriptionConsentPage $consentPage)
    {
    }

    #[When('I accept the recurring charges of my subscriptions')]
    public function iAcceptTheRecurringChargesOfMySubscriptions(): void
    {
        $this->consentPage->acceptConsent();
    }

    #[Then('I should be told that subscriptions need an account')]
    public function iShouldBeToldThatSubscriptionsNeedAnAccount(): void
    {
        $this->assertMessage('Subscriptions need an account. Please sign in or create an account to place this order.');
    }

    #[Then('/^I should be told that the "([^"]+)" payment method cannot be used for subscriptions$/')]
    public function iShouldBeToldThatThePaymentMethodCannotBeUsedForSubscriptions(string $paymentMethodName): void
    {
        $this->assertMessage(\sprintf('The "%s" payment method cannot be used for subscriptions. Please choose another one.', $paymentMethodName));
    }

    #[Then('I should be told to accept the recurring charges of my subscriptions')]
    public function iShouldBeToldToAcceptTheRecurringCharges(): void
    {
        $this->assertMessage('Please accept the recurring charges of your subscriptions to place this order.');
    }

    private function assertMessage(string $message): void
    {
        Assert::true($this->consentPage->showsMessage($message), \sprintf('The page does not say "%s".', $message));
    }
}
