<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Controller;

use Doctrine\Persistence\ObjectManager;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionFrequencyInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionPlanInterface;
use JpmMartin\SyliusSubscriptionPlugin\Frequency\CartItemSubscriptionChangerInterface;
use JpmMartin\SyliusSubscriptionPlugin\Repository\SubscriptionFrequencyRepositoryInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Order\Context\CartContextInterface;
use Sylius\Component\Order\Processor\OrderProcessorInterface;
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
 * The per-line dropdown on the cart page (choose-subscription-per-cart-line, D5): changes one line's subscription
 * to buying it once, one of the store's frequencies or one of its variant's plans. It is a form of its own, next
 * to the cart's live form rather than inside it, exactly like "repeat this cart" (RepeatCartAction), and goes back
 * to the cart once it has been processed with the new choice.
 */
final class ChangeCartItemSubscriptionTermsAction
{
    public const CSRF_TOKEN_ID = 'jpm_martin_sylius_subscription_cart_item_subscription_terms';

    public const FIELD = 'subscription_terms';

    /**
     * @param RepositoryInterface<SubscriptionPlanInterface> $subscriptionPlanRepository
     * @param SubscriptionFrequencyRepositoryInterface<SubscriptionFrequencyInterface> $subscriptionFrequencyRepository
     */
    public function __construct(
        private readonly CartContextInterface $cartContext,
        private readonly CartItemSubscriptionChangerInterface $changer,
        private readonly OrderProcessorInterface $orderProcessor,
        private readonly ObjectManager $orderManager,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly RepositoryInterface $subscriptionPlanRepository,
        private readonly SubscriptionFrequencyRepositoryInterface $subscriptionFrequencyRepository,
    ) {
    }

    public function __invoke(Request $request, int $id): RedirectResponse
    {
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(self::CSRF_TOKEN_ID, $request->request->getString('_csrf_token')))) {
            throw new HttpException(403, 'Invalid CSRF token.');
        }

        $response = new RedirectResponse($this->urlGenerator->generate('sylius_shop_cart_summary'));

        $cart = $this->cartContext->getCart();
        if (!$cart instanceof OrderInterface) {
            throw new NotFoundHttpException('Cart not found.');
        }

        $item = $this->findItem($cart, $id);
        if (null === $item) {
            throw new NotFoundHttpException('Cart item not found.');
        }

        $terms = $this->resolve($request->request->getString(self::FIELD));
        if (false === $terms) {
            $this->flash($request, ['error', 'jpm_martin_sylius_subscription.cart.subscription_option_not_offered']);

            return $response;
        }
        [$plan, $frequency] = $terms;

        try {
            $this->changer->change($item, $plan, $frequency);
        } catch (\InvalidArgumentException) {
            $this->flash($request, ['error', 'jpm_martin_sylius_subscription.cart.subscription_option_not_offered']);

            return $response;
        }

        $this->orderProcessor->process($cart);
        $this->orderManager->flush();
        $this->flash($request, ['success', 'jpm_martin_sylius_subscription.cart.item_subscription_changed']);

        return $response;
    }

    private function findItem(OrderInterface $cart, int $id): ?OrderItemInterface
    {
        foreach ($cart->getItems() as $item) {
            if ($item->getId() === $id) {
                return $item;
            }
        }

        return null;
    }

    /**
     * "", "plan:<code>" or "frequency:<code>" (CartItemSubscriptionOption::value()), resolved to entities. A code
     * that does not resolve is reported exactly like an option the line does not have: false.
     *
     * @return array{0: ?SubscriptionPlanInterface, 1: ?SubscriptionFrequencyInterface}|false
     */
    private function resolve(string $value): array|false
    {
        if ('' === $value) {
            return [null, null];
        }

        if (str_starts_with($value, 'plan:')) {
            $plan = $this->subscriptionPlanRepository->findOneBy(['code' => substr($value, \strlen('plan:'))]);

            return $plan instanceof SubscriptionPlanInterface ? [$plan, null] : false;
        }

        if (str_starts_with($value, 'frequency:')) {
            $frequency = $this->subscriptionFrequencyRepository->findOneBy(['code' => substr($value, \strlen('frequency:'))]);

            return $frequency instanceof SubscriptionFrequencyInterface ? [null, $frequency] : false;
        }

        return false;
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
