<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Integration\Management;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionCycleInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use JpmMartin\SyliusSubscriptionPlugin\Event\SubscriptionAddressChanged;
use JpmMartin\SyliusSubscriptionPlugin\Management\ShippingMethodOffer;
use JpmMartin\SyliusSubscriptionPlugin\Management\SubscriptionAddressChangerInterface;
use JpmMartin\SyliusSubscriptionPlugin\StateMachine\SubscriptionTransitions;
use Sylius\Behat\Context\Setup\ShippingContext;
use Sylius\Behat\Service\SharedStorageInterface;
use Sylius\Component\Addressing\Factory\ZoneFactoryInterface;
use Sylius\Component\Addressing\Model\CountryInterface;
use Sylius\Component\Addressing\Model\ZoneInterface;
use Sylius\Component\Core\Model\AddressInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\ShipmentInterface;
use Sylius\Component\Core\Model\ShippingMethodInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Event\EventCollector;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Integration\Lifecycle\LifecycleTestCase;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Payment\ScriptedGateway;

/**
 * A monthly Coffee subscription activated on 1 January, shipped "Free" within the United States. The
 * store also ships to France, in its "Europe" zone, with "Europe Express" for $15.00, and knows Germany,
 * where nothing ships.
 */
final class ChangingASubscriptionAddressTest extends LifecycleTestCase
{
    private ShippingMethodInterface $europeExpress;

    protected function setUp(): void
    {
        parent::setUp();

        $container = self::getContainer();
        /** @var FactoryInterface<CountryInterface> $countries */
        $countries = $container->get('sylius.factory.country');
        foreach (['FR', 'DE'] as $code) {
            $country = $countries->createNew();
            $country->setCode($code);
            $this->entityManager()->persist($country);
        }
        /** @var ZoneFactoryInterface<ZoneInterface> $zones */
        $zones = $container->get('sylius.factory.zone');
        $europe = $zones->createWithMembers(['FR']);
        $europe->setType(ZoneInterface::TYPE_COUNTRY);
        $europe->setCode('EU');
        $europe->setName('Europe');
        $europe->setScope('all');
        $this->entityManager()->persist($europe);
        $this->entityManager()->flush();
        /** @var ShippingContext $shipping */
        $shipping = $container->get('sylius.behat.context.setup.shipping');
        $shipping->storeHasShippingMethodWithFeeAndZone('Europe Express', 1500, $europe);
        /** @var SharedStorageInterface $sharedStorage */
        $sharedStorage = $container->get('sylius.behat.shared_storage');
        $europeExpress = $sharedStorage->get('shipping_method');
        self::assertInstanceOf(ShippingMethodInterface::class, $europeExpress);
        $this->europeExpress = $europeExpress;

        $this->itIsNow('2027-01-01 09:00');
        $this->pay($this->placedCoffeeOrder());
        $this->collector()->clear();
    }

    public function testAnAddressOfTheBookTheShippingMethodReachesIsUsedByTheNextRenewalWithTheSameMethod(): void
    {
        $bookAddress = $this->address('Elm Street 13', 'Springwood', 'US');
        $this->customer->addAddress($bookAddress);
        $this->entityManager()->flush();

        $this->itIsNow('2027-01-20 09:00');
        $subscription = $this->subscription();
        self::assertSame(['Free'], $this->methodNames($this->changer()->shippingMethodsFor($subscription, $bookAddress)));
        $this->changer()->change($subscription, $bookAddress);
        $this->entityManager()->flush();

        $this->itIsNow('2027-02-01 09:00');
        $this->runTheCycleCommand();

        $order = $this->renewalOrder(2);
        self::assertSame('Elm Street 13', $order->getShippingAddress()?->getStreet());
        self::assertSame('Elm Street 13', $order->getBillingAddress()?->getStreet(), 'Billed where it is shipped when no other billing address is given.');
        self::assertSame('Free', $this->shipmentOf($order)->getMethod()?->getName());
        self::assertEquals([new SubscriptionAddressChanged((int) $subscription->getId(), false)], $this->collector()->events(SubscriptionAddressChanged::class));
    }

    public function testMovingToAnotherZoneAsksForAShippingMethodThatReachesItWithItsCost(): void
    {
        $paris = $this->address('Rue de Rivoli 1', 'Paris', 'FR');

        $this->itIsNow('2027-01-20 09:00');
        $subscription = $this->subscription();
        $offers = $this->changer()->shippingMethodsFor($subscription, $paris);
        self::assertSame(['Europe Express'], $this->methodNames($offers));
        self::assertSame(1500, $offers[0]->cost);

        try {
            $this->changer()->change($subscription, $paris);
            self::fail('The current method, which does not reach Paris, was kept.');
        } catch (\InvalidArgumentException) {
        }
        $this->changer()->change($subscription, $paris, null, $this->europeExpress);
        $this->entityManager()->flush();

        $this->itIsNow('2027-02-01 09:00');
        $this->runTheCycleCommand();

        $order = $this->renewalOrder(2);
        self::assertSame('FR', $order->getShippingAddress()?->getCountryCode());
        self::assertSame('Europe Express', $this->shipmentOf($order)->getMethod()?->getName());
        self::assertEquals([new SubscriptionAddressChanged((int) $subscription->getId(), true)], $this->collector()->events(SubscriptionAddressChanged::class));
    }

    public function testAnAddressNoShippingMethodReachesIsRefusedAndNothingChanges(): void
    {
        $berlin = $this->address('Unter den Linden 1', 'Berlin', 'DE');
        $subscription = $this->subscription();

        self::assertSame([], $this->changer()->shippingMethodsFor($subscription, $berlin));

        try {
            $this->changer()->change($subscription, $berlin, null, $this->europeExpress);
            self::fail('An address nothing reaches was accepted.');
        } catch (\InvalidArgumentException) {
        }
        $this->entityManager()->flush();

        $subscription = $this->subscription();
        self::assertNull($subscription->getShippingAddress());
        self::assertNull($subscription->getBillingAddress());
        self::assertSame('Free', $subscription->getShippingMethod()?->getName());
        self::assertSame([], $this->collector()->events());
    }

