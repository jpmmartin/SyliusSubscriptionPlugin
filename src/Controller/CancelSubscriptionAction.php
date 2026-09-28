<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Controller;

use Doctrine\Persistence\ObjectManager;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use JpmMartin\SyliusSubscriptionPlugin\Repository\SubscriptionRepositoryInterface;
use JpmMartin\SyliusSubscriptionPlugin\StateMachine\SubscriptionTransitions;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Customer\Context\CustomerContextInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * The customer cancels their subscription from their account. With deliveries already paid for, it is
 * cancelled once they are placed, and nothing more is charged meanwhile; otherwise at once. A
 * cancellation the subscription's state or its minimum commitment does not allow is refused.
 */
final class CancelSubscriptionAction
{
    /** @param SubscriptionRepositoryInterface<SubscriptionInterface> $subscriptionRepository */
    public function __construct(
        private readonly SubscriptionRepositoryInterface $subscriptionRepository,
        private readonly CustomerContextInterface $customerContext,
        private readonly StateMachineInterface $stateMachine,
        private readonly ObjectManager $subscriptionManager,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function __invoke(Request $request, string $id): RedirectResponse
    {
        $customer = $this->customerContext->getCustomer();
        $subscription = $this->subscriptionRepository->findOneByIdAndCustomer($id, $customer instanceof CustomerInterface ? $customer : null);
        if (!$subscription instanceof SubscriptionInterface) {
            throw new NotFoundHttpException('The subscription does not exist.');
        }

        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken((string) $subscription->getId(), $request->request->getString('_csrf_token')))) {
            throw new HttpException(403, 'Invalid CSRF token.');
        }

        if (
            $subscription->cancelsAfterPrepaidDeliveries() ||
            !$this->stateMachine->can($subscription, SubscriptionTransitions::GRAPH, SubscriptionTransitions::TRANSITION_CANCEL)
        ) {
            throw new BadRequestHttpException('This subscription cannot be cancelled now.');
        }

        if (0 < $subscription->getPrepaidDeliveriesLeft()) {
            $subscription->setCancelsAfterPrepaidDeliveries(true);
            $this->flash($request, 'jpm_martin_sylius_subscription.subscription.cancels_after_prepaid_deliveries');
        } else {
            $this->stateMachine->apply($subscription, SubscriptionTransitions::GRAPH, SubscriptionTransitions::TRANSITION_CANCEL);
            $this->flash($request, 'jpm_martin_sylius_subscription.subscription.cancelled');
        }
        $this->subscriptionManager->flush();

        return new RedirectResponse($this->urlGenerator->generate('jpm_martin_sylius_subscription_shop_account_subscription_show', ['id' => $subscription->getId()]));
    }

    private function flash(Request $request, string $message): void
    {
        $session = $request->getSession();
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add('success', $message);
        }
    }
}
