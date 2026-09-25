<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Management;

use JpmMartin\SyliusSubscriptionPlugin\Consent\SubscriptionConsentRecorderInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionCycleInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionFrequencyInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionItemInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionPlanInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionTermsInterface;
use JpmMartin\SyliusSubscriptionPlugin\Event\EventPublisher;
use JpmMartin\SyliusSubscriptionPlugin\Event\SubscriptionItemsChanged;
use JpmMartin\SyliusSubscriptionPlugin\Frequency\RepeatableVariantsInterface;
use JpmMartin\SyliusSubscriptionPlugin\Order\VariantAvailabilityChecker;
use JpmMartin\SyliusSubscriptionPlugin\OrderProcessing\SubscriptionPlanPriceProcessor;
use JpmMartin\SyliusSubscriptionPlugin\Schedule\SubscriptionInterval;
use JpmMartin\SyliusSubscriptionPlugin\Schedule\SubscriptionSchedulerInterface;
use Psr\Clock\ClockInterface;
use Sylius\Component\Core\Calculator\ProductVariantPricesCalculatorInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Webmozart\Assert\Assert;

/**
 * Offers are worked out the way the cart prices a subscription line, and a variant is offered when the
 * channel sells at least one of it; the changes are checked against the offers of the moment they are
 * applied, all of them before any is.
 */
final class SubscriptionItemEditor implements SubscriptionItemEditorInterface
{
    private const UNCHARGED_OPEN_CYCLE_STATES = [SubscriptionCycleInterface::STATE_SCHEDULED, SubscriptionCycleInterface::STATE_ON_HOLD];

    /** @param FactoryInterface<SubscriptionItemInterface> $itemFactory */
    public function __construct(
        private readonly SubscriptionTermsFinder $termsFinder,
        private readonly RepeatableVariantsInterface $repeatableVariants,
        private readonly VariantAvailabilityChecker $availabilityChecker,
        private readonly ProductVariantPricesCalculatorInterface $productVariantPricesCalculator,
        private readonly SubscriptionSchedulerInterface $scheduler,
        private readonly SubscriptionConsentRecorderInterface $consentRecorder,
        private readonly FactoryInterface $itemFactory,
        private readonly ClockInterface $clock,
        private readonly EventPublisher $eventPublisher,
        private readonly int $maxQuantity,
    ) {
    }

    public function canEdit(SubscriptionInterface $subscription): bool
    {
        if (SubscriptionInterface::STATE_ACTIVE !== $subscription->getState()) {
            return false;
        }

        $openCycle = $this->scheduler->findOpenCycle($subscription);

        // Neither state has an order yet: one awaiting payment keeps the items it was placed with.
        return null !== $openCycle && \in_array($openCycle->getState(), self::UNCHARGED_OPEN_CYCLE_STATES, true);
    }

    public function editableItems(SubscriptionInterface $subscription): array
    {
        if (!$this->canEdit($subscription)) {
            return [];
        }

        return array_values(array_filter(
            $subscription->getItems()->toArray(),
            static fn (SubscriptionItemInterface $item): bool => $item->isRenewable(),
        ));
    }

    public function maxQuantity(): int
    {
        return $this->maxQuantity;
    }

    public function variantsFor(SubscriptionItemInterface $item): array
    {
        $subscription = $item->getSubscription();
        $variant = $item->getProductVariant();
        if (null === $subscription || null === $variant) {
            return [];
        }
        $editable = $this->editableItems($subscription);
        if (!\in_array($item, $editable, true)) {
            return [];
        }

        $channel = $this->channelOf($subscription);
        $interval = self::intervalOf($subscription);
        $taken = self::variantsOf(array_filter($editable, static fn (SubscriptionItemInterface $other): bool => $other !== $item));

        $offers = [];
        foreach ($variant->getProduct()?->getVariants() ?? [] as $candidate) {
            if (!$candidate instanceof ProductVariantInterface || $candidate === $variant || \in_array($candidate, $taken, true)) {
                continue;
            }

            $terms = null !== $item->getFrequency() ?
                $this->frequencyFor($candidate, $channel, $interval) :
                $this->planFor($candidate, $interval);
            $maxCycles = $terms?->getMaxCycles();
            if (null === $terms || (null !== $maxCycles && $item->getPaidCycles() >= $maxCycles)) {
                continue;
            }

            $offer = $this->offer($candidate, $terms, $channel);
            if (null !== $offer) {
                $offers[] = $offer;
            }
        }

        return $offers;
    }

    public function canRemove(SubscriptionItemInterface $item): bool
    {
        $subscription = $item->getSubscription();
        if (null === $subscription) {
            return false;
        }

        $editable = $this->editableItems($subscription);

        return \in_array($item, $editable, true) && 1 < \count($editable);
    }

