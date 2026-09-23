<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Behat\Context\Ui\Admin;

use Behat\Behat\Context\Context;
use Behat\Step\Then;
use Behat\Step\When;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Behat\Page\Admin\ProductVariant\SubscriptionPlansPage;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Behat\Page\Admin\SubscriptionPlan\CreatePage;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Behat\Page\Admin\SubscriptionPlan\UpdatePage;
use Webmozart\Assert\Assert;

final class ManagingSubscriptionPlansContext implements Context
{
    public function __construct(
        private readonly SubscriptionPlansPage $subscriptionPlansPage,
        private readonly CreatePage $createPage,
        private readonly UpdatePage $updatePage,
    ) {
    }

    #[When('/^I want to add a subscription plan to the ("[^"]+" variant)$/')]
    public function iWantToAddASubscriptionPlanTo(ProductVariantInterface $variant): void
    {
        $this->openVariant($variant);
        $this->subscriptionPlansPage->addPlan();
    }

    #[When('I specify its code as :code')]
    public function iSpecifyItsCodeAs(string $code): void
    {
        $this->createPage->specifyCode($code);
    }

    #[When('I name it :name')]
    public function iNameIt(string $name): void
    {
        $this->createPage->nameIt($name);
    }

    #[When('/^I set it to renew every (\d+) (day|week|month|year)s?$/')]
    public function iSetItToRenewEvery(string $intervalCount, string $intervalUnit): void
    {
        $this->createPage->setInterval((int) $intervalCount, $intervalUnit);
    }

    #[When('/^I set its subscriber discount to (\d+) percent$/')]
    public function iSetItsSubscriberDiscountTo(string $percentage): void
    {
        $this->createPage->setDiscount((int) $percentage);
    }

    #[When('I add it')]
    #[When('I try to add it')]
    public function iAddIt(): void
    {
        $this->createPage->create();
    }

    #[When('/^I want to edit the "([^"]+)" subscription plan of the ("[^"]+" variant)$/')]
    public function iWantToEditTheSubscriptionPlanOf(string $code, ProductVariantInterface $variant): void
    {
        $this->openVariant($variant);
        $this->subscriptionPlansPage->editPlan($code);
    }

    #[When('I disable it')]
    public function iDisableIt(): void
    {
        $this->updatePage->disable();
    }

    #[When('I save the plan')]
    public function iSaveThePlan(): void
    {
        $this->updatePage->saveChanges();
    }

    #[When('/^I delete the "([^"]+)" subscription plan of the ("[^"]+" variant)$/')]
    public function iDeleteTheSubscriptionPlanOf(string $code, ProductVariantInterface $variant): void
    {
        $this->iWantToEditTheSubscriptionPlanOf($code, $variant);
        $this->updatePage->delete();
    }

    #[Then('/^the ("[^"]+" variant) should offer the "([^"]+)" subscription plan renewing every (\d+) (day|week|month|year)s? with a (\d+)% discount$/')]
    public function theVariantShouldOfferTheSubscriptionPlan(
        ProductVariantInterface $variant,
        string $code,
        string $intervalCount,
        string $intervalUnit,
        string $discountPercentage,
    ): void {
        $this->openVariant($variant);

        Assert::true($this->subscriptionPlansPage->hasPlan($code), \sprintf('The variant does not list the "%s" plan.', $code));
        Assert::startsWith($this->subscriptionPlansPage->getPlanInterval($code), \sprintf('%s %s', $intervalCount, $intervalUnit));
        Assert::same($this->subscriptionPlansPage->getPlanDiscount($code), \sprintf('%s %%', $discountPercentage));
    }

    #[Then('/^the "([^"]+)" subscription plan of the ("[^"]+" variant) should be disabled$/')]
    public function theSubscriptionPlanOfShouldBeDisabled(string $code, ProductVariantInterface $variant): void
    {
        $this->openVariant($variant);

        Assert::false($this->subscriptionPlansPage->isPlanEnabled($code));
    }

    #[Then('/^the ("[^"]+" variant) should offer no subscription plans$/')]
    public function theVariantShouldOfferNoSubscriptionPlans(ProductVariantInterface $variant): void
    {
        $this->openVariant($variant);

        Assert::true($this->subscriptionPlansPage->hasNoPlans());
    }

    #[Then('I should be notified that a plan with this code already exists')]
    public function iShouldBeNotifiedThatAPlanWithThisCodeAlreadyExists(): void
    {
        Assert::same($this->createPage->getValidationMessage('code'), 'A subscription plan with this code already exists.');
    }

    #[Then('I should be notified that the interval must be at least 1')]
    public function iShouldBeNotifiedThatTheIntervalMustBeAtLeastOne(): void
    {
        Assert::same($this->createPage->getValidationMessage('interval_count'), 'The interval must be at least 1.');
    }

    private function openVariant(ProductVariantInterface $variant): void
    {
        $product = $variant->getProduct();
        Assert::notNull($product);

        $this->subscriptionPlansPage->open(['productId' => $product->getId(), 'id' => $variant->getId()]);
    }
}
