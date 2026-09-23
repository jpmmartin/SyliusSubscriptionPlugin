<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\OrderProcessing;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionPlanAwareInterface;
use Sylius\Component\Core\Calculator\ProductVariantPricesCalculatorInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Order\Model\OrderInterface as BaseOrderInterface;
use Sylius\Component\Order\Processor\OrderProcessorInterface;
use Webmozart\Assert\Assert;

/**
 * Prices a subscription line at the variant's channel price less the discount of its terms: its plan,
 * or the frequency its cart is repeated with.
 *
 * Runs right after Sylius's own price recalculation (priority 50) and before shipments, promotions
 * and taxes, so everything that follows sees the subscriber price, as it would any unit price. The
 * original unit price is left as Sylius set it, so the saving shows against the regular price.
 * Immutable lines keep the price they were given, which is how a renewal keeps its frozen price.
 */
final class SubscriptionPlanPriceProcessor implements OrderProcessorInterface
{
    public function __construct(private readonly ProductVariantPricesCalculatorInterface $productVariantPricesCalculator)
    {
    }

    public function process(BaseOrderInterface $order): void
    {
        Assert::isInstanceOf($order, OrderInterface::class);

        if (!$order->canBeProcessed()) {
            return;
        }

        $channel = $order->getChannel();
        if (null === $channel) {
            return;
        }

        foreach ($order->getItems() as $item) {
            if ($item->isImmutable() || !$item instanceof SubscriptionPlanAwareInterface) {
                continue;
            }

            $terms = $item->getSubscriptionTerms();
            $variant = $item->getVariant();
            if (null === $terms || null === $variant) {
                continue;
            }

            $price = $this->productVariantPricesCalculator->calculate($variant, ['channel' => $channel]);

            $item->setUnitPrice(self::applyDiscount($price, $terms->getDiscountPercentage()));
        }
    }

    /** Rounded half up to the minor unit, the way the rest of the order is priced. */
    public static function applyDiscount(int $price, int $discountPercentage): int
    {
        return (int) round($price * (100 - $discountPercentage) / 100);
    }
}
