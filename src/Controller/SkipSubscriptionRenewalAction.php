<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Controller;

use Doctrine\ORM\OptimisticLockException;
use JpmMartin\SyliusSubscriptionPlugin\Command\SkipSubscriptionRenewal;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use JpmMartin\SyliusSubscriptionPlugin\Management\SubscriptionRenewalSkipperInterface;
use JpmMartin\SyliusSubscriptionPlugin\Repository\SubscriptionRepositoryInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Customer\Context\CustomerContextInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Serves the customer's account and the admin alike, like the frequency change: "customer_only" limits
 * it to the signed-in customer's own subscriptions, and "redirect_route" says where to go back to. The
 * skip goes through sylius.command_bus, so a cycle changed meanwhile by the cycles command rolls it back
 * whole, event included, and the customer is asked to try again; a renewal that stopped being skippable
 * meanwhile is reported as not skippable.
 */
final class SkipSubscriptionRenewalAction
{
    public const CSRF_TOKEN_PREFIX = 'jpm_martin_sylius_subscription_skip_renewal_';

    /** @param SubscriptionRepositoryInterface<SubscriptionInterface> $subscriptionRepository */
    public function __construct(
        private readonly SubscriptionRepositoryInterface $subscriptionRepository,
        private readonly CustomerContextInterface $customerContext,
        private readonly SubscriptionRenewalSkipperInterface $skipper,
        private readonly MessageBusInterface $commandBus,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function __invoke(Request $request, string $id): RedirectResponse
    {
        $subscription = $this->findSubscription($request, $id);
        $subscriptionId = $subscription->getId();

        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(self::CSRF_TOKEN_PREFIX . $subscriptionId, $request->request->getString('_csrf_token')))) {
            throw new HttpException(403, 'Invalid CSRF token.');
        }

        if (null === $subscriptionId || !$this->skipper->canSkip($subscription)) {
            $this->flash($request, 'error', 'jpm_martin_sylius_subscription.subscription_cycle.skip_not_allowed');
        } else {
            try {
                $this->commandBus->dispatch(new SkipSubscriptionRenewal($subscriptionId));
                $this->flash($request, 'success', 'jpm_martin_sylius_subscription.subscription_cycle.skipped');
            } catch (OptimisticLockException) {
                // Thrown by the bus's transaction when it stores the skip, after the handler ran.
                $this->flash($request, 'error', 'jpm_martin_sylius_subscription.subscription_cycle.skip_conflict');
            } catch (HandlerFailedException $exception) {
                // The renewal stopped being skippable between the check above and the handler.
                if (!$this->wraps($exception, \InvalidArgumentException::class)) {
                    throw $exception;
                }
                $this->flash($request, 'error', 'jpm_martin_sylius_subscription.subscription_cycle.skip_not_allowed');
            }
        }

        return new RedirectResponse($this->urlGenerator->generate($request->attributes->getString('redirect_route'), ['id' => $subscriptionId]));
    }

    private function findSubscription(Request $request, string $id): SubscriptionInterface
    {
        if ($request->attributes->getBoolean('customer_only')) {
            $customer = $this->customerContext->getCustomer();
            $subscription = $this->subscriptionRepository->findOneByIdAndCustomer($id, $customer instanceof CustomerInterface ? $customer : null);
        } else {
            $subscription = $this->subscriptionRepository->find($id);
        }

        if (!$subscription instanceof SubscriptionInterface) {
            throw new NotFoundHttpException('The subscription does not exist.');
        }

        return $subscription;
    }

    /** @param class-string<\Throwable> $class */
    private function wraps(HandlerFailedException $exception, string $class): bool
    {
        foreach ($exception->getWrappedExceptions() as $wrapped) {
            if ($wrapped instanceof $class) {
                return true;
            }
        }

        return false;
    }

    private function flash(Request $request, string $type, string $message): void
    {
        $session = $request->getSession();
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add($type, $message);
        }
    }
}
