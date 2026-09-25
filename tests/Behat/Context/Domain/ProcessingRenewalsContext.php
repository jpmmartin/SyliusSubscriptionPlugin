<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Behat\Context\Domain;

use Behat\Behat\Context\Context;
use Behat\Step\Given;
use Behat\Step\Then;
use Behat\Step\When;
use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionCycleInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use Sylius\Behat\Context\Setup\CalendarContext;
use Sylius\Behat\Service\SharedStorageInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Payment\ScriptedGateway;
use Webmozart\Assert\Assert;

/** What the store's scheduler does, run on the day the scenario names. */
final class ProcessingRenewalsContext implements Context
{
    public function __construct(
        private readonly Command $processCyclesCommand,
        private readonly CalendarContext $calendarContext,
        private readonly EntityManagerInterface $entityManager,
        private readonly SharedStorageInterface $sharedStorage,
        private readonly ScriptedGateway $scriptedGateway,
    ) {
    }

    /** Each renewal, in turn, declined on its date and on every retry until it fails. */
    #[Given('/^the next (\d+) renewals of my subscription failed$/')]
    public function theNextRenewalsOfMySubscriptionFailed(int $count): void
    {
        for ($renewal = 0; $renewal < $count; ++$renewal) {
            $cycle = null;
            foreach ($this->subscription()->getCycles() as $candidate) {
                if (SubscriptionCycleInterface::STATE_SCHEDULED === $candidate->getState()) {
                    $cycle = $candidate;
                }
            }
            Assert::notNull($cycle, 'The subscription has no renewal scheduled.');
            $cycleId = $cycle->getId();
            $attemptAt = $cycle->getScheduledAt();

            while (null !== $attemptAt) {
                $this->scriptedGateway->willAnswer(ScriptedGateway::DECLINE, 'Insufficient funds.');
                $this->theRenewalsDueOnAreProcessed($attemptAt->format('Y-m-d H:i'));
                $cycle = $this->entityManager->find(SubscriptionCycleInterface::class, $cycleId);
                Assert::isInstanceOf($cycle, SubscriptionCycleInterface::class);
                $attemptAt = SubscriptionCycleInterface::STATE_AWAITING_PAYMENT === $cycle->getState() ? $cycle->getNextAttemptAt() : null;
            }
            Assert::same($cycle->getState(), SubscriptionCycleInterface::STATE_FAILED);
        }
    }

    #[When('/^the renewals due on "([^"]+)" are processed$/')]
    public function theRenewalsDueOnAreProcessed(string $dateTime): void
    {
        $this->calendarContext->itIsNow($dateTime);
        $this->entityManager->clear();

        $output = new BufferedOutput();
        $status = $this->processCyclesCommand->run(new ArrayInput([]), $output);
        Assert::same($status, Command::SUCCESS, $output->fetch());
    }

    #[Then('no renewal order should have been placed for it')]
    public function noRenewalOrderShouldHaveBeenPlaced(): void
    {
        foreach ($this->subscription()->getCycles() as $cycle) {
            Assert::true(1 === $cycle->getNumber() || null === $cycle->getOrder(), \sprintf('Renewal #%d has an order.', $cycle->getNumber()));
        }
    }

    #[Then('/^its renewal #(\d+) should be shipped to "([^"]+)" with "([^"]+)"$/')]
    public function itsRenewalShouldBeShippedTo(int $number, string $street, string $shippingMethod): void
    {
        foreach ($this->subscription()->getCycles() as $cycle) {
            if ($number === $cycle->getNumber()) {
                $order = $cycle->getOrder();
                Assert::notNull($order, \sprintf('Renewal #%d has no order.', $number));
                Assert::same($order->getShippingAddress()?->getStreet(), $street);
                Assert::same($order->getShipments()->first() ? $order->getShipments()->first()->getMethod()?->getName() : null, $shippingMethod);

                return;
            }
        }

        throw new \InvalidArgumentException(\sprintf('There is no renewal #%d.', $number));
    }

    #[Then('/^its renewal #(\d+) should have been charged "([^"]+)"$/')]
    public function itsRenewalShouldHaveBeenCharged(int $number, string $amount): void
    {
        foreach ($this->subscription()->getCycles() as $cycle) {
            if ($number === $cycle->getNumber()) {
                $total = $cycle->getOrder()?->getTotal();
                Assert::notNull($total, \sprintf('Renewal #%d has no order.', $number));
                Assert::same(\sprintf('$%s', number_format($total / 100, 2)), $amount);
                Assert::same($cycle->getOrder()?->getPaymentState(), 'paid');

                return;
            }
        }

        throw new \InvalidArgumentException(\sprintf('There is no renewal #%d.', $number));
    }

    private function subscription(): SubscriptionInterface
    {
        $subscription = $this->sharedStorage->get('subscription');
        Assert::isInstanceOf($subscription, SubscriptionInterface::class);
        $subscription = $this->entityManager->find($subscription::class, $subscription->getId());
        Assert::isInstanceOf($subscription, SubscriptionInterface::class);
        $this->entityManager->refresh($subscription);

        return $subscription;
    }
}
