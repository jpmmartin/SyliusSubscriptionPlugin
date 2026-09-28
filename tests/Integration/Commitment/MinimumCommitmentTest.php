<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Integration\Commitment;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionIntervalUnit;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionItemInterface;
use JpmMartin\SyliusSubscriptionPlugin\Management\SubscriptionCommitmentInterface;
use JpmMartin\SyliusSubscriptionPlugin\Management\SubscriptionFrequencyChangerInterface;
use JpmMartin\SyliusSubscriptionPlugin\Management\SubscriptionItemChanges;
use JpmMartin\SyliusSubscriptionPlugin\Management\SubscriptionItemEditorInterface;
use JpmMartin\SyliusSubscriptionPlugin\Management\SubscriptionRenewalSkipperInterface;
use JpmMartin\SyliusSubscriptionPlugin\Schedule\SubscriptionInterval;
use JpmMartin\SyliusSubscriptionPlugin\StateMachine\SubscriptionTransitions;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Behat\Context\Setup\ProductContext;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Integration\Lifecycle\LifecycleTestCase;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Payment\ScriptedGateway;

/**
 * Coffee on its monthly plan, which commits its subscribers to six paid cycles, the initial order
 * included, and Tea on its own monthly plan, which commits to nothing; activated on 1 January and
 * renewed by the command on the first of each month.
 */
