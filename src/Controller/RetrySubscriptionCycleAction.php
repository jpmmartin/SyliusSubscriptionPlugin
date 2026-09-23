<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Controller;

use Doctrine\Persistence\ObjectManager;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionCycleInterface;
use JpmMartin\SyliusSubscriptionPlugin\Management\SubscriptionCycleRetrierInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * The admin's retry of a failed cycle. The cycle is found within its subscription, so a cycle id taken
 * from another subscription's page is not found; the flash says what came of the charge.
 */
final class RetrySubscriptionCycleAction
{
    /** @param RepositoryInterface<SubscriptionCycleInterface> $cycleRepository */
    public function __construct(
        private readonly RepositoryInterface $cycleRepository,
        private readonly SubscriptionCycleRetrierInterface $cycleRetrier,
        private readonly ObjectManager $cycleManager,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function __invoke(Request $request, string $subscriptionId, string $id): RedirectResponse
    {
        $cycle = $this->cycleRepository->findOneBy(['id' => (int) $id, 'subscription' => (int) $subscriptionId]);
        if (!$cycle instanceof SubscriptionCycleInterface) {
            throw new NotFoundHttpException('The subscription cycle does not exist.');
        }

        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken('jpm_martin_sylius_subscription_cycle_retry_' . $cycle->getId(), $request->request->getString('_csrf_token')))) {
            throw new HttpException(403, 'Invalid CSRF token.');
        }

        if ($this->cycleRetrier->canRetry($cycle)) {
            $this->cycleRetrier->retry($cycle);
            $this->cycleManager->flush();
            $this->flash($request, match ($cycle->getState()) {
                SubscriptionCycleInterface::STATE_PAID => ['success', 'jpm_martin_sylius_subscription.subscription_cycle.retry_paid'],
                SubscriptionCycleInterface::STATE_FAILED => ['error', 'jpm_martin_sylius_subscription.subscription_cycle.retry_failed'],
                default => ['info', 'jpm_martin_sylius_subscription.subscription_cycle.retry_pending'],
            });
        } else {
            $this->flash($request, ['error', 'jpm_martin_sylius_subscription.subscription_cycle.retry_not_allowed']);
        }

        return new RedirectResponse($this->urlGenerator->generate('jpm_martin_sylius_subscription_admin_subscription_show', ['id' => $subscriptionId]));
    }

    /** @param array{string, string} $flash */
    private function flash(Request $request, array $flash): void
    {
        $session = $request->getSession();
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add(...$flash);
        }
    }
}
