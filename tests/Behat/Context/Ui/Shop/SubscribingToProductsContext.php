<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Behat\Context\Ui\Shop;

use Behat\Behat\Context\Context;
use Behat\Step\Then;
use Behat\Step\When;
use Sylius\Component\Core\Model\ProductInterface;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Behat\Page\Shop\Cart\RepeatCartPage;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Behat\Page\Shop\Product\SubscriptionPlanChoicePage;
use Webmozart\Assert\Assert;

/**
 * What the product page offers, and subscribing from it. Adding to the cart is a live-component
 * action, so the scenarios that add run in a browser; tests/Functional/Shop covers it without one.
 */
final class SubscribingToProductsContext implements Context
{
    private const LOCALE = 'en_US';

    public function __construct(
        private readonly SubscriptionPlanChoicePage $productPage,
        private readonly RepeatCartPage $cartPage,
    ) {
    }

    #[When('/^I view (product "[^"]+") in the store$/')]
    public function iViewProductInTheStore(ProductInterface $product): void
    {
        $this->productPage->open(['slug' => $product->getTranslation(self::LOCALE)->getSlug(), '_locale' => self::LOCALE]);
    }

    #[When('/^I choose to subscribe on the "([^"]+)" plan$/')]
    public function iChooseToSubscribeOnThePlan(string $planCode): void
    {
        $this->productPage->choosePlan($planCode);
    }

    #[When('I add it to my cart')]
    public function iAddItToMyCart(): void
    {
        $this->productPage->addToCart();
    }

    #[Then('/^my cart should have "([^"]+)" on the "([^"]+)" plan$/')]
    public function myCartShouldHaveOnThePlan(string $productName, string $planCode): void
    {
        Assert::true($this->cartPage->isOpen(), 'The customer is not on the cart page.');
        Assert::same($this->cartPage->getPlanOf($productName), $planCode);
    }

    #[Then('/^I should be able to buy it once or subscribe on the "([^"]+)" plan$/')]
    #[Then('/^I should be able to buy it once or subscribe on the "([^"]+)" and "([^"]+)" plans$/')]
    public function iShouldBeAbleToBuyItOnceOrSubscribeOn(string ...$planCodes): void
    {
        Assert::true($this->productPage->isOneTimePurchaseOffered(), 'Buying once is not offered.');
        Assert::same($this->productPage->getOfferedPlanCodes(), array_values($planCodes));
    }

    #[Then('/^the choices should read "([^"]+)", "([^"]+)" and "([^"]+)"$/')]
    public function theChoicesShouldRead(string ...$labels): void
    {
        $shown = $this->productPage->getChoiceLabels();
        Assert::same($shown, array_values($labels), \sprintf('The choices read %s.', json_encode($shown, \JSON_THROW_ON_ERROR)));
    }

    #[Then('I should only be able to buy it once')]
    public function iShouldOnlyBeAbleToBuyItOnce(): void
    {
        Assert::false($this->productPage->hasPlanChoice(), 'A subscription choice is offered.');
    }
}
