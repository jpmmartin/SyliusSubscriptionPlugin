<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Serializer;

use JpmMartin\SyliusSubscriptionPlugin\Api\ShopSubscriptionActions;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionChargeAttemptInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionCycleInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionCycleItemInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionItemInterface;
use JpmMartin\SyliusSubscriptionPlugin\Management\PrepaidDeliveries;
use JpmMartin\SyliusSubscriptionPlugin\Management\SubscriptionCommitmentInterface;
use JpmMartin\SyliusSubscriptionPlugin\Management\SubscriptionFrequencyChangerInterface;
use JpmMartin\SyliusSubscriptionPlugin\Management\SubscriptionRecoveryInterface;
use JpmMartin\SyliusSubscriptionPlugin\Order\RenewalAddressesResolver;
use JpmMartin\SyliusSubscriptionPlugin\Pricing\PendingPriceApplier;
use JpmMartin\SyliusSubscriptionPlugin\Schedule\SubscriptionSchedulerInterface;
use Sylius\Component\Addressing\Model\AddressInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Webmozart\Assert\Assert;

/**
 * A subscription of the shop API as the customer's account shows it: its list, and its page, with the
 * actions the account would offer. Worked out here rather than mapped, since most of it is asked of the
 * services the account asks.
 */
final class ShopSubscriptionNormalizer implements NormalizerInterface, NormalizerAwareInterface
{
    use NormalizerAwareTrait;

    public const INDEX = 'jpm_martin_sylius_subscription:shop:subscription:index';

    public const SHOW = 'jpm_martin_sylius_subscription:shop:subscription:show';

    private const ALREADY_CALLED = 'jpm_martin_sylius_subscription_shop_subscription_normalizer_already_called';

    public function __construct(
        private readonly SubscriptionSchedulerInterface $scheduler,
        private readonly ShopSubscriptionActions $actions,
        private readonly SubscriptionFrequencyChangerInterface $frequencyChanger,
        private readonly RenewalAddressesResolver $addressesResolver,
        private readonly PendingPriceApplier $pendingPriceApplier,
        private readonly SubscriptionCommitmentInterface $commitment,
        private readonly PrepaidDeliveries $prepaidDeliveries,
        private readonly SubscriptionRecoveryInterface $recovery,
        private readonly TranslatorInterface $translator,
        private readonly string $consentVersion,
    ) {
    }

