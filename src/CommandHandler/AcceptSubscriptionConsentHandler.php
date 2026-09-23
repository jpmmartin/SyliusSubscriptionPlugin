<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\CommandHandler;

use JpmMartin\SyliusSubscriptionPlugin\Command\AcceptSubscriptionConsent;
use JpmMartin\SyliusSubscriptionPlugin\Consent\SubscriptionConsentRecorderInterface;
use Sylius\Bundle\ApiBundle\Exception\UnprocessableCartException;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Repository\OrderRepositoryInterface;

/** Records the acceptance; the command bus's transaction middleware flushes it. */
final class AcceptSubscriptionConsentHandler
{
    /** @param OrderRepositoryInterface<OrderInterface> $orderRepository */
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly SubscriptionConsentRecorderInterface $consentRecorder,
    ) {
    }

    public function __invoke(AcceptSubscriptionConsent $command): OrderInterface
    {
        $cart = $this->orderRepository->findCartByTokenValue($command->orderTokenValue);
        if (!$cart instanceof OrderInterface) {
            throw new UnprocessableCartException();
        }

        $this->consentRecorder->record($cart);

        return $cart;
    }
}
