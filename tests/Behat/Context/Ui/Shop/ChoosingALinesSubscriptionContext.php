<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Behat\Context\Ui\Shop;

use Behat\Behat\Context\Context;
use Behat\Step\Then;
use Behat\Step\When;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Behat\Page\Shop\Cart\RepeatCartPage;
use Webmozart\Assert\Assert;

/** choose-subscription-per-cart-line: the dropdown a single cart line offers when it has more than one option. */
final class ChoosingALinesSubscriptionContext implements Context
{
    public function __construct(private readonly RepeatCartPage $cartPage)
    {
    }

    #[When('/^I choose "([^"]+)" for the "([^"]+)" line$/')]
    public function iChooseForTheLine(string $label, string $productName): void
    {
        $this->cartPage->open();
        $this->cartPage->chooseSubscriptionOption($productName, $label);
    }

    #[Then('/^the "([^"]+)" line should be repeated "([^"]+)"$/')]
    public function theLineShouldBeRepeated(string $productName, string $label): void
    {
        Assert::same($this->cartPage->getCurrentSubscriptionChoice($productName), $label);
    }

    #[Then('/^the "([^"]+)" line should have the plan "([^"]+)"$/')]
    public function theLineShouldHaveThePlan(string $productName, string $planCode): void
    {
        Assert::contains($this->cartPage->getCurrentSubscriptionChoice($productName), $planCode);
    }

    #[Then('/^the "([^"]+)" line should have no plan and should not be repeated$/')]
    public function theLineShouldHaveNoPlanAndShouldNotBeRepeated(string $productName): void
    {
        Assert::same($this->cartPage->getCurrentSubscriptionChoice($productName), 'One-time purchase');
    }

    #[Then('/^the "([^"]+)" line should not offer to change its subscription$/')]
    public function theLineShouldNotOfferToChangeItsSubscription(string $productName): void
    {
        if (!$this->cartPage->isOpen()) {
            $this->cartPage->open();
        }

        Assert::false($this->cartPage->hasSubscriptionOptions($productName));
    }
}
