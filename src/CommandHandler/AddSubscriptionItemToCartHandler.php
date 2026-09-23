<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\CommandHandler;

use JpmMartin\SyliusSubscriptionPlugin\Command\AddSubscriptionItemToCart;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionPlanAwareInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionPlanInterface;
use Sylius\Bundle\ApiBundle\Context\UserContextInterface;
use Sylius\Bundle\ApiBundle\Exception\ProductVariantUnprocessableException;
use Sylius\Bundle\ApiBundle\Exception\UnprocessableCartException;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Core\Model\ShopUserInterface;
use Sylius\Component\Core\Repository\OrderRepositoryInterface;
use Sylius\Component\Core\Repository\ProductVariantRepositoryInterface;
use Sylius\Component\Order\Modifier\OrderItemQuantityModifierInterface;
use Sylius\Component\Order\Modifier\OrderModifierInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Webmozart\Assert\Assert;

/**
 * Sylius's AddItemToCartHandler, with the plan set on the line. The line goes into the cart through
 * sylius.modifier.order, which the plugin decorates to keep subscription and one-off lines apart.
 * The command has been validated by then, so the plan is an enabled plan of this variant.
 */
final class AddSubscriptionItemToCartHandler
{
    /**
     * @param OrderRepositoryInterface<OrderInterface> $orderRepository
     * @param ProductVariantRepositoryInterface<ProductVariantInterface> $productVariantRepository
     * @param RepositoryInterface<SubscriptionPlanInterface> $subscriptionPlanRepository
     * @param FactoryInterface<OrderItemInterface> $cartItemFactory
     */
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly ProductVariantRepositoryInterface $productVariantRepository,
        private readonly RepositoryInterface $subscriptionPlanRepository,
        private readonly OrderModifierInterface $orderModifier,
        private readonly FactoryInterface $cartItemFactory,
        private readonly OrderItemQuantityModifierInterface $orderItemQuantityModifier,
        private readonly ?UserContextInterface $userContext = null,
    ) {
    }

    public function __invoke(AddSubscriptionItemToCart $command): OrderInterface
    {
        $variant = $this->productVariantRepository->findOneBy(['code' => $command->productVariantCode]);
        if (!$variant instanceof ProductVariantInterface) {
            throw new ProductVariantUnprocessableException();
        }

        $cart = $this->orderRepository->findCartByTokenValue($command->orderTokenValue);
        if (!$cart instanceof OrderInterface) {
            throw new UnprocessableCartException();
        }

        $this->assertCartAccessible($cart);

        $plan = $this->subscriptionPlanRepository->findOneBy(['code' => $command->subscriptionPlanCode]);
        Assert::isInstanceOf($plan, SubscriptionPlanInterface::class);

        $cartItem = $this->cartItemFactory->createNew();
        Assert::isInstanceOf($cartItem, SubscriptionPlanAwareInterface::class);
        $cartItem->setVariant($variant);
        $cartItem->setSubscriptionPlan($plan);

        $this->orderItemQuantityModifier->modify($cartItem, $command->quantity);
        $this->orderModifier->addToOrder($cart, $cartItem);

        return $cart;
    }

    /** The same rule as Sylius's handler: a registered customer's cart is theirs alone. */
    private function assertCartAccessible(OrderInterface $cart): void
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