    public function variantsToAdd(SubscriptionInterface $subscription): array
    {
        $editable = $this->editableItems($subscription);
        if ([] === $editable) {
            return [];
        }

        $channel = $this->channelOf($subscription);
        $interval = self::intervalOf($subscription);
        $taken = self::variantsOf($editable);

        /** @var array<int, array{ProductVariantInterface, SubscriptionTermsInterface}> $candidates by the variant's object id */
        $candidates = [];
        foreach ($this->termsFinder->plansWith($interval) as $plan) {
            $variant = $plan->getProductVariant();
            Assert::notNull($variant);
            $candidates[spl_object_id($variant)] = [$variant, $plan];
        }
        $frequency = $this->termsFinder->frequenciesOf($channel)[$interval->key()] ?? null;
        if (null !== $frequency) {
            foreach ($this->repeatableVariants->findAllRepeatable() as $variant) {
                $candidates[spl_object_id($variant)] ??= [$variant, $frequency];
            }
        }

        $offers = [];
        foreach ($candidates as [$variant, $terms]) {
            if (\in_array($variant, $taken, true)) {
                continue;
            }

            $offer = $this->offer($variant, $terms, $channel);
            if (null !== $offer) {
                $offers[] = $offer;
            }
        }

        usort($offers, static fn (SubscriptionItemOffer $a, SubscriptionItemOffer $b): int => [
            (string) $a->variant->getProduct()?->getName(),
            $a->variant->getPosition(),
            $a->variant->getId(),
        ] <=> [
            (string) $b->variant->getProduct()?->getName(),
            $b->variant->getPosition(),
            $b->variant->getId(),
        ]);

        return $offers;
    }

    public function renewalTotalAfter(SubscriptionInterface $subscription, SubscriptionItemChanges $changes): int
    {
        return $this->check($subscription, $changes)['renewalTotal'];
    }

    public function requiresConsent(SubscriptionInterface $subscription, SubscriptionItemChanges $changes): bool
    {
        return $this->renewalTotalAfter($subscription, $changes) > $subscription->getRenewalTotal();
    }

    public function apply(SubscriptionInterface $subscription, SubscriptionItemChanges $changes, ?string $acceptedConsentLocaleCode = null): bool
    {
        $checked = $this->check($subscription, $changes);
        if (!$changes->changesAnything()) {
            return false;
        }

        $previousRenewalTotal = $subscription->getRenewalTotal();
        $raisesTotal = $checked['renewalTotal'] > $previousRenewalTotal;
        Assert::false(
            $raisesTotal && null === $acceptedConsentLocaleCode,
            'The changes raise what each renewal costs, so the customer must accept the recurring charges again.',
        );

        foreach ($checked['edits'] as [$edit, $offer]) {
            if ($edit->removed) {
                $edit->item->setRemovedAt($this->clock->now());

                continue;
            }

            $edit->item->setQuantity($edit->quantity);
            if (null !== $offer) {
                $edit->item->setProductVariant($offer->variant);
                self::renewOn($edit->item, $offer);
            }
        }

        foreach ($checked['additions'] as [$addition, $offer]) {
            $item = $this->itemFactory->createNew();
            $item->setProductVariant($offer->variant);
            $item->setQuantity($addition->quantity);
            self::renewOn($item, $offer);
            $subscription->addItem($item);
        }

        if ($raisesTotal && null !== $acceptedConsentLocaleCode) {
            $this->consentRecorder->recordOnSubscription($subscription, $acceptedConsentLocaleCode);
        }

        $subscriptionId = $subscription->getId();
        if (null !== $subscriptionId) {
            $this->eventPublisher->publish(new SubscriptionItemsChanged($subscriptionId, $previousRenewalTotal, $subscription->getRenewalTotal()));
        }

        return true;
    }

