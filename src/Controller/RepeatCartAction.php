<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Controller;

use Doctrine\Persistence\ObjectManager;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionFrequencyInterface;
use JpmMartin\SyliusSubscriptionPlugin\Frequency\CartRepeaterInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Order\Context\CartContextInterface;
use Sylius\Component\Order\Processor\OrderProcessorInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * "Repeat this cart" on the cart page: repeats the customer's cart with the frequency chosen, or stops
 * repeating it when none is. It is a form of its own, next to the cart's live form rather than
 * inside it, and goes back to the cart once the cart has been processed with the new choice.
 */
final class RepeatCartAction
{
    public const CSRF_TOKEN_ID = 'jpm_martin_sylius_subscription_repeat_cart';

    public const FIELD = 'subscription_frequency';

    public function __construct(
        private readonly CartContextInterface $cartContext,
        private readonly CartRepeaterInterface $cartRepeater,
        private readonly OrderProcessorInterface $orderProcessor,
        private readonly ObjectManager $orderManager,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function __invoke(Request $request): RedirectResponse
    {
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken(self::CSRF_TOKEN_ID, $request->request->getString('_csrf_token')))) {
            throw new HttpException(403, 'Invalid CSRF token.');
        }

        $response = new RedirectResponse($this->urlGenerator->generate('sylius_shop_cart_summary'));

        $cart = $this->cartContext->getCart();
        if (!$cart instanceof OrderInterface || null === $cart->getId() || $cart->isEmpty()) {
            return $response;
        }

        $code = $request->request->getString(self::FIELD);
        if ('' === $code) {
            $this->cartRepeater->stopRepeating($cart);
            $flash = ['success', 'jpm_martin_sylius_subscription.cart.not_repeated'];
        } else {
            $frequency = $this->findOffered($cart, $code);
            if (null === $frequency) {
                $this->flash($request, ['error', 'jpm_martin_sylius_subscription.cart.frequency_not_offered']);

                return $response;
            }

            $this->cartRepeater->repeat($cart, $frequency);
            $flash = ['success', 'jpm_martin_sylius_subscription.cart.repeated'];
        }

        $this->orderProcessor->process($cart);
        $this->orderManager->flush();
        $this->flash($request, $flash);

        return $response;
    }

    private function findOffered(OrderInterface $cart, string $code): ?SubscriptionFrequencyInterface
    {
        foreach ($this->cartRepeater->getOfferedFrequencies($cart) as $frequency) {
            if ($frequency->getCode() === $code) {
                return $frequency;
            }
        }

        return null;
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
