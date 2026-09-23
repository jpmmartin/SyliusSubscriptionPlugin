<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\CommandHandler;

use JpmMartin\SyliusSubscriptionPlugin\Command\RepeatCart;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionFrequencyInterface;
use JpmMartin\SyliusSubscriptionPlugin\Frequency\CartRepeaterInterface;
use Sylius\Bundle\ApiBundle\Exception\UnprocessableCartException;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Repository\OrderRepositoryInterface;
use Sylius\Component\Order\Processor\OrderProcessorInterface;

/**
 * Repeats the cart, or stops repeating it, and processes it so its lines take the choice; the command
 * bus's transaction middleware flushes it. The frequency was validated as offered to the cart.
 */
final class RepeatCartHandler
{
    /** @param OrderRepositoryInterface<OrderInterface> $orderRepository */
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly CartRepeaterInterface $cartRepeater,
        private readonly OrderProcessorInterface $orderProcessor,
    ) {
    }

    public function __invoke(RepeatCart $command): OrderInterface
    {
        $cart = $this->orderRepository->findCartByTokenValue($command->orderTokenValue);
        if (!$cart instanceof OrderInterface) {
            throw new UnprocessableCartException();
        }

        if (null === $command->subscriptionFrequencyCode) {
            $this->cartRepeater->stopRepeating($cart);
        } else {
            $this->cartRepeater->repeat($cart, $this->offered($cart, $command->subscriptionFrequencyCode));
        }

        $this->orderProcessor->process($cart);

        return $cart;
    }

    private function offered(OrderInterface $cart, string $code): SubscriptionFrequencyInterface
    {
        foreach ($this->cartRepeater->getOfferedFrequencies($cart) as $frequency) {
            if ($frequency->getCode() === $code) {
                return $frequency;
            }
        }

        throw new \InvalidArgumentException(\sprintf('The "%s" subscription frequency is not offered to this cart.', $code));
    }
}
