<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Twig;

use JpmMartin\SyliusSubscriptionPlugin\Frequency\CartRepeaterInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** Lets the cart page offer "Repeat this cart" without a controller of its own. */
final class CartRepetitionExtension extends AbstractExtension
{
    public function __construct(private readonly CartRepeaterInterface $cartRepeater)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('jpm_martin_sylius_subscription_cart_frequencies', $this->cartRepeater->getOfferedFrequencies(...)),
            new TwigFunction('jpm_martin_sylius_subscription_cart_frequency', $this->cartRepeater->getFrequency(...)),
            new TwigFunction('jpm_martin_sylius_subscription_cart_has_repeatable_lines', $this->cartRepeater->hasRepeatableLines(...)),
        ];
    }
}