    public function testARenewalOrderAlreadyPlacedKeepsItsAddressAndTheNextOneTakesTheNewOne(): void
    {
        $this->scriptedGateway()->willAnswer(ScriptedGateway::DECLINE, 'Insufficient funds.');
        $this->itIsNow('2027-02-01 09:00');
        $this->runTheCycleCommand();
        self::assertSame(SubscriptionCycleInterface::STATE_AWAITING_PAYMENT, $this->cycle(2)->getState());

        $this->itIsNow('2027-02-01 12:00');
        $this->changer()->change($this->subscription(), $this->address('Elm Street 13', 'Springwood', 'US'));
        $this->entityManager()->flush();

        $this->itIsNow('2027-02-02 09:00');
        $this->runTheCycleCommand();
        self::assertSame(SubscriptionCycleInterface::STATE_PAID, $this->cycle(2)->getState());
        self::assertSame('Frost Alley', $this->renewalOrder(2)->getShippingAddress()?->getStreet(), 'The order placed on 1 February keeps its address.');

        $this->itIsNow('2027-03-01 09:00');
        $this->runTheCycleCommand();
        self::assertSame('Elm Street 13', $this->renewalOrder(3)->getShippingAddress()?->getStreet());
    }

    public function testEditingTheBookAddressAfterwardsLeavesTheSubscriptionsCopyAlone(): void
    {
        $bookAddress = $this->address('Elm Street 13', 'Springwood', 'US');
        $this->customer->addAddress($bookAddress);
        $this->entityManager()->flush();
        $this->changer()->change($this->subscription(), $bookAddress);
        $this->entityManager()->flush();

        $bookAddress->setStreet('Somewhere Else 99');
        $this->entityManager()->flush();

        $subscription = $this->subscription();
        self::assertSame('Elm Street 13', $subscription->getShippingAddress()?->getStreet());
        self::assertNotSame($bookAddress->getId(), $subscription->getShippingAddress()?->getId());
    }

    public function testFindingTheShippingMethodsAndChangingTheAddressStoreNoOrder(): void
    {
        $ordersBefore = $this->countOrders();

        $paris = $this->address('Rue de Rivoli 1', 'Paris', 'FR');
        $this->changer()->shippingMethodsFor($this->subscription(), $paris);
        $this->changer()->change($this->subscription(), $paris, $this->address('Office Road 1', 'Springwood', 'US'), $this->europeExpress);
        $this->entityManager()->flush();

        self::assertSame($ordersBefore, $this->countOrders());
        self::assertSame('Office Road 1', $this->subscription()->getBillingAddress()?->getStreet());
    }

    public function testTheAddressesOfACancelledSubscriptionCannotBeChanged(): void
    {
        $this->apply($this->subscription(), SubscriptionTransitions::GRAPH, SubscriptionTransitions::TRANSITION_CANCEL);
        $this->entityManager()->flush();

        self::assertFalse($this->changer()->canChange($this->subscription()));
        $this->expectException(\InvalidArgumentException::class);
        $this->changer()->change($this->subscription(), $this->address('Elm Street 13', 'Springwood', 'US'));
    }

    private function changer(): SubscriptionAddressChangerInterface
    {
        $changer = self::getContainer()->get(SubscriptionAddressChangerInterface::class);
        self::assertInstanceOf(SubscriptionAddressChangerInterface::class, $changer);

        return $changer;
    }

    private function collector(): EventCollector
    {
        $collector = self::getContainer()->get('jpm_martin_sylius_subscription.test.event_collector');
        self::assertInstanceOf(EventCollector::class, $collector);

        return $collector;
    }

    private function address(string $street, string $city, string $countryCode): AddressInterface
    {
        /** @var FactoryInterface<AddressInterface> $factory */
        $factory = self::getContainer()->get('sylius.factory.address');
        $address = $factory->createNew();
        $address->setFirstName('John');
        $address->setLastName('Doe');
        $address->setStreet($street);
        $address->setCity($city);
        $address->setPostcode('10001');
        $address->setCountryCode($countryCode);

        return $address;
    }

    /**
     * @param list<ShippingMethodOffer> $offers
     *
     * @return list<string>
     */
    private function methodNames(array $offers): array
    {
        return array_map(static fn (ShippingMethodOffer $offer): string => (string) $offer->method->getName(), $offers);
    }

    private function subscription(): SubscriptionInterface
    {
        return $this->subscriptionsByPlan()['COFFEE_MONTHLY'];
    }

    private function cycle(int $number): SubscriptionCycleInterface
    {
        foreach ($this->storedCycles($this->subscription()) as $cycle) {
            if ($number === $cycle->getNumber()) {
                return $cycle;
            }
        }

        self::fail(\sprintf('The subscription has no cycle %d.', $number));
    }

    private function renewalOrder(int $number): OrderInterface
    {
        $order = $this->cycle($number)->getOrder();
        self::assertInstanceOf(OrderInterface::class, $order);
        $this->entityManager()->refresh($order);

        return $order;
    }

    private function shipmentOf(OrderInterface $order): ShipmentInterface
    {
        $shipment = $order->getShipments()->first();
        self::assertInstanceOf(ShipmentInterface::class, $shipment);

        return $shipment;
    }

    private function countOrders(): int
    {
        return (int) $this->entityManager()->getConnection()->fetchOne('SELECT COUNT(*) FROM sylius_order');
    }
}
