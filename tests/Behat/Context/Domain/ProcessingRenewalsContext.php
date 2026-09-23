<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Behat\Context\Domain;

use Behat\Behat\Context\Context;
use Behat\Step\Then;
use Behat\Step\When;
use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use Sylius\Behat\Context\Setup\CalendarContext;
use Sylius\Behat\Service\SharedStorageInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Webmozart\Assert\Assert;

/** What the store's scheduler does, run on the day the scenario names. */
final class ProcessingRenewalsContext implements Context
{
    public function __construct(
        private readonly Command $processCyclesCommand,
        private readonly CalendarContext $calendarContext,
        private readonly EntityManagerInterface $entityManager,
        private readonly SharedStorageInterface $sharedStorage,
    ) {
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
