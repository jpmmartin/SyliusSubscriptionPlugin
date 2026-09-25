<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Behat\Context\Ui\Admin;

use Behat\Behat\Context\Context;
use Behat\Step\Then;
use Behat\Step\When;
use Sylius\Behat\NotificationType;
use Sylius\Behat\Service\NotificationCheckerInterface;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Behat\Page\Admin\Subscription\ChangeFrequencyPage;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Behat\Page\Admin\Subscription\IndexPage;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Behat\Page\Admin\Subscription\ShowPage;
use Webmozart\Assert\Assert;

final class ManagingSubscriptionsContext implements Context
{
    private const TRANSITIONS = ['pause' => 'pause', 'resume' => 'resume', 'suspend' => 'suspend', 'reactivate' => 'reactivate', 'cancel' => 'cancel'];

    public function __construct(
        private readonly IndexPage $indexPage,
        private readonly ShowPage $showPage,
        private readonly ChangeFrequencyPage $changeFrequencyPage,
        private readonly NotificationCheckerInterface $notificationChecker,
    ) {
    }

    #[When('I browse subscriptions')]
    public function iBrowseSubscriptions(): void
    {
        $this->indexPage->open();
    }

    #[When('/^I filter the subscriptions by (state|customer|variant) "([^"]+)"$/')]
    public function iFilterTheSubscriptionsBy(string $filter, string $value): void
    {
        $this->indexPage->open();
        match ($filter) {
            'state' => $this->indexPage->filterByState($value),
            'customer' => $this->indexPage->filterByCustomer($value),
            'variant' => $this->indexPage->filterByVariant($value),
        };
        $this->indexPage->filter();
    }

    #[When('/^I filter the subscriptions renewing next between "([^"]+)" and "([^"]+)"$/')]
    public function iFilterTheSubscriptionsRenewingNextBetween(string $from, string $to): void
    {
        $this->indexPage->open();
        $this->indexPage->filterByNextRenewal($from, $to);
        $this->indexPage->filter();
    }

    #[When('/^I view the subscription of "([^"]+)"$/')]
    public function iViewTheSubscriptionOf(string $email): void
    {
        $this->indexPage->open();
        $this->indexPage->showSubscriptionOf($email);
    }

    #[When('/^I (pause|resume|suspend|reactivate|cancel) it$/')]
    public function iApplyToIt(string $transition): void
    {
        $this->showPage->apply(self::TRANSITIONS[$transition]);
    }

    #[When('I skip its next renewal')]
    public function iSkipItsNextRenewal(): void
    {
        $this->showPage->skipRenewal();
    }

    #[When('/^I change its frequency to "([^"]+)"$/')]
    public function iChangeItsFrequencyTo(string $frequency): void
    {
        $this->showPage->changeFrequency();
        $this->changeFrequencyPage->chooseFrequency($frequency);
        $this->changeFrequencyPage->confirm();
    }

    #[When('/^I retry its renewal #(\d+)$/')]
    public function iRetryItsRenewal(int $number): void
    {
        $this->showPage->retryRenewal($number);
    }

    #[Then('/^I should see (\d+) subscriptions? in the list$/')]
    public function iShouldSeeSubscriptionsInTheList(int $count): void
    {
        Assert::same($this->indexPage->countItems(), $count);
    }

    #[Then('/^I should see the subscription of "([^"]+)" to "([^"]+)" renewing next on "([^"]+)"$/')]
    public function iShouldSeeTheSubscriptionOf(string $email, string $productName, string $date): void
    {
        Assert::true($this->indexPage->isSingleResourceOnPage([
            'customer' => $email,
            'products' => $productName,
            'nextRenewal' => $date,
        ]));
    }

    #[Then('/^it should be "([^"]+)"$/')]
    public function itShouldBe(string $state): void
    {
        Assert::same($this->showPage->getDetail('state'), $state);
    }

    #[Then('/^it should renew "([^"]+)"$/')]
    public function itShouldRenew(string $frequency): void
    {
        Assert::same($this->showPage->getDetail('frequency'), $frequency);
    }

