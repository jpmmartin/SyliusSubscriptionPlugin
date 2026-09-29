<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Twig;

use JpmMartin\SyliusSubscriptionPlugin\Installation\OrderItemReadinessInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** Lets the admin say which installation step is left, while it is. */
final class InstallationExtension extends AbstractExtension
{
    public function __construct(private readonly OrderItemReadinessInterface $orderItemReadiness)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('jpm_martin_sylius_subscription_order_item_ready', $this->orderItemReadiness->isReady(...)),
            new TwigFunction('jpm_martin_sylius_subscription_order_item_class', $this->orderItemReadiness->orderItemClass(...)),
        ];
    }
}
