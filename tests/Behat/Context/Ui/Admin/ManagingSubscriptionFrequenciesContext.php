<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Behat\Context\Ui\Admin;

use Behat\Behat\Context\Context;
use Behat\Step\Then;
use Behat\Step\When;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionFrequencyInterface;
use Sylius\Behat\Page\Admin\Crud\IndexPageInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Behat\Page\Admin\SubscriptionFrequency\CreatePage;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Behat\Page\Admin\SubscriptionFrequency\UpdatePage;
use Webmozart\Assert\Assert;

final class ManagingSubscriptionFrequenciesContext implements Context
{
    /** @param RepositoryInterface<SubscriptionFrequencyInterface> $frequencyRepository */
    public function __construct(
        private readonly IndexPageInterface $indexPage,
        private readonly CreatePage $createPage,
        private readonly UpdatePage $updatePage,
        private readonly RepositoryInterface $frequencyRepository,
    ) {
    }

    #[When('I want to create a new subscription frequency')]
    public function iWantToCreateANewSubscriptionFrequency(): void
    {
        $this->createPage->open();
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

    #[When('/^I make it available in channel "([^"]+)"$/')]
    public function iMakeItAvailableInChannel(string $channelName): void
    {
        $this->createPage->makeAvailableIn($channelName);
    }

    #[When('I add it')]
    public function iAddIt(): void
    {
        $this->createPage->create();
    }

    #[When('/^I want to modify the "([^"]+)" subscription frequency$/')]
    public function iWantToModifyTheSubscriptionFrequency(string $code): void
    {
        $this->updatePage->open(['id' => $this->frequency($code)->getId()]);
    }

    #[When('I disable it')]
    public function iDisableIt(): void
    {
        $this->updatePage->disable();
    }

    #[When('I save my changes')]
    public function iSaveMyChanges(): void
    {
        $this->updatePage->saveChanges();
    }

    #[When('/^I delete the "([^"]+)" subscription frequency$/')]
    public function iDeleteTheSubscriptionFrequency(string $code): void
    {
        $this->indexPage->open();
        $this->indexPage->deleteResourceOnPage(['code' => $code]);
    }

    #[Then('/^the subscription frequency "([^"]+)" should appear in the list renewing "([^"]+)" with a (\d+)% discount$/')]
    public function theSubscriptionFrequencyShouldAppearInTheList(string $code, string $interval, string $discount): void
    {
        $this->indexPage->open();

        Assert::true($this->indexPage->isSingleResourceOnPage(['code' => $code, 'interval' => $interval, 'discountPercentage' => $discount . ' %']));
    }

    #[Then('/^the subscription frequency "([^"]+)" should still appear in the list$/')]
    public function theSubscriptionFrequencyShouldStillAppearInTheList(string $code): void
    {
        $this->indexPage->open();

        Assert::true($this->indexPage->isSingleResourceOnPage(['code' => $code]));
    }

    #[Then('/^the "([^"]+)" subscription frequency should be disabled$/')]
    public function theSubscriptionFrequencyShouldBeDisabled(string $code): void
    {
        $this->updatePage->open(['id' => $this->frequency($code)->getId()]);

        Assert::false($this->updatePage->isEnabled());
    }

    private function frequency(string $code): SubscriptionFrequencyInterface
    {
        $frequency = $this->frequencyRepository->findOneBy(['code' => $code]);
        Assert::isInstanceOf($frequency, SubscriptionFrequencyInterface::class);

        return $frequency;
    }
}
