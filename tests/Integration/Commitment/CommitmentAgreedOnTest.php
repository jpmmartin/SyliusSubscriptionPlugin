<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Integration\Commitment;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Integration\Lifecycle\LifecycleTestCase;

/** Coffee on its monthly plan, which commits its subscribers to six cycles. */
final class CommitmentAgreedOnTest extends LifecycleTestCase
{
    public function testEachItemKeepsTheCommitmentItWasSubscribedWithWhenThePlanIsEditedAfter(): void
    {
        $this->coffeeMonthly->setCommitmentCycles(6);
        $this->entityManager()->flush();
        $this->pay($this->placedCoffeeOrder());
        $first = $this->onlySubscription();
        self::assertSame(6, $this->onlyItemOf($first)->getCommitmentCycles());

        $this->coffeeMonthly->setCommitmentCycles(3);
        $this->entityManager()->flush();
        $this->pay($this->placedCoffeeOrder());

        $commitments = [];
        foreach ($this->storedSubscriptions() as $subscription) {
            $commitments[] = $this->onlyItemOf($subscription)->getCommitmentCycles();
        }
        self::assertSame([6, 3], $commitments, 'The first subscriber keeps six cycles; the new one has three.');
    }

    public function testAPlanWithoutCommitmentCommitsItsItemsToNothing(): void
    {
        $this->pay($this->placedCoffeeOrder());

        self::assertNull($this->onlyItemOf($this->onlySubscription())->getCommitmentCycles());
    }

    private function onlySubscription(): SubscriptionInterface
    {
        $subscriptions = $this->storedSubscriptions();
        self::assertCount(1, $subscriptions);

        return $subscriptions[0];
    }
}
