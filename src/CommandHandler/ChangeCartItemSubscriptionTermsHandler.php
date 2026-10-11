<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\CommandHandler;

use JpmMartin\SyliusSubscriptionPlugin\Cart\CartAccessInterface;
use JpmMartin\SyliusSubscriptionPlugin\Command\ChangeCartItemSubscriptionTerms;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionFrequencyInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionPlanInterface;
use JpmMartin\SyliusSubscriptionPlugin\Frequency\CartItemSubscriptionChangerInterface;
use JpmMartin\SyliusSubscriptionPlugin\Repository\SubscriptionFrequencyRepositoryInterface;
use Sylius\Bundle\ApiBundle\Exception\UnprocessableCartException;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Core\Repository\OrderRepositoryInterface;
use Sylius\Component\Order\Processor\OrderProcessorInterface;
use Sylius\Component\Order\Repository\OrderItemRepositoryInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The shop API's counterpart of the cart page's per-line dropdown (choose-subscription-per-cart-line, D8). The
 * command has been validated by CartItemSubscriptionTermsOffered by the time this runs, so the terms given are
 * among the line's own options; this only has to resolve them back to entities and apply them.
 */
final class ChangeCartItemSubscriptionTermsHandler
{
    /**
     * @param OrderRepositoryInterface<OrderInterface> $orderRepository
     * @param OrderItemRepositoryInterface<OrderItemInterface> $orderItemRepository
     * @param RepositoryInterface<SubscriptionPlanInterface> $subscriptionPlanRepository
     * @param SubscriptionFrequencyRepositoryInterface<SubscriptionFrequencyInterface> $subscriptionFrequencyRepository
     */
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly OrderItemRepositoryInterface $orderItemRepository,
        private readonly CartAccessInterface $cartAccess,
        private readonly CartItemSubscriptionChangerInterface $changer,
        private readonly OrderProcessorInterface $orderProcessor,
        private readonly RepositoryInterface $subscriptionPlanRepository,
        private readonly SubscriptionFrequencyRepositoryInterface $subscriptionFrequencyRepository,
    ) {
    }

    public function __invoke(ChangeCartItemSubscriptionTerms $command): OrderInterface
    {
        $cart = $this->orderRepository->findCartByTokenValue($command->orderTokenValue);
        if (!$cart instanceof OrderInterface) {
            throw new UnprocessableCartException();
        }

        $this->cartAccess->assertAccessible($cart);

        // Also confirms the line is this cart's own: a match requires both the id and the cart's token.
        $item = $this->orderItemRepository->findOneByIdAndCartTokenValue($command->orderItemId, $command->orderTokenValue);
        if (!$item instanceof OrderItemInterface) {
            throw new NotFoundHttpException('Cart item not found.');
        }

        $plan = null === $command->subscriptionPlan ? null : $this->subscriptionPlanRepository->findOneBy(['code' => $command->subscriptionPlan]);
        $frequency = null === $command->subscriptionFrequency ? null : $this->subscriptionFrequencyRepository->findOneBy(['code' => $command->subscriptionFrequency]);

        $this->changer->change($item, $plan, $frequency);
        $this->orderProcessor->process($cart);

        return $cart;
    }
}
