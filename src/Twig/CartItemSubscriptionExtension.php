<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Twig;

use JpmMartin\SyliusSubscriptionPlugin\Frequency\CartItemSubscriptionChangerInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** Lets the cart page offer a line's subscription options without a controller of their own. */
final class CartItemSubscriptionExtension extends AbstractExtension
{
    public function __construct(private readonly CartItemSubscriptionChangerInterface $changer)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('jpm_martin_sylius_subscription_cart_item_subscription_options', $this->changer->getOptions(...)),
        ];
    }
}
