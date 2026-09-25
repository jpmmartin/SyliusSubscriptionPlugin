<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Payment;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionChargeAttemptInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionCycleInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Symfony\Component\HttpFoundation\UrlHelper;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Built the way Sylius builds a channel's links in its emails: on the channel's hostname when it has
 * one, and otherwise on the current request's, or the router's default URI without a request, as in a
 * worker handling events.
 */
final class RenewalPaymentLinkGenerator implements RenewalPaymentLinkGeneratorInterface
{
    private const PAYABLE_OUTCOMES = [SubscriptionChargeAttemptInterface::OUTCOME_DECLINED, SubscriptionChargeAttemptInterface::OUTCOME_NOT_ATTEMPTED];

    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly UrlHelper $urlHelper,
        private readonly bool $unsecuredUrls,
    ) {
    }

    public function generate(SubscriptionCycleInterface $cycle): ?string
    {
        $order = $cycle->getOrder();
        $tokenValue = $order?->getTokenValue();
        $lastAttempt = $cycle->getAttempts()->last();
        if (
            SubscriptionCycleInterface::STATE_AWAITING_PAYMENT !== $cycle->getState() ||
            null === $cycle->getNextAttemptAt() ||
            !$lastAttempt instanceof SubscriptionChargeAttemptInterface ||
            !\in_array($lastAttempt->getOutcome(), self::PAYABLE_OUTCOMES, true) ||
            null === $order ||
            null === $tokenValue ||
            null === $order->getLastPayment(PaymentInterface::STATE_NEW)
        ) {
            return null;
        }

        $path = $this->urlGenerator->generate('sylius_shop_order_show', [
            'tokenValue' => $tokenValue,
            '_locale' => $order->getLocaleCode(),
        ]);

        $hostname = $order->getChannel() instanceof ChannelInterface ? $order->getChannel()->getHostname() : null;
        if (null !== $hostname && '' !== $hostname) {
            return ($this->unsecuredUrls ? 'http://' : 'https://') . $hostname . $path;
        }

        return $this->urlHelper->getAbsoluteUrl($path);
    }
}