    /** @return array<string, mixed> */
    public function normalize(mixed $object, ?string $format = null, array $context = []): array
    {
        Assert::isInstanceOf($object, SubscriptionInterface::class);

        $context[self::ALREADY_CALLED] = true;
        $data = $this->normalizer->normalize($object, $format, $context);
        Assert::isArray($data);

        $openCycle = $this->scheduler->findOpenCycle($object);
        $data += [
            'id' => $object->getId(),
            'state' => $object->getState(),
            'intervalCount' => $object->getDeliveryIntervalCount(),
            'intervalUnit' => $object->getDeliveryIntervalUnit()->value,
            'deliveriesPerCharge' => $object->getDeliveriesPerCharge(),
            'renewalTotal' => $object->getRenewalTotal(),
            'chargeTotal' => $object->getRenewalTotal() * $object->getDeliveriesPerCharge(),
            'currencyCode' => $object->getCurrencyCode(),
            'nextRenewalAt' => $openCycle?->getScheduledAt()?->format(\DateTimeInterface::ATOM),
            'items' => array_values(array_map(self::item(...), $object->getItems()->toArray())),
        ];

        if (!\in_array(self::SHOW, (array) ($context['groups'] ?? []), true)) {
            return $data;
        }

        return $data + [
            'activatedAt' => $object->getActivatedAt()?->format(\DateTimeInterface::ATOM),
            'cycles' => array_values(array_map(self::cycle(...), $object->getCycles()->toArray())),
            'shippingAddress' => self::address($this->addressesResolver->shippingAddress($object)),
            'billingAddress' => self::address($this->addressesResolver->billingAddress($object)),
            'shippingMethod' => $object->getShippingMethod()?->getCode(),
            'paymentMethod' => $object->getPaymentMethod()?->getCode(),
            'frequencies' => array_map(
                static fn ($interval): array => ['intervalCount' => $interval->count, 'intervalUnit' => $interval->unit->value],
                $this->frequencyChanger->frequenciesToChangeTo($object),
            ),
            'priceIncreaseAwaitsAcceptance' => $this->pendingPriceApplier->awaitsAcceptance($object),
            'remainingCommitmentCycles' => $this->commitment->remainingCycles($object),
            'prepaidDeliveriesLeft' => $object->getPrepaidDeliveriesLeft(),
            'cancelsAfterPrepaidDeliveries' => $object->cancelsAfterPrepaidDeliveries(),
            'lastPrepaidDeliveryAt' => $this->prepaidDeliveries->lastDeliveryAt($object)?->format(\DateTimeInterface::ATOM),
            'notRecoverableReason' => $this->recovery->whyNot($object),
            'consent' => [
                'version' => $this->consentVersion,
                'text' => $this->translator->trans('jpm_martin_sylius_subscription.consent.text', [], 'messages'),
            ],
            'actions' => $this->actions->of($object),
        ];
    }

    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return
            !isset($context[self::ALREADY_CALLED]) &&
            $data instanceof SubscriptionInterface &&
            [] !== array_intersect([self::INDEX, self::SHOW], (array) ($context['groups'] ?? []))
        ;
    }

    public function getSupportedTypes(?string $format): array
    {
        return [SubscriptionInterface::class => false];
    }

    /** @return array<string, mixed> */
    private static function item(SubscriptionItemInterface $item): array
    {
        $variant = $item->getProductVariant();

        return [
            'id' => $item->getId(),
            'product' => $variant?->getProduct()?->getName(),
            'productVariant' => $variant?->getCode(),
            'quantity' => $item->getQuantity(),
            'unitPrice' => $item->getUnitPrice(),
            'pendingUnitPrice' => $item->getPendingUnitPrice(),
            'pendingPriceFrom' => $item->getPendingPriceFrom()?->format(\DateTimeInterface::ATOM),
            'introductoryUnitPrice' => $item->isOnIntroductoryPrice() ? $item->getIntroductoryUnitPrice() : null,
            'introductoryCyclesLeft' => $item->isOnIntroductoryPrice() ? (int) $item->getIntroductoryCycles() - $item->getPaidCycles() : null,
            'subscriptionPlan' => $item->getPlan()?->getCode(),
            'subscriptionFrequency' => $item->getFrequency()?->getCode(),
            'removed' => $item->isRemoved(),
            'renewable' => $item->isRenewable(),
        ];
    }

    /** @return array<string, mixed> */
    private static function cycle(SubscriptionCycleInterface $cycle): array
    {
        return [
            'number' => $cycle->getNumber(),
            'scheduledAt' => $cycle->getScheduledAt()?->format(\DateTimeInterface::ATOM),
            'state' => $cycle->isSkipped() ? 'skipped' : $cycle->getState(),
            'charging' => $cycle->isCharging(),
            'orderNumber' => $cycle->getOrder()?->getNumber(),
            'paidByCustomer' => $cycle->getAttempts()->exists(
                static fn (int $key, SubscriptionChargeAttemptInterface $attempt): bool => SubscriptionChargeAttemptInterface::TYPE_CUSTOMER === $attempt->getType(),
            ),
            // What its order left out, as the account's renewals say it.
            'skippedItems' => array_values(array_map(
                static fn (SubscriptionCycleItemInterface $cycleItem): array => [
                    'item' => $cycleItem->getSubscriptionItem()?->getId(),
                    'product' => $cycleItem->getSubscriptionItem()?->getProductVariant()?->getProduct()?->getName(),
                    'reason' => $cycleItem->getSkippedReason(),
                ],
                $cycle->getItems()->filter(static fn (SubscriptionCycleItemInterface $cycleItem): bool => !$cycleItem->isIncluded())->toArray(),
            )),
        ];
    }

    /** @return array<string, string|null>|null */
    private static function address(?AddressInterface $address): ?array
    {
        if (null === $address) {
            return null;
        }

        return [
            'firstName' => $address->getFirstName(),
            'lastName' => $address->getLastName(),
            'company' => $address->getCompany(),
            'street' => $address->getStreet(),
            'city' => $address->getCity(),
            'postcode' => $address->getPostcode(),
            'countryCode' => $address->getCountryCode(),
            'provinceCode' => $address->getProvinceCode(),
            'provinceName' => $address->getProvinceName(),
            'phoneNumber' => $address->getPhoneNumber(),
        ];
    }
}