final class MinimumCommitmentTest extends LifecycleTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->coffeeMonthly->setCommitmentCycles(6);
        $this->entityManager()->flush();
        $this->itIsNow('2027-01-01 09:00');
    }

    public function testTheCustomerCanNeitherCancelNorPauseBeforeTheCommitmentIsMetAndSeesWhatIsLeft(): void
    {
        $this->pay($this->placedCoffeeOrder());
        $this->renewOn('2027-02-01', '2027-03-01', '2027-04-01');

        $subscription = $this->subscription();
        self::assertSame(4, $this->onlyItemOf($subscription)->getPaidCycles());
        self::assertSame(2, $this->commitment()->remainingCycles($subscription));
        self::assertSame([false, false], $this->asTheCustomer(fn (): array => $this->canCancelAndPause($subscription)));
    }

    public function testOnceTheSixthCycleIsPaidTheCustomerCanCancelAndPause(): void
    {
        $this->pay($this->placedCoffeeOrder());
        $this->renewOn('2027-02-01', '2027-03-01', '2027-04-01', '2027-05-01');
        self::assertSame([false, false], $this->asTheCustomer(fn (): array => $this->canCancelAndPause($this->subscription())));

        $this->renewOn('2027-06-01');

        $subscription = $this->subscription();
        self::assertFalse($this->commitment()->isCommitted($subscription));
        self::assertSame(0, $this->commitment()->remainingCycles($subscription));
        self::assertSame([true, true], $this->asTheCustomer(fn (): array => $this->canCancelAndPause($subscription)));
    }

    public function testASkippedRenewalDoesNotCountSoTheCommitmentLastsUntilTheSixthPaidCycle(): void
    {
        $this->pay($this->placedCoffeeOrder());
        /** @var SubscriptionRenewalSkipperInterface $skipper */
        $skipper = self::getContainer()->get('jpm_martin_sylius_subscription.management.renewal_skipper');
        $skipper->skip($this->subscription());
        $this->entityManager()->flush();
        $this->renewOn('2027-02-01', '2027-03-01', '2027-04-01', '2027-05-01', '2027-06-01');

        $subscription = $this->subscription();
        self::assertSame(5, $this->onlyItemOf($subscription)->getPaidCycles(), 'February was skipped.');
        self::assertSame(1, $this->commitment()->remainingCycles($subscription));
        self::assertSame([false, false], $this->asTheCustomer(fn (): array => $this->canCancelAndPause($subscription)));
    }

    public function testTheAdministratorCancelsASubscriptionWithinItsCommitment(): void
    {
        $this->pay($this->placedCoffeeOrder());
        $subscription = $this->subscription();
        self::assertTrue($this->commitment()->isCommitted($subscription));

        $this->apply($subscription, SubscriptionTransitions::GRAPH, SubscriptionTransitions::TRANSITION_CANCEL);
        $this->entityManager()->flush();

        self::assertSame(SubscriptionInterface::STATE_CANCELLED, $this->subscription()->getState());
    }

    public function testTheCyclesCommandSuspendsASubscriptionWithinItsCommitment(): void
    {
        $this->pay($this->placedCoffeeOrder());
        foreach (['2027-02-01', '2027-03-01', '2027-04-01'] as $date) {
            $this->scriptedGateway()->willAnswer(ScriptedGateway::DECLINE, 'Stolen card.', 'stolen_card');
            $this->renewOn($date);
        }

        $subscription = $this->subscription();
        self::assertTrue($this->commitment()->isCommitted($subscription));
        self::assertSame(SubscriptionInterface::STATE_SUSPENDED, $subscription->getState());
    }

    public function testACommittedItemCannotBeRemovedAndOneThatCommitsToNothingCan(): void
    {
        $this->pay($this->placedBatchOrder());
        $subscription = $this->subscription();
        [$coffee, $tea] = array_values($subscription->getItems()->toArray());
        self::assertInstanceOf(SubscriptionItemInterface::class, $coffee);
        self::assertInstanceOf(SubscriptionItemInterface::class, $tea);

        self::assertFalse($this->editor()->canRemove($coffee));
        self::assertTrue($this->editor()->canRemove($tea));

        $changes = new SubscriptionItemChanges();
        $changes->edit($coffee)->removed = true;
        $this->expectExceptionMessage('This item is still within its minimum commitment.');
        $this->editor()->apply($subscription, $changes, 'en_US');
    }

    public function testChangingTheFrequencyOrTheVariantKeepsTheCommitmentAndThePaidCycles(): void
    {
        $this->plan($this->coffee, 'COFFEE_QUARTERLY', 3, SubscriptionIntervalUnit::Month, 15);
        $decaf = $this->anotherVariantOfCoffee();
        $this->plan($decaf, 'DECAF_QUARTERLY', 3, SubscriptionIntervalUnit::Month, 15);
        $this->entityManager()->flush();
        $this->pay($this->placedCoffeeOrder());
        $this->renewOn('2027-02-01');

        /** @var SubscriptionFrequencyChangerInterface $changer */
        $changer = self::getContainer()->get(SubscriptionFrequencyChangerInterface::class);
        $changer->change($this->subscription(), new SubscriptionInterval(3, SubscriptionIntervalUnit::Month));
        $this->entityManager()->flush();
        $item = $this->onlyItemOf($this->subscription());
        self::assertSame(['COFFEE_QUARTERLY', 6, 2], [$item->getPlan()?->getCode(), $item->getCommitmentCycles(), $item->getPaidCycles()]);

        // The command started afresh, so Decaf is taken as the editor offers it.
        [$decafOffer] = $this->editor()->variantsFor($item);
        self::assertSame($decaf->getCode(), $decafOffer->variant->getCode());
        $changes = new SubscriptionItemChanges();
        $changes->edit($item)->variant = $decafOffer->variant;
        $this->editor()->apply($this->subscription(), $changes, 'en_US');
        $this->entityManager()->flush();
        $item = $this->onlyItemOf($this->subscription());
        self::assertSame(['DECAF_QUARTERLY', 6, 2], [$item->getPlan()?->getCode(), $item->getCommitmentCycles(), $item->getPaidCycles()], 'The quarterly plan of Decaf commits to nothing, but the item keeps what it agreed to.');
        self::assertSame(4, $this->commitment()->remainingCycles($this->subscription()));
    }

    private function anotherVariantOfCoffee(): ProductVariantInterface
    {
        $product = $this->coffee->getProduct();
        self::assertInstanceOf(ProductInterface::class, $product);
        /** @var ProductContext $products */
        $products = self::getContainer()->get('sylius.behat.context.setup.product');
        $products->theProductHasVariantPricedAt($product, 'Decaf', 11000);
        foreach ($product->getVariants() as $variant) {
            if ('DECAF' === $variant->getCode()) {
                self::assertInstanceOf(ProductVariantInterface::class, $variant);

                return $variant;
            }
        }

        self::fail('Coffee has no Decaf variant.');
    }

    private function renewOn(string ...$dates): void
    {
        foreach ($dates as $date) {
            $this->itIsNow($date . ' 09:00');
            $this->runTheCycleCommand();
        }
    }

    /** @return array{bool, bool} whether it can be cancelled, and paused */
    private function canCancelAndPause(SubscriptionInterface $subscription): array
    {
        /** @var StateMachineInterface $stateMachine */
        $stateMachine = self::getContainer()->get('sylius_abstraction.state_machine');

        return [
            $stateMachine->can($subscription, SubscriptionTransitions::GRAPH, SubscriptionTransitions::TRANSITION_CANCEL),
            $stateMachine->can($subscription, SubscriptionTransitions::GRAPH, SubscriptionTransitions::TRANSITION_PAUSE),
        ];
    }

    /**
     * Within a request of one of the account's routes, which declare their actor.
     *
     * @template T
     *
     * @param callable(): T $action
     *
     * @return T
     */
    private function asTheCustomer(callable $action): mixed
    {
        /** @var RequestStack $requestStack */
        $requestStack = self::getContainer()->get('request_stack');
        $requestStack->push(new Request(attributes: ['_subscription_actor' => 'customer']));

        try {
            return $action();
        } finally {
            $requestStack->pop();
        }
    }

    private function subscription(): SubscriptionInterface
    {
        $subscriptions = $this->storedSubscriptions();
        self::assertCount(1, $subscriptions);

        return $subscriptions[0];
    }

    private function commitment(): SubscriptionCommitmentInterface
    {
        /** @var SubscriptionCommitmentInterface $commitment */
        $commitment = self::getContainer()->get(SubscriptionCommitmentInterface::class);

        return $commitment;
    }

    private function editor(): SubscriptionItemEditorInterface
    {
        /** @var SubscriptionItemEditorInterface $editor */
        $editor = self::getContainer()->get(SubscriptionItemEditorInterface::class);

        return $editor;
    }
}
