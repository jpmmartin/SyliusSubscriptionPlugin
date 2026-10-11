<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Cart;

use Sylius\Bundle\ApiBundle\Context\UserContextInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\ShopUserInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Extracted from AddSubscriptionItemToCartHandler, which had it as a private method of its own before this cart
 * gained a second API handler that needs the same rule (choose-subscription-per-cart-line, task 4.1).
 */
final class CartAccess implements CartAccessInterface
{
    public function __construct(private readonly ?UserContextInterface $userContext = null)
    {
    }

    public function assertAccessible(OrderInterface $cart): void
    {
        if (null === $this->userContext || $cart->isCreatedByGuest()) {
            return;
        }

        $cartCustomer = $cart->getCustomer();
        if (!$cartCustomer instanceof CustomerInterface || null === $cartCustomer->getUser()) {
            return;
        }

        $currentUser = $this->userContext->getUser();
        if ($currentUser instanceof ShopUserInterface && $currentUser->getCustomer()?->getId() === $cartCustomer->getId()) {
            return;
        }

        throw new NotFoundHttpException('Cart not found.');
    }
}
