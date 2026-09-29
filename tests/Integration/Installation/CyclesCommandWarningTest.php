<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Integration\Installation;

use Sylius\Component\Core\Model\OrderItem;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Installation\WithholdsSubscriptions;

/** The cycles command, which a store schedules, says the installation step is left and still succeeds. */
final class CyclesCommandWarningTest extends KernelTestCase
{
    use WithholdsSubscriptions;

    protected function tearDown(): void
    {
        $this->restoreSubscriptions();

        parent::tearDown();
    }

    public function testItWarnsWhileTheOrderItemCannotCarryThePlan(): void
    {
        $this->withholdSubscriptions();

        $tester = $this->runTheCyclesCommand();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $output = preg_replace('/\s+/', ' ', $tester->getDisplay());
        self::assertIsString($output);
        self::assertStringContainsString(OrderItem::class, $output);
        self::assertStringContainsString('does not carry the subscription plan', $output);
    }

    public function testItSaysNothingOnceItCan(): void
    {
        $tester = $this->runTheCyclesCommand();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringNotContainsString('does not carry the subscription plan', $tester->getDisplay());
    }

    private function runTheCyclesCommand(): CommandTester
    {
        $application = new Application(self::bootKernel());
        $tester = new CommandTester($application->find('jpm-martin:subscription:process-cycles'));
        $tester->execute([]);

        return $tester;
    }
}
