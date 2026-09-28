<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Controller;

use JpmMartin\SyliusSubscriptionPlugin\Command\UpdateSubscriptionPrices;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionFrequencyInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionPlanInterface;
use JpmMartin\SyliusSubscriptionPlugin\Pricing\PriceUpdateTarget;
use JpmMartin\SyliusSubscriptionPlugin\Pricing\SubscriptionPriceUpdaterInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

/**
 * "Update subscription prices" of a plan, a store frequency or a variant: the preview counts what the
 * update would do, and confirming it sends one UpdateSubscriptionPrices per subscription, each in its
 * own transaction, or queued when the store routes the message to a transport.
 */
final class UpdateSubscriptionPricesAction
{
    /**
     * @param RepositoryInterface<SubscriptionPlanInterface> $planRepository
     * @param RepositoryInterface<SubscriptionFrequencyInterface> $frequencyRepository
     * @param RepositoryInterface<ProductVariantInterface> $variantRepository
     */
    public function __construct(
        private readonly RepositoryInterface $planRepository,
        private readonly RepositoryInterface $frequencyRepository,
        private readonly RepositoryInterface $variantRepository,
        private readonly SubscriptionPriceUpdaterInterface $priceUpdater,
        private readonly MessageBusInterface $commandBus,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly Environment $twig,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly int $noticeDays,
    ) {
    }

    public function __invoke(Request $request, string $target, int $id): Response
    {
        [$subject, $name, $backUrl] = $this->resolve($target, $id);
        $priceUpdateTarget = new PriceUpdateTarget($target, $id);
        $preview = $this->priceUpdater->preview($priceUpdateTarget);
        $tokenId = \sprintf('jpm_martin_sylius_subscription_price_update_%s_%d', $target, $id);

        if ($request->isMethod(Request::METHOD_POST)) {
            if (!$this->csrfTokenManager->isTokenValid(new CsrfToken($tokenId, $request->request->getString('_csrf_token')))) {
                throw new AccessDeniedHttpException('Invalid CSRF token.');
            }

            foreach ($preview->subscriptionIds as $subscriptionId) {
                $this->commandBus->dispatch(new UpdateSubscriptionPrices($subscriptionId, $target, $id));
            }

            $session = $request->getSession();
            if ($session instanceof FlashBagAwareSessionInterface) {
                $session->getFlashBag()->add('success', [
                    'message' => 'jpm_martin_sylius_subscription.subscription.prices_updated',
                    'parameters' => ['%count%' => $preview->total()],
                ]);
            }

            return new RedirectResponse($backUrl);
        }

        return new Response($this->twig->render('@JpmMartinSyliusSubscriptionPlugin/admin/subscription_price_update.html.twig', [
            'resource' => $subject,
            'target' => $target,
            'target_id' => $id,
            'target_name' => $name,
            'preview' => $preview,
            'notice_days' => $this->noticeDays,
            'csrf_token_id' => $tokenId,
            'back_url' => $backUrl,
        ]));
    }

    /** @return array{object, string, string} the plan, frequency or variant, its name, and the page to go back to */
    private function resolve(string $target, int $id): array
    {
        if (PriceUpdateTarget::PLAN === $target) {
            $plan = $this->planRepository->find($id);
            $variant = $plan?->getProductVariant();
            if (null === $plan || null === $variant) {
                throw new NotFoundHttpException('The subscription plan does not exist.');
            }

            return [$plan, (string) $plan->getName(), $this->urlGenerator->generate('jpm_martin_sylius_subscription_admin_subscription_plan_update', [
                'productId' => $variant->getProduct()?->getId(),
                'variantId' => $variant->getId(),
                'id' => $plan->getId(),
            ])];
        }

        if (PriceUpdateTarget::FREQUENCY === $target) {
            $frequency = $this->frequencyRepository->find($id);
            if (null === $frequency) {
                throw new NotFoundHttpException('The subscription frequency does not exist.');
            }

            return [$frequency, (string) $frequency->getName(), $this->urlGenerator->generate('jpm_martin_sylius_subscription_admin_subscription_frequency_update', ['id' => $frequency->getId()])];
        }

        $variant = $this->variantRepository->find($id);
        if (null === $variant) {
            throw new NotFoundHttpException('The product variant does not exist.');
        }

        return [$variant, (string) ($variant->getName() ?? $variant->getProduct()?->getName() ?? $variant->getCode()), $this->urlGenerator->generate('sylius_admin_product_variant_update', [
            'productId' => $variant->getProduct()?->getId(),
            'id' => $variant->getId(),
        ])];
    }
}