    /**
     * @return array{
     *     edits: list<array{SubscriptionItemEdit, ?SubscriptionItemOffer}>,
     *     additions: list<array{SubscriptionItemAddition, SubscriptionItemOffer}>,
     *     renewalTotal: int,
     * } each edit with the offer of the variant it moves to, and each addition with its own
     */
    private function check(SubscriptionInterface $subscription, SubscriptionItemChanges $changes): array
    {
        Assert::true($this->canEdit($subscription), 'The items of this subscription cannot be changed now.');
        $editable = $this->editableItems($subscription);

        $edits = [];
        /** @var array<int, array{SubscriptionItemEdit, ?SubscriptionItemOffer}> $editsByItem */
        $editsByItem = [];
        foreach ($changes->edits() as $edit) {
            Assert::true(\in_array($edit->item, $editable, true), 'This item of the subscription cannot be changed.');
            $offer = null;
            if (!$edit->removed) {
                $this->assertQuantity($edit->quantity);
                if ($edit->changesVariant()) {
                    $offer = self::offerOf($edit->variant, $this->variantsFor($edit->item));
                    Assert::notNull($offer, 'This item cannot move to that variant.');
                }
            }

            $edits[] = $editsByItem[spl_object_id($edit->item)] = [$edit, $offer];
        }

        /** @var list<ProductVariantInterface> $variants */
        $variants = [];
        $renewalTotal = 0;
        foreach ($editable as $item) {
            [$edit, $offer] = $editsByItem[spl_object_id($item)] ?? [null, null];
            if (true === $edit?->removed) {
                continue;
            }

            $variant = $edit?->variant ?? $item->getProductVariant();
            Assert::notNull($variant);
            $variants[] = $variant;
            $renewalTotal += ($offer?->unitPrice ?? $item->getUnitPrice()) * ($edit?->quantity ?? $item->getQuantity());
        }

        $additions = [];
        $toAdd = [] === $changes->additions() ? [] : $this->variantsToAdd($subscription);
        foreach ($changes->additions() as $addition) {
            $this->assertQuantity($addition->quantity);
            $offer = self::offerOf($addition->variant, $toAdd);
            Assert::notNull($offer, 'That variant cannot be added to this subscription.');

            $additions[] = [$addition, $offer];
            $variants[] = $addition->variant;
            $renewalTotal += $offer->unitPrice * $addition->quantity;
        }

        Assert::notEmpty($variants, 'At least one item of the subscription must still renew.');
        Assert::count(
            array_unique(array_map(spl_object_id(...), $variants)),
            \count($variants),
            'A variant can only be once in a subscription: change its quantity instead.',
        );

        return ['edits' => $edits, 'additions' => $additions, 'renewalTotal' => $renewalTotal];
    }

    private function assertQuantity(int $quantity): void
    {
        Assert::range($quantity, 1, $this->maxQuantity, \sprintf('The quantity must be between 1 and %d.', $this->maxQuantity));
    }

    private function planFor(ProductVariantInterface $variant, SubscriptionInterval $interval): ?SubscriptionPlanInterface
    {
        return $this->termsFinder->plansOf($variant)[$interval->key()] ?? null;
    }

    private function frequencyFor(ProductVariantInterface $variant, ChannelInterface $channel, SubscriptionInterval $interval): ?SubscriptionFrequencyInterface
    {
        if (!$this->repeatableVariants->isRepeatable($variant)) {
            return null;
        }

        return $this->termsFinder->frequenciesOf($channel)[$interval->key()] ?? null;
    }

    /** Null when the channel does not sell the variant, or has no price for it. */
    private function offer(ProductVariantInterface $variant, SubscriptionTermsInterface $terms, ChannelInterface $channel): ?SubscriptionItemOffer
    {
        if (null === $variant->getChannelPricingForChannel($channel) || !$this->availabilityChecker->isAvailable($variant, $channel, 1)) {
            return null;
        }

        $price = $this->productVariantPricesCalculator->calculate($variant, ['channel' => $channel]);

        return new SubscriptionItemOffer($variant, $terms, SubscriptionPlanPriceProcessor::applyDiscount($price, $terms->getDiscountPercentage()));
    }

    private function channelOf(SubscriptionInterface $subscription): ChannelInterface
    {
        $channel = $subscription->getChannel();
        Assert::isInstanceOf($channel, ChannelInterface::class);

        return $channel;
    }

    private static function intervalOf(SubscriptionInterface $subscription): SubscriptionInterval
    {
        return new SubscriptionInterval($subscription->getBillingIntervalCount(), $subscription->getBillingIntervalUnit());
    }

    /**
     * @param iterable<SubscriptionItemInterface> $items
     *
     * @return list<ProductVariantInterface>
     */
    private static function variantsOf(iterable $items): array
    {
        $variants = [];
        foreach ($items as $item) {
            $variant = $item->getProductVariant();
            if (null !== $variant) {
                $variants[] = $variant;
            }
        }

        return $variants;
    }

    /** @param list<SubscriptionItemOffer> $offers */
    private static function offerOf(ProductVariantInterface $variant, array $offers): ?SubscriptionItemOffer
    {
        foreach ($offers as $offer) {
            if ($offer->variant === $variant) {
                return $offer;
            }
        }

        return null;
    }

    private static function renewOn(SubscriptionItemInterface $item, SubscriptionItemOffer $offer): void
    {
        $item->setPlan($offer->terms instanceof SubscriptionPlanInterface ? $offer->terms : null);
        $item->setFrequency($offer->terms instanceof SubscriptionFrequencyInterface ? $offer->terms : null);
        $item->setUnitPrice($offer->unitPrice);
    }
}
