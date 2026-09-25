<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Management;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionCycleInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionFrequencyInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionIntervalUnit;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionItemInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionPlanInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionTermsInterface;
use JpmMartin\SyliusSubscriptionPlugin\Event\EventPublisher;
use JpmMartin\SyliusSubscriptionPlugin\Event\SubscriptionFrequencyChanged;
use JpmMartin\SyliusSubscriptionPlugin\OrderProcessing\SubscriptionPlanPriceProcessor;
use JpmMartin\SyliusSubscriptionPlugin\Schedule\SubscriptionInterval;
use JpmMartin\SyliusSubscriptionPlugin\Schedule\SubscriptionSchedulerInterface;
use Sylius\Component\Core\Calculator\ProductVariantPricesCalculatorInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Webmozart\Assert\Assert;

/**
 * Every item moves, also one that no longer renews: under terms with a higher maximum, or none, it
 * renews again. Only an item its customer removed stays as it was. An item on a plan moves to a plan of its variant, and one repeated with a store
 * frequency to another of the store's frequencies, never from one kind to the other. The new prices
 * are worked out the way the cart prices a subscription line.
 */
final class SubscriptionFrequencyChanger implements SubscriptionFrequencyChangerInterface
{
    private const UNCHARGED_OPEN_CYCLE_STATES = [SubscriptionCycleInterface::STATE_SCHEDULED, SubscriptionCycleInterface::STATE_ON_HOLD];

    public function __construct(
        private readonly SubscriptionTermsFinder $termsFinder,
        private readonly ProductVariantPricesCalculatorInterface $productVariantPricesCalculator,
        private readonly SubscriptionSchedulerInterface $scheduler,
        private readonly EventPublisher $eventPublisher,
    ) {
    }

    public function frequenciesToChangeTo(SubscriptionInterface $subscription): array
    {
        return array_values(array_map(static fn (array $offer): SubscriptionInterval => $offer['interval'], $this->offersFor($subscription)));
    }

    public function change(SubscriptionInterface $subscription, SubscriptionInterval $interval): void
    {
        $offer = $this->offersFor($subscription)[$interval->key()] ?? null;
        Assert::notNull($offer, 'This subscription cannot change to that frequency now.');
        $openCycle = $this->scheduler->findOpenCycle($subscription);
        Assert::notNull($openCycle);
        $channel = $subscription->getChannel();
        Assert::isInstanceOf($channel, ChannelInterface::class);

        foreach ($offer['targets'] as [$item, $terms]) {
            $variant = $item->getProductVariant();
            Assert::notNull($variant);
            $price = $this->productVariantPricesCalculator->calculate($variant, ['channel' => $channel]);

            $item->setPlan($terms instanceof SubscriptionPlanInterface ? $terms : null);
            $item->setFrequency($terms instanceof SubscriptionFrequencyInterface ? $terms : null);
            $item->setUnitPrice(SubscriptionPlanPriceProcessor::applyDiscount($price, $terms->getDiscountPercentage()));
        }

        $subscription->setBillingIntervalCount($interval->count);
        $subscription->setBillingIntervalUnit($interval->unit);
        $subscription->setDeliveryIntervalCount($interval->count);
        $subscription->setDeliveryIntervalUnit($interval->unit);
        $subscription->setScheduleAnchorAt($openCycle->getScheduledAt());
        $subscription->setScheduleAnchorCycle($openCycle->getNumber());

        $subscriptionId = $subscription->getId();
        if (null === $subscriptionId) {
            return;
        }
        $this->eventPublisher->publish(new SubscriptionFrequencyChanged($subscriptionId, $interval->count, $interval->unit->value));
    }

    /**
     * @return array<string, array{interval: SubscriptionInterval, targets: list<array{SubscriptionItemInterface, SubscriptionTermsInterface}>}>
     *     by interval key, the shortest first, with the plan or frequency each item would move to
     */
    private function offersFor(SubscriptionInterface $subscription): array
    {
        $openCycle = $this->scheduler->findOpenCycle($subscription);
        if (
            SubscriptionInterface::STATE_ACTIVE !== $subscription->getState() ||
            null === $openCycle ||
            !\in_array($openCycle->getState(), self::UNCHARGED_OPEN_CYCLE_STATES, true)
        ) {
            return [];
        }

        $current = new SubscriptionInterval($subscription->getBillingIntervalCount(), $subscription->getBillingIntervalUnit());
        $storeFrequencies = null;
        $offers = null;
        foreach ($subscription->getItems() as $item) {
            if ($item->isRemoved()) {
                continue;
            }

            if (null !== $item->getFrequency()) {
                $storeFrequencies ??= self::otherThan($current, $this->storeFrequenciesOf($subscription));
                $targets = $storeFrequencies;
            } else {
                $variant = $item->getProductVariant();
                $targets = null === $variant ? [] : self::otherThan($current, $this->termsFinder->plansOf($variant));
            }

            if (null === $offers) {
                $offers = [];
                foreach ($targets as $key => $terms) {
                    $offers[$key] = ['interval' => SubscriptionInterval::of($terms), 'targets' => [[$item, $terms]]];
                }

                continue;
            }

            foreach (array_keys($offers) as $key) {
                if (isset($targets[$key])) {
                    $offers[$key]['targets'][] = [$item, $targets[$key]];
                } else {
                    unset($offers[$key]);
                }
            }
        }

        $offers ??= [];
        uasort($offers, static fn (array $a, array $b): int => self::lengthInDays($a['interval']) <=> self::lengthInDays($b['interval']));

        return $offers;
    }

    /** @return array<string, SubscriptionFrequencyInterface> by interval key, the frequencies of the subscription's channel */
    private function storeFrequenciesOf(SubscriptionInterface $subscription): array
    {
        $channel = $subscription->getChannel();
        if (!$channel instanceof ChannelInterface) {
            return [];
        }

        return $this->termsFinder->frequenciesOf($channel);
    }

    /**
     * @template T of SubscriptionTermsInterface
     *
     * @param array<string, T> $terms by interval key
     *
     * @return array<string, T> all but the current interval's
     */
    private static function otherThan(SubscriptionInterval $current, array $terms): array
    {
        unset($terms[$current->key()]);

        return $terms;
    }

    /** Only to order the intervals: a month counts 30 days and a year 365. */
    private static function lengthInDays(SubscriptionInterval $interval): int
    {
        return $interval->count * match ($interval->unit) {
            SubscriptionIntervalUnit::Day => 1,
            SubscriptionIntervalUnit::Week => 7,
            SubscriptionIntervalUnit::Month => 30,
            SubscriptionIntervalUnit::Year => 365,
        };
    }
}
