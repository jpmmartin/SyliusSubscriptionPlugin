<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Behat\Context\Ui\Shop;

use Behat\Behat\Context\Context;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use Sylius\Behat\NotificationType;
use Sylius\Behat\Service\NotificationCheckerInterface;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Behat\Page\Shop\Cart\RepeatCartPage;
use Webmozart\Assert\Assert;

final class RepeatingACartContext implements Context
{
    public function __construct(
        private readonly RepeatCartPage $repeatCartPage,
        private readonly NotificationCheckerInterface $notificationChecker,
    ) {
    }

    #[Given('/^I chose to repeat my cart "([^"]+)"$/')]
    #[When('/^I choose to repeat my cart "([^"]+)"$/')]
    public function iChooseToRepeatMyCart(string $label): void
    {
        $this->repeatCartPage->open();
        $this->repeatCartPage->repeatWith($label);
    }

    #[When('I choose not to repeat my cart')]
    public function iChooseNotToRepeatMyCart(): void
    {
        $this->repeatCartPage->open();
        $this->repeatCartPage->stopRepeating();
    }

    #[Then('I should be notified that my cart will be repeated')]
    public function iShouldBeNotifiedThatMyCartWillBeRepeated(): void
    {
        $this->notificationChecker->checkNotification('Your cart will be repeated.', NotificationType::success());
    }

    #[Then('I should be notified that my cart will no longer be repeated')]
    public function iShouldBeNotifiedThatMyCartWillNoLongerBeRepeated(): void
    {
        $this->notificationChecker->checkNotification('Your cart will no longer be repeated.', NotificationType::success());
    }

    #[Then('/^I should be offered to repeat my cart "([^"]+)" or "([^"]+)"$/')]
    public function iShouldBeOfferedToRepeatMyCart(string ...$labels): void
    {
        Assert::same($this->repeatCartPage->getOptions(), ["Don't repeat", ...$labels]);
    }

    #[Then('I should not be offered to repeat my cart')]
    public function iShouldNotBeOfferedToRepeatMyCart(): void
    {
        Assert::false($this->repeatCartPage->isRepeatingOffered());
    }

    #[Then('/^the "([^"]+)" item should be repeated "([^"]+)"$/')]
    public function theItemShouldBeRepeated(string $productName, string $repetition): void
    {
        Assert::same($this->repeatCartPage->getRepetitionOf($productName), 'Repeated: ' . $repetition);
    }

    #[Then('/^the "([^"]+)" item should be bought once$/')]
    public function theItemShouldBeBoughtOnce(string $productName): void
    {
        Assert::true($this->repeatCartPage->isBoughtOnce($productName));
    }

    #[Then('/^the "([^"]+)" item should not be repeated$/')]
    public function theItemShouldNotBeRepeated(string $productName): void
    {
        Assert::null($this->repeatCartPage->getRepetitionOf($productName));
    }
}
