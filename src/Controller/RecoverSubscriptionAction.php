<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Controller;

use Doctrine\Persistence\ObjectManager;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use JpmMartin\SyliusSubscriptionPlugin\Management\SubscriptionRecoveryInterface;
use JpmMartin\SyliusSubscriptionPlugin\Repository\SubscriptionRepositoryInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Customer\Context\CustomerContextInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * "Pay and reactivate", and the link of the store's notice of a suspension: the customer, signed in as
 * the account's firewall requires, starts the recovery, or finds the one they started, and goes on to
 * the order payment page. A GET, so the notice's link works: at worst it places an order that expires.
 */
final class RecoverSubscriptionAction
{
    /** @param SubscriptionRepositoryInterface<SubscriptionInterface> $subscriptionRepository */
    public function __construct(
        private readonly SubscriptionRepositoryInterface $subscriptionRepository,
        private readonly CustomerContextInterface $customerContext,
        private readonly SubscriptionRecoveryInterface $recovery,
        private readonly ObjectManager $subscriptionManager,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function __invoke(Request $request, string $id): Response
    {
        $customer = $this->customerContext->getCustomer();
        $subscription = $this->subscriptionRepository->findOneByIdAndCustomer($id, $customer instanceof CustomerInterface ? $customer : null);
        if (null === $subscription) {
            throw new NotFoundHttpException('The subscription does not exist.');
        }

        if (!$this->recovery->canRecover($subscription)) {
            $session = $request->getSession();
            if ($session instanceof FlashBagAwareSessionInterface) {
                $session->getFlashBag()->add('error', 'jpm_martin_sylius_subscription.subscription.recovery_not_possible');
            }

            return new RedirectResponse($this->urlGenerator->generate(
                $request->attributes->getString('redirect_route'),
                ['id' => $subscription->getId()],
            ));
        }

        $order = $this->recovery->start($subscription);
        $this->subscriptionManager->flush();

        return new RedirectResponse($this->urlGenerator->generate('sylius_shop_order_show', ['tokenValue' => $order->getTokenValue()]));
    }
}
