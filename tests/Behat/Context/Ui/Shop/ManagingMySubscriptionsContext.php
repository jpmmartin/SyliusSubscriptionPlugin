<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Behat\Context\Ui\Shop;

use Behat\Behat\Context\Context;
use Behat\Step\Then;
use Behat\Step\When;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use Sylius\Behat\NotificationType;
use Sylius\Behat\Service\NotificationCheckerInterface;
use Sylius\Behat\Service\SharedStorageInterface;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Behat\Page\Shop\Account\Subscription\ChangeAddressPage;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Behat\Page\Shop\Account\Subscription\ChangeFrequencyPage;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Behat\Page\Shop\Account\Subscription\IndexPage;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Behat\Page\Shop\Account\Subscription\ShowPage;
use Webmozart\Assert\Assert;

final class ManagingMySubscriptionsContext implements Context
{
    public function __construct(
        private readonly IndexPage $indexPage,
        private readonly ShowPage $showPage,
        private readonly ChangeFrequencyPage $changeFrequencyPage,
        private readonly ChangeAddressPage $changeAddressPage,
        private readonly NotificationCheckerInterface $notificationChecker,
        private readonly SharedStorageInterface $sharedStorage,
    ) {
    }

    #[When('I browse my subscriptions')]
    public function iBrowseMySubscriptions(): void
    {
        $this->indexPage->open();
    }

    #[When('/^I view my subscription to "([^"]+)"$/')]
    public function iViewMySubscriptionTo(string $productName): void
    {
        $this->indexPage->open();
        $this->indexPage->showSubscriptionTo($productName);
    }

    #[When('I try to view that subscription')]
    public function iTryToViewThatSubscription(): void
    {
        $subscription = $this->sharedStorage->get('subscription');
        Assert::isInstanceOf($subscription, SubscriptionInterface::class);

        $this->showPage->tryToOpen(['id' => $subscription->getId()]);
    }

    #[When('I cancel this subscription')]
    public function iCancelThisSubscription(): void
    {
        $this->showPage->cancel();
    }

    #[When('/^I (pause|resume) this subscription$/')]
    public function iApplyToThisSubscription(string $transition): void
    {
        $this->showPage->apply($transition);
    }

    #[When('/^I skip its renewal of "([^"]+)"$/')]
    public function iSkipItsRenewalOf(string $date): void
    {
        Assert::contains((string) $this->showPage->getSkipRenewalButton(), $date);
        $this->showPage->skipRenewal();
    }

    #[When('/^I change its shipping address to the "([^"]+)" address of my address book$/')]
    public function iChangeItsShippingAddressToTheAddressOfMyBook(string $street): void
    {
        $this->showPage->changeAddress();
        $this->changeAddressPage->chooseFromAddressBook($street);
        $this->changeAddressPage->confirm();
    }

    #[When('/^I change its shipping address to "([^"]+)", "([^"]+)", "([^"]+)", "([^"]+)" for "([^"]+)"$/')]
    public function iChangeItsShippingAddressTo(string $city, string $street, string $postcode, string $country, string $fullName): void
    {
        $this->showPage->changeAddress();
        $this->changeAddressPage->writeShippingAddress($fullName, $street, $postcode, $city, $country);
        $this->changeAddressPage->confirm();
    }

    #[When('/^I choose the "([^"]+)" shipping method$/')]
    public function iChooseTheShippingMethod(string $name): void
    {
        $this->changeAddressPage->chooseShippingMethod($name);
        $this->changeAddressPage->confirm();
    }

    #[When('I start changing its frequency')]
    public function iStartChangingItsFrequency(): void
    {
        $this->showPage->changeFrequency();
    }

    #[When('/^I change its frequency to "([^"]+)"$/')]
    public function iChangeItsFrequencyTo(string $frequency): void
    {
        $this->showPage->changeFrequency();
        $this->changeFrequencyPage->chooseFrequency($frequency);
        $this->changeFrequencyPage->confirm();
    }

    #[Then('/^I should see my subscription to "([^"]+)" renewing "([^"]+)"$/')]
    public function iShouldSeeMySubscriptionRenewing(string $productName, string $frequency): void
    {
        Assert::true($this->indexPage->hasSubscriptionTo($productName));
        Assert::same($this->indexPage->getColumnOf($productName, 'frequency'), $frequency);
    }

    #[Then('/^my subscription to "([^"]+)" should cost "([^"]+)" per renewal$/')]
    public function mySubscriptionShouldCost(string $productName, string $price): void
    {
        Assert::same($this->indexPage->getColumnOf($productName, 'price'), $price);
    }

    #[Then('/^my subscription to "([^"]+)" should be "([^"]+)"$/')]
    public function mySubscriptionShouldBe(string $productName, string $state): void
    {
        Assert::same($this->indexPage->getColumnOf($productName, 'state'), $state);
    }

    #[Then('/^my subscription to "([^"]+)" should next renew on "([^"]+)"$/')]
    public function mySubscriptionShouldNextRenewOn(string $productName, string $date): void
    {
        Assert::same($this->indexPage->getColumnOf($productName, 'next-renewal'), $date);
    }

    #[Then('/^this subscription should be "([^"]+)"$/')]
    public function thisSubscriptionShouldBe(string $state): void
    {
        Assert::same($this->showPage->getDetail('state'), $state);
    }

    #[Then('/^this subscription should renew "([^"]+)"$/')]
    public function thisSubscriptionShouldRenew(string $frequency): void
    {
        Assert::same($this->showPage->getDetail('frequency'), $frequency);
    }

