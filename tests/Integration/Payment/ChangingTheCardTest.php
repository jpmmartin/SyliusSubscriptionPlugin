<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Integration\Payment;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use JpmMartin\SyliusSubscriptionPlugin\Payment\CardUpdateProviderRegistry;
use Sylius\Behat\Context\Setup\PaymentContext;
use Sylius\Behat\Service\SharedStorageInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Integration\Lifecycle\LifecycleTestCase;

/**
 * A monthly Coffee subscription paid with "Card on file". The test store's gateway integration lets
 * customers change the card of "Card on file" only.
 */
final class ChangingTheCardTest extends LifecycleTestCase
{
    public function testAnIntegrationThatSupportsTheSubscriptionsMethodGivesThePageToChangeTheCard(): void
    {
        $subscription = $this->coffeeSubscription();

        self::assertSame(
            \sprintf('https://gateway.example.com/cards/update?subscription=%d', (int) $subscription->getId()),
            $this->registry()->urlFor($subscription),
        );
    }

    public function testNothingIsOfferedWhenNoIntegrationSupportsTheSubscriptionsMethod(): void
    {
        /** @var PaymentContext $payment */
        $payment = self::getContainer()->get('sylius.behat.context.setup.payment');
        $payment->storeAllowsPaying('Other card');
        /** @var SharedStorageInterface $sharedStorage */
        $sharedStorage = self::getContainer()->get('sylius.behat.shared_storage');
        $otherCard = $sharedStorage->get('payment_method');
        self::assertInstanceOf(PaymentMethodInterface::class, $otherCard);
        $subscription = $this->coffeeSubscription();
        $subscription->setPaymentMethod($otherCard);
        $this->entityManager()->flush();

        self::assertNull($this->registry()->urlFor($subscription));
    }

    private function coffeeSubscription(): SubscriptionInterface
    {
        $this->itIsNow('2027-01-01 09:00');
        $this->pay($this->placedCoffeeOrder());

        return $this->subscriptionsByPlan()['COFFEE_MONTHLY'];
    }

    private function registry(): CardUpdateProviderRegistry
    {
        $registry = self::getContainer()->get('jpm_martin_sylius_subscription.payment.card_update_provider_registry');
        self::assertInstanceOf(CardUpdateProviderRegistry::class, $registry);

        return $registry;
    }
}
