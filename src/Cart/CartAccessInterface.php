<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Cart;

use Sylius\Component\Core\Model\OrderInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Whether the current shop API client may act on a given cart: a registered customer's cart is theirs alone, the
 * rule Sylius's own cart handlers enforce for one-off lines. Shared by every handler the plugin adds that works on
 * an existing cart, instead of each repeating it (choose-subscription-per-cart-line, task 4.1).
 */
interface CartAccessInterface
{
    /** @throws NotFoundHttpException when the cart belongs to a different registered customer */
    public function assertAccessible(OrderInterface $cart): void;
}
