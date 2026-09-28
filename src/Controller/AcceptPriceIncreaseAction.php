<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Controller;

use Doctrine\Persistence\ObjectManager;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use JpmMartin\SyliusSubscriptionPlugin\Pricing\PendingPriceApplier;
use JpmMartin\SyliusSubscriptionPlugin\Repository\SubscriptionRepositoryInterface;
use JpmMartin\SyliusSubscriptionPlugin\StateMachine\SubscriptionTransitions;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Customer\Context\CustomerContextInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * The customer accepts the price increase pending on their subscription, and resumes it when it was
 * paused for want of that acceptance and they ask to.
 */
final class AcceptPriceIncreaseAction
{
    /** @param SubscriptionRepositoryInterface<SubscriptionInterface> $subscriptionRepository */
    public function __construct(
        private readonly SubscriptionRepositoryInterface $subscriptionRepository,
        private readonly CustomerContextInterface $customerContext,
        private readonly PendingPriceApplier $pendingPriceApplier,
        private readonly StateMachineInterface $stateMachine,
        private readonly ObjectManager $subscriptionManager,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
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

        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken('jpm_martin_sylius_subscription_accept_price_increase_' . $subscription->getId(), $request->request->getString('_csrf_token')))) {
            throw new AccessDeniedHttpException('Invalid CSRF token.');
        }

        $flash = ['error', 'jpm_martin_sylius_subscription.subscription.price_increase_not_pending'];
        if ($this->pendingPriceApplier->awaitsAcceptance($subscription)) {
            $this->pendingPriceApplier->accept($subscription);
            $flash = ['success', 'jpm_martin_sylius_subscription.subscription.price_increase_accepted'];

            if ($request->request->getBoolean('resume') && $this->stateMachine->can($subscription, SubscriptionTransitions::GRAPH, SubscriptionTransitions::TRANSITION_RESUME)) {
                $this->stateMachine->apply($subscription, SubscriptionTransitions::GRAPH, SubscriptionTransitions::TRANSITION_RESUME);
                $flash = ['success', 'jpm_martin_sylius_subscription.subscription.price_increase_accepted_and_resumed'];
            }
            $this->subscriptionManager->flush();
        }

        $session = $request->getSession();
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add(...$flash);
        }

        return new RedirectResponse($this->urlGenerator->generate('jpm_martin_sylius_subscription_shop_account_subscription_show', ['id' => $subscription->getId()]));
    }
}
