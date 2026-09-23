<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Integration\StateMachine;

use PHPUnit\Framework\Attributes\DataProvider;
use Sylius\Abstraction\StateMachine\Exception\StateMachineExecutionException;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Abstraction\StateMachine\TransitionInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/** A graph checked against the table of the transitions its spec allows, through the abstraction the plugin applies it with. */
abstract class StateMachineGraphTestCase extends KernelTestCase
{
    abstract protected static function graph(): string;

    /** @return array<string, array<string, string>> the target of each allowed transition, by state */
    abstract protected static function allowed(): array;

    abstract protected function subjectIn(string $state): object;

    abstract protected function stateOf(object $subject): string;

    /** @return iterable<string, array{string}> */
    public static function states(): iterable
    {
        foreach (array_keys(static::allowed()) as $state) {
            yield $state => [$state];
        }
    }

    #[DataProvider('states')]
    public function testEachStateOffersOnlyTheTransitionsTheSpecAllows(string $state): void
    {
        $enabled = array_map(
            static fn (TransitionInterface $transition): string => $transition->getName(),
            $this->stateMachine()->getEnabledTransitions($this->subjectIn($state), static::graph()),
        );
        sort($enabled);

        $expected = array_keys(static::allowed()[$state]);
        sort($expected);

        self::assertSame($expected, $enabled);
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function allowedTransitions(): iterable
    {
        foreach (static::allowed() as $state => $transitions) {
            foreach ($transitions as $transition => $target) {
                yield \sprintf('%s: %s', $state, $transition) => [$state, $transition, $target];
            }
        }
    }

    #[DataProvider('allowedTransitions')]
    public function testAnAllowedTransitionMovesToItsTarget(string $state, string $transition, string $target): void
    {
        $subject = $this->subjectIn($state);

        $this->stateMachine()->apply($subject, static::graph(), $transition);

        self::assertSame($target, $this->stateOf($subject));
    }

    /** @return iterable<string, array{string, string}> */
    public static function forbiddenTransitions(): iterable
    {
        $allTransitions = array_unique(array_merge(...array_map('array_keys', array_values(static::allowed()))));

        foreach (static::allowed() as $state => $transitions) {
            foreach ($allTransitions as $transition) {
                if (!isset($transitions[$transition])) {
                    yield \sprintf('%s: %s', $state, $transition) => [$state, $transition];
                }
            }
        }
    }

    #[DataProvider('forbiddenTransitions')]
    public function testAForbiddenTransitionIsRejectedWithoutChangingTheState(string $state, string $transition): void
    {
        $subject = $this->subjectIn($state);

        self::assertFalse($this->stateMachine()->can($subject, static::graph(), $transition));

        try {
            $this->stateMachine()->apply($subject, static::graph(), $transition);
            self::fail(\sprintf('"%s" was applied from "%s".', $transition, $state));
        } catch (StateMachineExecutionException) {
        }

        self::assertSame($state, $this->stateOf($subject));
    }

    private function stateMachine(): StateMachineInterface
    {
        $stateMachine = self::getContainer()->get('sylius_abstraction.state_machine');
        self::assertInstanceOf(StateMachineInterface::class, $stateMachine);

        return $stateMachine;
    }
}