    #[Then('/^its "([^"]+)" item should be (\d+) at "([^"]+)" on the "([^"]+)" plan$/')]
    public function itsItemShouldBe(string $productName, int $quantity, string $unitPrice, string $planName): void
    {
        Assert::same($this->showPage->getItem($productName, 'quantity'), (string) $quantity);
        Assert::same($this->showPage->getItem($productName, 'unit-price'), $unitPrice);
        Assert::same($this->showPage->getItem($productName, 'plan'), $planName);
    }

    #[Then('/^it should have (\d+) failed renewals? in a row$/')]
    public function itShouldHaveFailedRenewalsInARow(int $count): void
    {
        Assert::same($this->showPage->getDetail('consecutive-failed-cycles'), (string) $count);
    }

    #[Then('/^its renewal #(\d+) should show "([^"]+)" skipped because "([^"]+)"$/')]
    public function itsRenewalShouldShowSkipped(int $number, string $productName, string $reason): void
    {
        Assert::same($this->showPage->getSkippedItemsOfRenewal($number), [$productName => $reason]);
    }

    #[Then('/^I should not be able to retry its renewal #(\d+)$/')]
    public function iShouldNotBeAbleToRetryItsRenewal(int $number): void
    {
        Assert::false($this->showPage->canRetryRenewal($number));
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

    #[Then('/^its renewal #(\d+) should be "([^"]+)"$/')]
    public function itsRenewalShouldBe(int $number, string $state): void
    {
        Assert::same($this->showPage->getRenewalState($number), $state);
    }

    #[Then('/^I should see that the recurring charges were accepted in version "([^"]+)"$/')]
    public function iShouldSeeTheConsentVersion(string $version): void
    {
        Assert::same($this->showPage->getConsent('version'), $version);
        Assert::startsWith($this->showPage->getConsent('text'), 'I authorise the store to charge my payment method');
    }

    #[Then('/^its renewal #(\d+) should show an? "([^"]+)" charge on "([^"]+)"(?: because "([^"]+)")?(?: with the code "([^"]+)")?$/')]
    public function itsRenewalShouldShowACharge(int $number, string $outcome, string $attemptedAt, ?string $reason = null, ?string $code = null): void
    {
        $attempts = $this->showPage->getAttemptsOfRenewal($number);
        Assert::inArray(
            ['attempted_at' => $attemptedAt, 'outcome' => $outcome, 'reason' => $reason ?? '', 'code' => $code ?? ''],
            $attempts,
            \sprintf('The attempts shown are %s.', json_encode($attempts, \JSON_THROW_ON_ERROR)),
        );
    }

    #[Then('/^I should not be able to (?:suspend|reactivate|cancel) it(?:, (?:suspend|reactivate|cancel) it)* or (?:suspend|reactivate|cancel) it$/')]
    public function iShouldNotBeAbleToChangeItsState(): void
    {
        foreach (self::TRANSITIONS as $transition) {
            Assert::false($this->showPage->canApply($transition), \sprintf('"%s" is offered.', $transition));
        }
    }

    #[Then('/^I should be notified that it has been (paused|resumed|suspended|reactivated|cancelled)$/')]
    public function iShouldBeNotifiedThatItHasBeen(string $state): void
    {
        $this->notificationChecker->checkNotification(\sprintf('The subscription has been %s.', $state), NotificationType::success());
    }

    #[Then('I should be notified that its frequency has been changed')]
    public function iShouldBeNotifiedThatItsFrequencyHasBeenChanged(): void
    {
        $this->notificationChecker->checkNotification('The subscription frequency has been changed.', NotificationType::success());
    }

    #[Then('I should be notified that the renewal has been skipped')]
    public function iShouldBeNotifiedThatTheRenewalHasBeenSkipped(): void
    {
        $this->notificationChecker->checkNotification('The renewal has been skipped.', NotificationType::success());
    }

    #[Then('I should be notified that the renewal has been charged')]
    public function iShouldBeNotifiedThatTheRenewalHasBeenCharged(): void
    {
        $this->notificationChecker->checkNotification('The renewal has been charged.', NotificationType::success());
    }
}