    #[Then('/^this subscription should have (\d+) "([^"]+)" at "([^"]+)"$/')]
    public function thisSubscriptionShouldHave(int $quantity, string $productName, string $unitPrice): void
    {
        Assert::same($this->showPage->getItem($productName, 'quantity'), (string) $quantity);
        Assert::same($this->showPage->getItem($productName, 'unit-price'), $unitPrice);
    }

    #[Then('/^this subscription should have (\d+) "([^"]+)" in "([^"]+)" at "([^"]+)"$/')]
    public function thisSubscriptionShouldHaveTheVariant(int $quantity, string $productName, string $variant, string $unitPrice): void
    {
        $this->thisSubscriptionShouldHave($quantity, $productName, $unitPrice);
        Assert::same($this->showPage->getItem($productName, 'variant'), $variant);
    }

    #[Then('/^my subscription to "([^"]+)" should list (\d+) "([^"]+)" in "([^"]+)" at "([^"]+)"$/')]
    public function mySubscriptionShouldList(string $subscriptionProduct, int $quantity, string $itemProduct, string $variant, string $unitPrice): void
    {
        Assert::same($this->indexPage->getItemOf($subscriptionProduct, $itemProduct, 'quantity'), (string) $quantity);
        Assert::same($this->indexPage->getItemOf($subscriptionProduct, $itemProduct, 'unit-price'), $unitPrice);
        Assert::same($this->indexPage->getItemOf($subscriptionProduct, $itemProduct, 'variant'), $variant);
    }

    #[Then('/^its renewal #(\d+) should have skipped "([^"]+)" because "([^"]+)"$/')]
    public function itsRenewalShouldHaveSkipped(int $number, string $productName, string $reason): void
    {
        Assert::same($this->showPage->getSkippedItemsOfRenewal($number), [$productName => $reason]);
    }

    #[Then('/^it should cost "([^"]+)" per renewal$/')]
    public function itShouldCost(string $price): void
    {
        Assert::same($this->showPage->getDetail('price'), $price);
    }

    #[Then('/^it should next renew on "([^"]+)"$/')]
    public function itShouldNextRenewOn(string $date): void
    {
        Assert::same($this->showPage->getDetail('next-renewal'), $date);
    }

    #[Then('/^this subscription should have (\d+) renewals$/')]
    public function thisSubscriptionShouldHaveRenewals(int $count): void
    {
        Assert::same($this->showPage->countRenewals(), $count);
    }

    #[Then('/^its renewal #(\d+) should be "([^"]+)"(?: on "([^"]+)")?$/')]
    public function itsRenewalShouldBe(int $number, string $state, ?string $date = null): void
    {
        Assert::same($this->showPage->getRenewal($number, 'state'), $state);
        if (null !== $date) {
            Assert::same($this->showPage->getRenewal($number, 'date'), $date);
        }
    }

    #[Then('I should not be able to cancel it again')]
    public function iShouldNotBeAbleToCancelItAgain(): void
    {
        Assert::false($this->showPage->canBeCancelled());
        Assert::false($this->showPage->canChangeFrequency());
    }

    #[Then('/^I should only be offered the "([^"]+)" frequency$/')]
    public function iShouldOnlyBeOfferedTheFrequency(string $frequency): void
    {
        Assert::same($this->changeFrequencyPage->getOfferedFrequencies(), [$frequency]);
    }

    #[Then('/^I should be notified that the subscription has been (cancelled|paused|resumed)$/')]
    public function iShouldBeNotifiedThatTheSubscriptionHasBeen(string $state): void
    {
        $this->notificationChecker->checkNotification(\sprintf('The subscription has been %s.', $state), NotificationType::success());
    }

    #[Then('/^I should be asked to choose the "([^"]+)" shipping method for "([^"]+)"$/')]
    public function iShouldBeAskedToChooseTheShippingMethod(string $name, string $cost): void
    {
        Assert::same($this->changeAddressPage->getOfferedShippingMethods(), [\sprintf('%s: %s', $name, $cost)]);
    }

    #[Then("I should be notified that the subscription's addresses have been changed")]
    public function iShouldBeNotifiedThatTheAddressesHaveBeenChanged(): void
    {
        $this->notificationChecker->checkNotification("The subscription's addresses have been changed.", NotificationType::success());
    }

    #[Then('/^this subscription should be shipped to "([^"]+)"$/')]
    public function thisSubscriptionShouldBeShippedTo(string $street): void
    {
        Assert::contains($this->showPage->getDetail('shipping-address'), $street);
    }

    #[Then('I should be notified that the renewal has been skipped')]
    public function iShouldBeNotifiedThatTheRenewalHasBeenSkipped(): void
    {
        $this->notificationChecker->checkNotification('The renewal has been skipped.', NotificationType::success());
    }

    #[Then('I should not be able to pause or resume it, nor skip its next renewal')]
    public function iShouldNotBeAbleToPauseResumeOrSkip(): void
    {
        Assert::false($this->showPage->canApply('pause'), 'Pausing is offered.');
        Assert::false($this->showPage->canApply('resume'), 'Resuming is offered.');
        Assert::null($this->showPage->getSkipRenewalButton(), 'Skipping is offered.');
    }

    #[Then('I should be notified that the subscription frequency has been changed')]
    public function iShouldBeNotifiedThatTheFrequencyHasBeenChanged(): void
    {
        $this->notificationChecker->checkNotification('The subscription frequency has been changed.', NotificationType::success());
    }

    #[Then('I should be told that it does not exist')]
    public function iShouldBeToldThatItDoesNotExist(): void
    {
        Assert::same($this->showPage->getStatusCode(), 404);
    }
}
