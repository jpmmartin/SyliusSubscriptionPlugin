<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Pricing;

use Doctrine\ORM\EntityRepository;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionItemInterface;
use JpmMartin\SyliusSubscriptionPlugin\Event\EventPublisher;
use JpmMartin\SyliusSubscriptionPlugin\Event\SubscriptionPriceChanged;
use JpmMartin\SyliusSubscriptionPlugin\Event\SubscriptionPriceIncreaseAnnounced;
use JpmMartin\SyliusSubscriptionPlugin\OrderProcessing\SubscriptionPlanPriceProcessor;
use Psr\Clock\ClockInterface;
use Sylius\Component\Core\Calculator\ProductVariantPricesCalculatorInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Webmozart\Assert\Assert;

final class SubscriptionPriceUpdater implements SubscriptionPriceUpdaterInterface
{
    private const SETTLED_STATES = [SubscriptionInterface::STATE_CANCELLED, SubscriptionInterface::STATE_COMPLETED];

    private const INCREASE = 'increase';

    private const DECREASE = 'decrease';

    private const WITHDRAWAL = 'withdrawal';

    /** @param EntityRepository<SubscriptionItemInterface> $itemRepository */
    public function __construct(
        private readonly EntityRepository $itemRepository,
        private readonly ProductVariantPricesCalculatorInterface $productVariantPricesCalculator,
        private readonly PriceIncreaseAcceptancePolicyInterface $acceptancePolicy,
        private readonly ClockInterface $clock,
        private readonly EventPublisher $eventPublisher,
        private readonly int $noticeDays,
    ) {
    }

    public function preview(PriceUpdateTarget $target): PriceUpdatePreview
    {
        /** @var list<SubscriptionItemInterface> $items */
        $items = $this->itemRepository->createQueryBuilder('item')
            ->innerJoin('item.subscription', 'subscription')
            ->andWhere(\sprintf('item.%s = :target', $target->itemField()))
            ->andWhere('item.removedAt IS NULL')
            ->andWhere('subscription.state NOT IN (:settled)')
            ->setParameter('target', $target->id)
            ->setParameter('settled', self::SETTLED_STATES)
            ->orderBy('subscription.id', 'ASC')
            ->getQuery()
            ->getResult()
        ;

        /** @var array<int, list<string|null>> $changesBySubscription */
        $changesBySubscription = [];
        foreach ($items as $item) {
            $subscriptionId = $item->getSubscription()?->getId();
            if (null !== $subscriptionId) {
                $changesBySubscription[$subscriptionId][] = self::changeOf($item, $this->newPriceOf($item));
            }
        }

        $increases = $decreases = $unchanged = 0;
        foreach ($changesBySubscription as $changes) {
            if (\in_array(self::INCREASE, $changes, true)) {
                ++$increases;
            } elseif (\in_array(self::DECREASE, $changes, true) || \in_array(self::WITHDRAWAL, $changes, true)) {
                ++$decreases;
            } else {
                ++$unchanged;
            }
        }

        return new PriceUpdatePreview($increases, $decreases, $unchanged, array_keys($changesBySubscription));
    }

    public function update(SubscriptionInterface $subscription, PriceUpdateTarget $target): void
    {
        if (\in_array($subscription->getState(), self::SETTLED_STATES, true)) {
            return;
        }

        $totalBefore = $subscription->getRenewalTotal();
        $appliesFrom = \DateTimeImmutable::createFromInterface($this->clock->now())->modify(\sprintf('+%d days', $this->noticeDays));
        $announced = false;
        foreach ($subscription->getItems() as $item) {
            if ($item->isRemoved() || !$target->matches($item)) {
                continue;
            }

            $newPrice = $this->newPriceOf($item);
            $change = self::changeOf($item, $newPrice);
            if (self::DECREASE === $change && null !== $newPrice) {
                $item->setUnitPrice($newPrice);
                $item->setPendingPrice(null, null);
            } elseif (self::INCREASE === $change) {
                $item->setPendingPrice($newPrice, $appliesFrom);
                $announced = true;
            } elseif (self::WITHDRAWAL === $change) {
                $item->setPendingPrice(null, null);
            }
        }

        $subscriptionId = $subscription->getId();
        $totalNow = $subscription->getRenewalTotal();
        if (null !== $subscriptionId && $totalNow !== $totalBefore) {
            $this->eventPublisher->publish(new SubscriptionPriceChanged($subscriptionId, $totalBefore, $totalNow));
        }

        if (!$announced) {
            return;
        }

        // A new increase asks the customer again, for everything pending.
        $subscription->setPriceIncreaseAcceptedAt(null);
        if (null !== $subscriptionId) {
            $this->eventPublisher->publish(new SubscriptionPriceIncreaseAnnounced(
                $subscriptionId,
                $totalNow,
                self::renewalTotalWithPendingPrices($subscription),
                $appliesFrom,
                $this->acceptancePolicy->requiresAcceptance($subscription),
            ));
        }
    }

    /** One of the changes, or null when the item stays as it is or cannot be priced in its channel. */
    private static function changeOf(SubscriptionItemInterface $item, ?int $newPrice): ?string
    {
        if (null === $newPrice) {
            return null;
        }

        $current = $item->getUnitPrice();

        return match (true) {
            $newPrice < $current => self::DECREASE,
            $newPrice > $current => $newPrice === $item->getPendingUnitPrice() ? null : self::INCREASE,
            $item->hasPendingPrice() => self::WITHDRAWAL,
            default => null,
        };
    }

    private function newPriceOf(SubscriptionItemInterface $item): ?int
    {
        $variant = $item->getProductVariant();
        $channel = $item->getSubscription()?->getChannel();
        $terms = $item->getTerms();
        if (null === $variant || !$channel instanceof ChannelInterface || null === $terms || null === $variant->getChannelPricingForChannel($channel)) {
            return null;
        }

        return SubscriptionPlanPriceProcessor::applyDiscount(
            $this->productVariantPricesCalculator->calculate($variant, ['channel' => $channel]),
            $terms->getDiscountPercentage(),
        );
    }

    private static function renewalTotalWithPendingPrices(SubscriptionInterface $subscription): int
    {
        $total = 0;
        foreach ($subscription->getItems() as $item) {
            if ($item->isRenewable()) {
                $total += ($item->getPendingUnitPrice() ?? $item->getUnitPrice()) * $item->getQuantity();
            }
        }
        Assert::greaterThanEq($total, 0);

        return $total;
    }
}
