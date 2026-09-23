<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Console\Command;

use JpmMartin\SyliusSubscriptionPlugin\Command\ProcessSubscriptionCycle;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionCycleInterface;
use JpmMartin\SyliusSubscriptionPlugin\Repository\SubscriptionCycleRepositoryInterface;
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
 */
#[AsCommand(name: 'jpm-martin:subscription:process-cycles', description: 'Processes the subscription cycles that are due.')]
final class ProcessSubscriptionCyclesCommand extends Command
{
    /** @param SubscriptionCycleRepositoryInterface<SubscriptionCycleInterface> $cycleRepository */
    public function __construct(
        private readonly SubscriptionCycleRepositoryInterface $cycleRepository,
        private readonly MessageBusInterface $commandBus,
        private readonly ClockInterface $clock,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $due = $this->cycleRepository->findDue($this->clock->now());
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
}
