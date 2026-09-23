<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Behat\Context\Ui\Admin;

use Behat\Behat\Context\Context;
use Behat\Step\Then;
use Behat\Step\When;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Behat\Page\Admin\ProductVariant\SubscriptionPlansPage;
use Webmozart\Assert\Assert;

final class ManagingRepeatableVariantsContext implements Context
{
    public function __construct(private readonly SubscriptionPlansPage $subscriptionTab)
    {
    }

    #[When('/^I let customers repeat the ("[^"]+" variant)$/')]
    public function iLetCustomersRepeatTheVariant(ProductVariantInterface $variant): void
    {
        $this->openFor($variant);
        $this->subscriptionTab->markRepeatable();
        $this->subscriptionTab->saveChanges();
    }

    #[When('/^I stop letting customers repeat the ("[^"]+" variant)$/')]
    public function iStopLettingCustomersRepeatTheVariant(ProductVariantInterface $variant): void
    {
        $this->openFor($variant);
        $this->subscriptionTab->unmarkRepeatable();
        $this->subscriptionTab->saveChanges();
    }

    #[Then('/^the ("[^"]+" variant) should be repeatable$/')]
    public function theVariantShouldBeRepeatable(ProductVariantInterface $variant): void
    {
        $this->openFor($variant);

        Assert::true($this->subscriptionTab->isMarkedRepeatable());
    }

    #[Then('/^the ("[^"]+" variant) should not be repeatable$/')]
    public function theVariantShouldNotBeRepeatable(ProductVariantInterface $variant): void
    {
        $this->openFor($variant);

        Assert::false($this->subscriptionTab->isMarkedRepeatable());
    }

    private function openFor(ProductVariantInterface $variant): void
    {
        $this->subscriptionTab->open(['productId' => $variant->getProduct()?->getId(), 'id' => $variant->getId()]);
    }
}
