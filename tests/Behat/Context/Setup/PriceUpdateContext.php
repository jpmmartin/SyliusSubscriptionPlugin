<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Behat\Context\Setup;

use Behat\Behat\Context\Context;
use Behat\Hook\AfterScenario;
use Behat\Step\Given;
use JpmMartin\SyliusSubscriptionPlugin\Command\UpdateSubscriptionPrices;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionPlanInterface;
use JpmMartin\SyliusSubscriptionPlugin\Pricing\PriceUpdateTarget;
use JpmMartin\SyliusSubscriptionPlugin\Pricing\SubscriptionPriceUpdaterInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Webmozart\Assert\Assert;

final class PriceUpdateContext implements Context
{
    /** @param RepositoryInterface<SubscriptionPlanInterface> $planRepository */
    public function __construct(
        private readonly RepositoryInterface $planRepository,
        private readonly SubscriptionPriceUpdaterInterface $priceUpdater,
        private readonly MessageBusInterface $commandBus,
        private readonly string $acceptanceSwitchFile,
    ) {
    }

    #[Given('the store requires customers to accept a price increase')]
    public function theStoreRequiresCustomersToAcceptAPriceIncrease(): void
    {
        file_put_contents($this->acceptanceSwitchFile, 'required');
    }

    /** As an administrator's confirmed update does it: one message per subscription. */
    #[Given('/^the prices of the subscriptions on the "([^"]+)" plan have been updated$/')]
    public function thePricesOfTheSubscriptionsOnThePlanHaveBeenUpdated(string $planCode): void
    {
        $plan = $this->planRepository->findOneBy(['code' => $planCode]);
        Assert::isInstanceOf($plan, SubscriptionPlanInterface::class);
        $target = new PriceUpdateTarget(PriceUpdateTarget::PLAN, (int) $plan->getId());

        foreach ($this->priceUpdater->preview($target)->subscriptionIds as $subscriptionId) {
            $this->commandBus->dispatch(new UpdateSubscriptionPrices($subscriptionId, $target->type, $target->id));
        }
    }

    #[AfterScenario]
    public function forgetTheAcceptanceSwitch(): void
    {
        if (is_file($this->acceptanceSwitchFile)) {
            unlink($this->acceptanceSwitchFile);
        }
    }
}
