<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Console\Command;

use Doctrine\Persistence\ObjectManager;
use JpmMartin\SyliusSubscriptionPlugin\Command\ProcessSubscriptionCycle;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionCycleInterface;
use JpmMartin\SyliusSubscriptionPlugin\Repository\SubscriptionCycleRepositoryInterface;
use JpmMartin\SyliusSubscriptionPlugin\Schedule\SubscriptionCalendarInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Meant to run on a schedule. Each due cycle is dispatched with the version this run read, and is only
 * handled while that version still holds: a message delivered twice, or dispatched by two runs that
 * read the same version, is handled once. The messages go through sylius.command_bus, so a store may
 * route them to an asynchronous transport.
 *
 * It also says how many of the due cycles are more than one interval late, which is what the store
 * sees after its scheduler stopped for a while; the missed cycle policy decides what becomes of them.
 */
#[AsCommand(name: 'jpm-martin:subscription:process-cycles', description: 'Processes the subscription cycles that are due.')]
final class ProcessSubscriptionCyclesCommand extends Command
{
    /** @param SubscriptionCycleRepositoryInterface<SubscriptionCycleInterface> $cycleRepository */
    public function __construct(
        private readonly SubscriptionCycleRepositoryInterface $cycleRepository,
        private readonly MessageBusInterface $commandBus,
        private readonly ClockInterface $clock,
        private readonly SubscriptionCalendarInterface $calendar,
        private readonly ObjectManager $entityManager,
        private readonly string $missedCycles,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $now = $this->clock->now();
        $due = $this->cycleRepository->findDue($now);

        $late = $this->countLate($due, $now);
        // Each handler loads its cycle again: one already in memory would have its version checked
        // against what this command read rather than against the database.
        $this->entityManager->clear();
        if (0 < $late) {
            $io->warning(\sprintf(
                '%d due subscription cycle(s) are more than one interval late: the cycles command may have stopped running for a while. The missed cycle policy decides what becomes of the dates that passed (missed_cycles: %s).',
                $late,
                $this->missedCycles,
            ));
        }

        $failed = 0;
        foreach ($due as $cycle) {
            try {
                $this->commandBus->dispatch(new ProcessSubscriptionCycle($cycle['id'], $cycle['version']));
            } catch (\Throwable $exception) {
                ++$failed;
                $io->error(\sprintf('Subscription cycle %d: %s', $cycle['id'], $exception->getMessage()));
            }
        }

        $io->success(\sprintf('%d due subscription cycle(s) processed, %d failed.', \count($due) - $failed, $failed));

        return 0 === $failed ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * The scheduled ones whose next date has come too.
     *
     * @param list<array{id: int, version: int}> $due
     */
    private function countLate(array $due, \DateTimeImmutable $now): int
    {
        $late = 0;
        foreach ($due as $row) {
            $cycle = $this->cycleRepository->find($row['id']);
            if (!$cycle instanceof SubscriptionCycleInterface || SubscriptionCycleInterface::STATE_SCHEDULED !== $cycle->getState()) {
                continue;
            }
            $subscription = $cycle->getSubscription();
            if (null !== $subscription && $this->calendar->dateOfCycle($subscription, $cycle->getNumber() + 1) <= $now) {
                ++$late;
            }
        }

        return $late;
    }
}
