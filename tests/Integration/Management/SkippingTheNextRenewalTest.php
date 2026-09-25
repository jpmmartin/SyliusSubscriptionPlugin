<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Integration\Management;

use Doctrine\Persistence\ManagerRegistry;
use JpmMartin\SyliusSubscriptionPlugin\Controller\SkipSubscriptionRenewalAction;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionCycleInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use JpmMartin\SyliusSubscriptionPlugin\Event\EventPublisher;
use JpmMartin\SyliusSubscriptionPlugin\Event\SubscriptionEventInterface;
use JpmMartin\SyliusSubscriptionPlugin\Management\SubscriptionRenewalSkipper;
use JpmMartin\SyliusSubscriptionPlugin\Management\SubscriptionRenewalSkipperInterface;
use JpmMartin\SyliusSubscriptionPlugin\Repository\SubscriptionRepositoryInterface;
use JpmMartin\SyliusSubscriptionPlugin\Schedule\SubscriptionSchedulerInterface;
use JpmMartin\SyliusSubscriptionPlugin\StateMachine\SubscriptionTransitions;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Customer\Context\CustomerContextInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Event\EventCollector;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Integration\Lifecycle\LifecycleTestCase;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Payment\ScriptedGateway;

/** A monthly Coffee subscription activated on 1 January whose customer skips renewals. */
final class SkippingTheNextRenewalTest extends LifecycleTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->itIsNow('2027-01-01 09:00');
        $this->pay($this->placedCoffeeOrder());
    }

    public function testSkippingTheNextRenewalCancelsItAsSkippedAndSchedulesTheFollowingDate(): void
    {
        $this->itIsNow('2027-01-20 09:00');
        $this->skip($this->skipper());

        self::assertSame([1 => 'paid', 2 => 'cancelled', 3 => 'scheduled'], $this->cycleStates());
        self::assertTrue($this->cycle(2)->isSkipped());
        self::assertSame('2027-03-01 09:00', $this->cycle(3)->getScheduledAt()?->format('Y-m-d H:i'));

        $this->itIsNow('2027-02-01 09:00');
        $this->runTheCycleCommand();

        self::assertNull($this->cycle(2)->getOrder(), 'No order is placed on 1 February.');
        self::assertSame([], $this->scriptedGateway()->requests());
        self::assertSame(SubscriptionInterface::STATE_ACTIVE, $this->subscription()->getState());
    }

    public function testARenewalWhoseOrderIsAlreadyPlacedCannotBeSkipped(): void
    {
        $this->scriptedGateway()->willAnswer(ScriptedGateway::DECLINE, 'Insufficient funds.');
        $this->itIsNow('2027-02-01 09:00');
        $this->runTheCycleCommand();
        self::assertSame(SubscriptionCycleInterface::STATE_AWAITING_PAYMENT, $this->cycle(2)->getState());

        self::assertFalse($this->skipper()->canSkip($this->subscription()));

        try {
            $this->skipper()->skip($this->subscription());
            self::fail('A renewal whose order is placed was skipped.');
        } catch (\InvalidArgumentException) {
        }

        self::assertSame([1 => 'paid', 2 => 'awaiting_payment'], $this->cycleStates());
        self::assertFalse($this->cycle(2)->isSkipped());
    }

    public function testNoMoreRenewalsInARowCanBeSkippedThanTheStoreAllows(): void
    {
        $skipper = $this->skipperAllowing(2);
        $this->itIsNow('2027-01-20 09:00');
        $this->skip($skipper);
        $this->itIsNow('2027-02-20 09:00');
        $this->skip($skipper);
        self::assertSame([1 => 'paid', 2 => 'cancelled', 3 => 'cancelled', 4 => 'scheduled'], $this->cycleStates());

        self::assertFalse($skipper->canSkip($this->subscription()), 'April would be a third skip in a row.');
        $this->expectException(\InvalidArgumentException::class);
        $skipper->skip($this->subscription());
    }

    public function testAPaidRenewalEndsTheRunOfSkips(): void
    {
        $skipper = $this->skipperAllowing(2);
        $this->itIsNow('2027-01-20 09:00');
        $this->skip($skipper);
        $this->itIsNow('2027-03-01 09:00');
        $this->runTheCycleCommand();
        $this->itIsNow('2027-03-20 09:00');
        $this->skip($skipper);
        self::assertSame([1 => 'paid', 2 => 'cancelled', 3 => 'paid', 4 => 'cancelled', 5 => 'scheduled'], $this->cycleStates());

        self::assertTrue($skipper->canSkip($this->subscription()), 'Only April is skipped in a row before May.');
    }

    public function testAFailedRenewalEndsTheRunOfSkips(): void
    {
        $skipper = $this->skipperAllowing(1);
        $this->itIsNow('2027-01-20 09:00');
        $this->skip($skipper);
        foreach (['2027-03-01 09:00', '2027-03-02 09:00', '2027-03-04 09:00', '2027-03-08 09:00'] as $dateTime) {
            $this->scriptedGateway()->willAnswer(ScriptedGateway::DECLINE, 'Insufficient funds.');
            $this->itIsNow($dateTime);
            $this->runTheCycleCommand();
        }
        self::assertSame([1 => 'paid', 2 => 'cancelled', 3 => 'failed', 4 => 'scheduled'], $this->cycleStates());

        self::assertTrue($skipper->canSkip($this->subscription()), 'February, skipped before the failed March, is not in a row with April.');
    }

    public function testARenewalCancelledByAPauseEndsTheRunOfSkips(): void
    {
        $skipper = $this->skipperAllowing(1);
        $this->itIsNow('2027-01-20 09:00');
        $this->skip($skipper);
        $this->itIsNow('2027-01-25 09:00');
        $this->transition(SubscriptionTransitions::TRANSITION_PAUSE);
        $this->itIsNow('2027-01-26 09:00');
        $this->transition(SubscriptionTransitions::TRANSITION_RESUME);
        self::assertSame([1 => 'paid', 2 => 'cancelled', 3 => 'cancelled', 4 => 'scheduled'], $this->cycleStates());
        self::assertTrue($this->cycle(2)->isSkipped());
        self::assertFalse($this->cycle(3)->isSkipped(), 'Cancelled by the pause, not skipped.');

        self::assertTrue($skipper->canSkip($this->subscription()), 'The renewal the pause cancelled ends the run.');
    }

    public function testASkippedRenewalIsNeitherAFailureNorAChargeTowardsThePlansMaximum(): void
    {
        $this->coffeeMonthly->setMaxCycles(2);
        $this->entityManager()->flush();

        $this->itIsNow('2027-01-20 09:00');
        $this->skip($this->skipper());

        $subscription = $this->subscription();
        self::assertSame(0, $subscription->getConsecutiveFailedCycles());
        self::assertSame(1, $this->onlyItemOf($subscription)->getPaidCycles());
        self::assertSame(SubscriptionInterface::STATE_ACTIVE, $subscription->getState(), 'The skipped renewal did not use up the plan.');

        $this->itIsNow('2027-03-01 09:00');
        $this->runTheCycleCommand();

        self::assertSame(SubscriptionInterface::STATE_COMPLETED, $this->subscription()->getState(), 'The plan ends on its second charge.');
    }

    public function testASkipWhoseCycleChangedMeanwhileIsRolledBackReportedAndNotPublished(): void
    {
        $this->itIsNow('2027-01-20 09:00');
        $subscription = $this->subscription();
        $openCycleId = (int) $this->cycle(2)->getId();
        // The cycles command stores a change to the cycle after this request read it.
        $this->entityManager()->getConnection()->executeStatement(
            'UPDATE jpm_martin_sylius_subscription_cycle SET version = version + 1 WHERE id = ?',
            [$openCycleId],
        );
        $collector = self::getContainer()->get('jpm_martin_sylius_subscription.test.event_collector');
        self::assertInstanceOf(EventCollector::class, $collector);
        $collector->clear();

        $request = new Request(attributes: ['redirect_route' => 'jpm_martin_sylius_subscription_admin_subscription_show', 'customer_only' => false]);
        $session = new Session(new MockArraySessionStorage());
        $request->setSession($session);
        $response = $this->skipAction()($request, (string) $subscription->getId());

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame(['error' => ['jpm_martin_sylius_subscription.subscription_cycle.skip_conflict']], $session->getFlashBag()->all());
        self::assertSame([], array_map(static fn (SubscriptionEventInterface $event): string => $event::class, $collector->events()), 'Nothing is published for a skip that was not stored.');

        /** @var ManagerRegistry $doctrine */
        $doctrine = self::getContainer()->get('doctrine');
        $doctrine->resetManager();
        self::assertSame([1 => 'paid', 2 => 'scheduled'], $this->cycleStates());
        self::assertFalse($this->cycle(2)->isSkipped());
    }

    public function testASkipTheHandlerRefusesIsReportedAsNotSkippable(): void
    {
        $this->scriptedGateway()->willAnswer(ScriptedGateway::DECLINE, 'Insufficient funds.');
        $this->itIsNow('2027-02-01 09:00');
        $this->runTheCycleCommand();
        self::assertSame(SubscriptionCycleInterface::STATE_AWAITING_PAYMENT, $this->cycle(2)->getState());

        // The action's own check still found the renewal skippable, as if its order was placed just after.
        $alwaysSkippable = new class() implements SubscriptionRenewalSkipperInterface {
            public function renewalToSkip(SubscriptionInterface $subscription): ?SubscriptionCycleInterface
            {
                return null;
            }

            public function canSkip(SubscriptionInterface $subscription): bool
            {
                return true;
            }

            public function skip(SubscriptionInterface $subscription): SubscriptionCycleInterface
            {
                throw new \LogicException('The action only checks.');
            }
        };
        $request = new Request(attributes: ['redirect_route' => 'jpm_martin_sylius_subscription_admin_subscription_show', 'customer_only' => false]);
        $session = new Session(new MockArraySessionStorage());
        $request->setSession($session);

        $response = $this->skipAction($alwaysSkippable)($request, (string) $this->subscription()->getId());

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame(['error' => ['jpm_martin_sylius_subscription.subscription_cycle.skip_not_allowed']], $session->getFlashBag()->all());
        self::assertFalse($this->cycle(2)->isSkipped());
    }

    /** A pause or a resumption, in a fresh entity manager like a new request. */
    private function transition(string $transition): void
    {
        $this->apply($this->subscription(), SubscriptionTransitions::GRAPH, $transition);
        $this->entityManager()->flush();
        $this->entityManager()->clear();
    }

    /** What the customer's action does, in a fresh entity manager like a new request. */
    private function skip(SubscriptionRenewalSkipperInterface $skipper): void
    {
        $skipper->skip($this->subscription());
        $this->entityManager()->flush();
        $this->entityManager()->clear();
    }

    /** The admin's skip action, with a token that is always valid; $checkingSkipper answers its own check. */
    private function skipAction(?SubscriptionRenewalSkipperInterface $checkingSkipper = null): SkipSubscriptionRenewalAction
    {
        $container = self::getContainer();
        /** @var SubscriptionRepositoryInterface<SubscriptionInterface> $repository */
        $repository = $container->get('jpm_martin_sylius_subscription.repository.subscription');
        /** @var CustomerContextInterface $customerContext */
        $customerContext = $container->get('sylius.context.customer');
        /** @var MessageBusInterface $commandBus */
        $commandBus = $container->get('sylius.command_bus');
        /** @var UrlGeneratorInterface $router */
        $router = $container->get('router');
        $csrf = new class() implements CsrfTokenManagerInterface {
            public function getToken(string $tokenId): CsrfToken
            {
                return new CsrfToken($tokenId, 'valid');
            }

            public function refreshToken(string $tokenId): CsrfToken
            {
                return new CsrfToken($tokenId, 'valid');
            }

            public function removeToken(string $tokenId): ?string
            {
                return null;
            }

            public function isTokenValid(CsrfToken $token): bool
            {
                return true;
            }
        };

        return new SkipSubscriptionRenewalAction($repository, $customerContext, $checkingSkipper ?? $this->skipper(), $commandBus, $csrf, $router);
    }

    private function skipper(): SubscriptionRenewalSkipperInterface
    {
        $skipper = self::getContainer()->get(SubscriptionRenewalSkipperInterface::class);
        self::assertInstanceOf(SubscriptionRenewalSkipperInterface::class, $skipper);

        return $skipper;
    }

    /** The store's skipper, with max_consecutive_skips set to $skips. */
    private function skipperAllowing(int $skips): SubscriptionRenewalSkipperInterface
    {
        /** @var StateMachineInterface $stateMachine */
        $stateMachine = self::getContainer()->get('sylius_abstraction.state_machine');
        /** @var SubscriptionSchedulerInterface $scheduler */
        $scheduler = self::getContainer()->get('jpm_martin_sylius_subscription.schedule.scheduler');
        /** @var EventPublisher $eventPublisher */
        $eventPublisher = self::getContainer()->get('jpm_martin_sylius_subscription.event.publisher');

        return new SubscriptionRenewalSkipper($stateMachine, $scheduler, $eventPublisher, $skips);
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

    /** @return array<int, string> */
    private function cycleStates(): array
    {
        $states = [];
        foreach ($this->storedCycles($this->subscription()) as $cycle) {
            $states[$cycle->getNumber()] = $cycle->getState();
        }

        return $states;
    }
}
