<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Integration\Order;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionCycleInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Integration\Lifecycle\LifecycleTestCase;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Payment\ScriptedGateway;

/**
 * Sylius's sylius:cancel-unpaid-orders, with its default expiration of five days. Like Sylius, it
 * reads the server's time, the one an order's checkout completion date is set with, so the orders are
 * dated back rather than the clock moved on.
 */
final class CancellingUnpaidOrdersTest extends LifecycleTestCase
{
    public function testARenewalOrderWhoseCycleAwaitsItsLastRetryIsLeftToTheRetryPolicy(): void
    {
        $this->itIsNow('2027-01-01 09:00');
        $this->pay($this->placedCoffeeOrder());
        foreach (['2027-02-01 09:00', '2027-02-02 09:00', '2027-02-04 09:00'] as $dateTime) {
            $this->scriptedGateway()->willAnswer(ScriptedGateway::DECLINE, 'Insufficient funds.');
            $this->itIsNow($dateTime);
            $this->runTheCycleCommand();
        }
        $renewal = $this->cycle(2)->getOrder();
        self::assertNotNull($renewal);
        $this->completedDaysAgo($renewal, 6);

        $this->runTheUnpaidOrdersCommand();

        $cycle = $this->cycle(2);
        self::assertSame(OrderInterface::STATE_NEW, $cycle->getOrder()?->getState());
        self::assertSame(SubscriptionCycleInterface::STATE_AWAITING_PAYMENT, $cycle->getState());
        self::assertSame('2027-02-08 09:00', $cycle->getNextAttemptAt()?->format('Y-m-d H:i'));

        $this->itIsNow('2027-02-08 09:00');
        $this->runTheCycleCommand();
        self::assertSame(SubscriptionCycleInterface::STATE_PAID, $this->cycle(2)->getState(), 'The day-7 retry was made and approved.');
    }

    public function testAnUnpaidInitialOrderStillExpiresAndTakesItsPendingSubscriptionsWithIt(): void
    {
        $order = $this->placedCoffeeOrder();
        $this->completedDaysAgo($order, 6);

        $this->runTheUnpaidOrdersCommand();

        self::assertSame(OrderInterface::STATE_CANCELLED, $this->reloaded($order)->getState());
        self::assertSame(SubscriptionInterface::STATE_CANCELLED, $this->subscription()->getState());
    }

    public function testAnyOtherUnpaidOrderStillExpiresWhenItsTimeHasCome(): void
    {
        $expired = $this->cart();
        $this->addLine($expired, $this->coffee, 1, null);
        $this->place($expired);
        $this->completedDaysAgo($expired, 6);
        $recent = $this->cart();
        $this->addLine($recent, $this->coffee, 1, null);
        $this->place($recent);
        $this->completedDaysAgo($recent, 4);

        $this->runTheUnpaidOrdersCommand();

        self::assertSame(OrderInterface::STATE_CANCELLED, $this->reloaded($expired)->getState());
        self::assertSame(OrderInterface::STATE_NEW, $this->reloaded($recent)->getState());
    }

    private function runTheUnpaidOrdersCommand(): void
    {
        $this->entityManager()->clear();

        $application = new Application(self::$kernel ?? self::bootKernel());
        $tester = new CommandTester($application->find('sylius:cancel-unpaid-orders'));
        $tester->execute([]);
        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
    }

    private function completedDaysAgo(OrderInterface $order, int $days): void
    {
        $order = $this->reloaded($order);
        $order->setCheckoutCompletedAt(new \DateTime(\sprintf('-%d days', $days)));
        $this->entityManager()->flush();
    }

    private function reloaded(OrderInterface $order): OrderInterface
    {
        $reloaded = $this->entityManager()->find($order::class, $order->getId());
        self::assertInstanceOf(OrderInterface::class, $reloaded);
        $this->entityManager()->refresh($reloaded);

        return $reloaded;
    }

    private function subscription(): SubscriptionInterface
    {
        return $this->subscriptionsByPlan()['COFFEE_MONTHLY'];
    }

    private function cycle(int $number): SubscriptionCycleInterface
    {
        return $this->storedCycles($this->subscription())[$number - 1];
    }
}
