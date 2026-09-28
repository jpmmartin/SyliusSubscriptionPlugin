<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Behat\Context\Domain;

use Behat\Behat\Context\Context;
use Behat\Step\Given;
use Behat\Step\Then;
use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use JpmMartin\SyliusSubscriptionPlugin\Repository\SubscriptionRepositoryInterface;
use Sylius\Bundle\PayumBundle\Model\GatewayConfigInterface;
use Sylius\Component\Core\Formatter\StringInflector;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Webmozart\Assert\Assert;

/** A free trial started from the shop: the gateway that keeps the card, and the subscription it started. */
final class FreeTrialContext implements Context
{
    /**
     * @param RepositoryInterface<PaymentMethodInterface> $paymentMethodRepository
     * @param SubscriptionRepositoryInterface<SubscriptionInterface> $subscriptionRepository
     */
    public function __construct(
        private readonly RepositoryInterface $paymentMethodRepository,
        private readonly SubscriptionRepositoryInterface $subscriptionRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /** The test store's gateway, which authorizes a payment of 0 and keeps the card; the store lists the method in trial_payment_methods. */
    #[Given('/^the "([^"]+)" payment method keeps cards through the test gateway$/')]
    public function thePaymentMethodKeepsCardsThroughTheTestGateway(string $paymentMethodName): void
    {
        $paymentMethod = $this->paymentMethodRepository->findOneBy(['code' => StringInflector::nameToCode($paymentMethodName)]);
        Assert::isInstanceOf($paymentMethod, PaymentMethodInterface::class);
        $gatewayConfig = $paymentMethod->getGatewayConfig();
        Assert::isInstanceOf($gatewayConfig, GatewayConfigInterface::class);

        $gatewayConfig->setFactoryName('scripted');
        $gatewayConfig->setUsePayum(false);
        $this->entityManager->flush();
    }

    #[Then('/^my subscription to "([^"]+)" should have started with a free trial of (\d+) days$/')]
    public function mySubscriptionShouldHaveStartedWithAFreeTrial(string $productName, int $days): void
    {
        $this->entityManager->clear();
        $subscriptions = $this->subscriptionRepository->findAll();
        Assert::count($subscriptions, 1);
        $subscription = $subscriptions[0];
        Assert::isInstanceOf($subscription, SubscriptionInterface::class);

        Assert::same($subscription->getItems()->first() ? $subscription->getItems()->first()->getProductVariant()?->getProduct()?->getName() : null, $productName);
        Assert::same($subscription->getState(), SubscriptionInterface::STATE_ACTIVE, 'Its payment of 0 was authorized, which activates it.');
        Assert::same($subscription->getTrialDays(), $days);
    }
}
