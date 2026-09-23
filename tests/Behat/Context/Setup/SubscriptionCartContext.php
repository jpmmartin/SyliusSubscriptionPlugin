<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Behat\Context\Setup;

use Behat\Behat\Context\Context;
use Behat\Step\Given;
use JpmMartin\SyliusSubscriptionPlugin\Command\AddSubscriptionItemToCart;
use Sylius\Behat\Context\Setup\CartContext;
use Sylius\Behat\Service\SharedStorageInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Core\Model\ShopUserInterface;
use Sylius\Component\Core\OrderCheckoutStates;
use Sylius\Component\Product\Resolver\ProductVariantResolverInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Webmozart\Assert\Assert;

/**
 * Puts a subscription line in the cart the way Sylius's own setup puts a one-off line: through the
 * command bus, on the cart Sylius picked up for this scenario, which is the one the shop session
 * and the API client then use.
 */
final class SubscriptionCartContext implements Context
{
    public function __construct(
        private readonly CartContext $syliusCartContext,
        private readonly MessageBusInterface $commandBus,
        private readonly ProductVariantResolverInterface $productVariantResolver,
        private readonly SharedStorageInterface $sharedStorage,
        private readonly ?TokenStorageInterface $tokenStorage = null,
    ) {
    }

    #[Given('/^I have (product "[^"]+") in the cart on the "([^"]+)" plan$/')]
    public function iHaveProductInTheCartOnThePlan(ProductInterface $product, string $planCode): void
    {
        if (!$this->hasAnOpenCart()) {
            $this->syliusCartContext->theCustomerHasTheCart();
        }

        $tokenValue = $this->sharedStorage->get('cart_token');
        Assert::string($tokenValue);

        $variant = $this->productVariantResolver->getVariant($product);
        Assert::isInstanceOf($variant, ProductVariantInterface::class);

        $this->asTheLoggedInCustomer(fn () => $this->commandBus->dispatch(new AddSubscriptionItemToCart(
            orderTokenValue: $tokenValue,
            productVariantCode: (string) $variant->getCode(),
            subscriptionPlanCode: $planCode,
            quantity: 1,
        )));

        $this->sharedStorage->set('product', $product);
    }

    private function hasAnOpenCart(): bool
    {
        if (!$this->sharedStorage->has('cart_token') || !$this->sharedStorage->has('order')) {
            return false;
        }

        $order = $this->sharedStorage->get('order');

        return $order instanceof OrderInterface &&
            $order->getTokenValue() === $this->sharedStorage->get('cart_token') &&
            OrderCheckoutStates::STATE_COMPLETED !== $order->getCheckoutState();
    }

    /** A logged-in customer's cart only accepts lines from that customer, as in Sylius's own setup. */
    private function asTheLoggedInCustomer(callable $callback): void
    {
        $previousToken = $this->tokenStorage?->getToken();

        if (null !== $this->tokenStorage && $this->sharedStorage->has('user')) {
            $user = $this->sharedStorage->get('user');
            if ($user instanceof ShopUserInterface) {
                $this->tokenStorage->setToken(new UsernamePasswordToken($user, 'api_shop', $user->getRoles()));
            }
        }

        try {
            $callback();
        } finally {
            $this->tokenStorage?->setToken($previousToken);
        }
    }
}
