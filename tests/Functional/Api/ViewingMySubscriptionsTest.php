<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Functional\Api;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionChargeAttempt;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionChargeAttemptInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionCycleInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionCycleItem;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;

/** GET /api/v2/shop/subscriptions and /api/v2/shop/subscriptions/{id}, for the signed-in customer. */
final class ViewingMySubscriptionsTest extends ShopSubscriptionApiTestCase
{
    public function testTheCustomerListsOnlyTheirSubscriptions(): void
    {
        $this->signIn();
        $this->request('GET', '/api/v2/shop/subscriptions');

        self::assertResponseIsSuccessful();
        $response = $this->responseJson();
        $members = $response['hydra:member'] ?? $response['member'] ?? [];
        self::assertIsArray($members);
        self::assertCount(1, $members, 'Only their own.');
        $subscription = $members[0];
        self::assertIsArray($subscription);
        self::assertSame($this->mySubscription->getId(), $subscription['id']);
        self::assertSame('active', $subscription['state']);
        self::assertSame([1, 'month'], [$subscription['intervalCount'], $subscription['intervalUnit']]);
        self::assertSame(1800, $subscription['renewalTotal']);
        self::assertSame('2027-02-01T09:00:00+00:00', $subscription['nextRenewalAt']);
        self::assertIsArray($subscription['items']);
        self::assertIsArray($subscription['items'][0]);
        self::assertSame(['Coffee', 1, 1800], [$subscription['items'][0]['product'], $subscription['items'][0]['quantity'], $subscription['items'][0]['unitPrice']]);
        self::assertArrayNotHasKey('actions', $subscription, 'The list is not the page.');
    }

    public function testTheDetailTellsTheRenewalsAndTheActionsTheAccountWouldOffer(): void
    {
        $this->signIn();
        $this->request('GET', $this->uriOf($this->mySubscription));

        self::assertResponseIsSuccessful();
        $subscription = $this->responseJson();
        self::assertIsArray($subscription['cycles']);
        self::assertSame([[1, 'paid'], [2, 'scheduled']], array_map(static fn (array $cycle): array => [$cycle['number'], $cycle['state']], $subscription['cycles']));
        self::assertIsArray($subscription['actions']);
        foreach (['cancel', 'pause', 'skip_renewal', 'change_frequency', 'change_address', 'change_items'] as $action) {
            self::assertContains($action, $subscription['actions']);
        }
        self::assertNotContains('resume', $subscription['actions']);
        self::assertSame([['intervalCount' => 3, 'intervalUnit' => 'month']], $subscription['frequencies']);
        self::assertIsArray($subscription['shippingAddress']);
        self::assertIsArray($subscription['consent']);
        self::assertSame('1', $subscription['consent']['version']);
    }

    public function testTheDetailTellsWhatTheAccountShowsOfTheRenewalsAndTheItems(): void
    {
        $this->storedSubscriptionIs(static function (SubscriptionInterface $subscription): void {
            // Its first order left the item out as out of stock, and the customer paid it themselves.
            $first = self::cycleOf($subscription, 1);
            $attempt = new SubscriptionChargeAttempt();
            $attempt->setType(SubscriptionChargeAttemptInterface::TYPE_CUSTOMER);
            $attempt->setOutcome(SubscriptionChargeAttemptInterface::OUTCOME_APPROVED);
            $attempt->setAttemptedAt(new \DateTimeImmutable('2027-01-01 09:00'));
            $first->addAttempt($attempt);
            $skipped = new SubscriptionCycleItem();
            $skipped->setSubscriptionItem(self::itemOf($subscription));
            $skipped->setQuantity(1);
            $skipped->setUnitPrice(1800);
            $skipped->setSkippedReason('out_of_stock');
            $first->addItem($skipped);
            // Its item costs $15.00 for its first three cycles, one of them paid.
            self::itemOf($subscription)->setIntroductoryPrice(1500, 3);
        });
        $this->signIn();

        $this->request('GET', $this->uriOf($this->mySubscription));

        self::assertResponseIsSuccessful();
        $response = $this->responseJson();
        self::assertIsArray($response['cycles']);
        [$first, $second] = $response['cycles'];
        self::assertIsArray($first);
        self::assertIsArray($second);
        $stored = $this->stored($this->mySubscription);
        $orderNumber = self::cycleOf($stored, 1)->getOrder()?->getNumber();
        self::assertNotNull($orderNumber);
        self::assertSame([$orderNumber, true], [$first['orderNumber'], $first['paidByCustomer']]);
        self::assertSame([['item' => self::itemOf($stored)->getId(), 'product' => 'Coffee', 'reason' => 'out_of_stock']], $first['skippedItems']);
        self::assertSame([null, false, []], [$second['orderNumber'], $second['paidByCustomer'], $second['skippedItems']]);
        self::assertIsArray($response['items']);
        self::assertIsArray($response['items'][0]);
        self::assertSame([1500, 2], [$response['items'][0]['introductoryUnitPrice'], $response['items'][0]['introductoryCyclesLeft']]);
        self::assertSame([null, null], [$response['lastPrepaidDeliveryAt'], $response['notRecoverableReason']]);
    }

    public function testTheDetailTellsWhenTheLastDeliveryPaidForComes(): void
    {
        $this->storedSubscriptionIs(static function (SubscriptionInterface $subscription): void {
            // January's charge paid for February and March too.
            $subscription->setPrepaidDeliveriesLeft(2);
            self::cycleOf($subscription, 2)->setCharging(false);
        });
        $this->signIn();

        $this->request('GET', $this->uriOf($this->mySubscription));

        self::assertResponseIsSuccessful();
        $response = $this->responseJson();
        self::assertSame([2, '2027-03-01T09:00:00+00:00'], [$response['prepaidDeliveriesLeft'], $response['lastPrepaidDeliveryAt']]);
    }

    public function testTheDetailTellsWhyASuspendedSubscriptionCannotBeRecovered(): void
    {
        $this->storedSubscriptionIs(static function (SubscriptionInterface $subscription): void {
            // Suspended once its February renewal failed, and Coffee is no longer sold.
            $subscription->setState(SubscriptionInterface::STATE_SUSPENDED);
            $subscription->setSuspendedForUnpaidRenewals(true);
            self::cycleOf($subscription, 2)->setState(SubscriptionCycleInterface::STATE_FAILED);
            self::itemOf($subscription)->getProductVariant()?->setEnabled(false);
        });
        $this->signIn();

        $this->request('GET', $this->uriOf($this->mySubscription));

        self::assertResponseIsSuccessful();
        $response = $this->responseJson();
        self::assertSame('nothing_to_renew', $response['notRecoverableReason']);
        self::assertIsArray($response['actions']);
        self::assertNotContains('recover', $response['actions']);
    }

    public function testAnotherCustomersSubscriptionIsNotFound(): void
    {
        $this->signIn();
        $this->request('GET', $this->uriOf($this->theirSubscription));

        self::assertResponseStatusCodeSame(404);
    }

    public function testNothingIsShownWithoutSigningIn(): void
    {
        $this->request('GET', '/api/v2/shop/subscriptions');
        self::assertResponseStatusCodeSame(401);

        $this->request('GET', $this->uriOf($this->mySubscription));
        self::assertResponseStatusCodeSame(401);
    }
}
