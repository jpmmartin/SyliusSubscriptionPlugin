<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Controller;

use Doctrine\Persistence\ObjectManager;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use JpmMartin\SyliusSubscriptionPlugin\Form\Type\SubscriptionAddressChangeType;
use JpmMartin\SyliusSubscriptionPlugin\Management\ShippingMethodOffer;
use JpmMartin\SyliusSubscriptionPlugin\Management\SubscriptionAddressChangerInterface;
use JpmMartin\SyliusSubscriptionPlugin\Repository\SubscriptionRepositoryInterface;
use Sylius\Component\Core\Model\AddressInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\ShippingMethodInterface;
use Sylius\Component\Customer\Context\CustomerContextInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;
use Webmozart\Assert\Assert;

/**
 * Serves the customer's account and the admin alike, as the frequency change does: "customer_only"
 * limits it to the signed-in customer's own subscriptions, and "template" and "redirect_route" say where
 * to show the form and where to go once the addresses are changed. When the current shipping method does
 * not reach the new address, the form comes back with the methods that do and what each would cost, and
 * nothing is changed until one is chosen.
 */
final class ChangeSubscriptionAddressAction
{
    /** @param SubscriptionRepositoryInterface<SubscriptionInterface> $subscriptionRepository */
    public function __construct(
        private readonly SubscriptionRepositoryInterface $subscriptionRepository,
        private readonly CustomerContextInterface $customerContext,
        private readonly SubscriptionAddressChangerInterface $addressChanger,
        private readonly FormFactoryInterface $formFactory,
        private readonly ObjectManager $subscriptionManager,
        private readonly Environment $twig,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(Request $request, string $id): Response
    {
        $subscription = $this->findSubscription($request, $id);
        if (!$this->addressChanger->canChange($subscription)) {
            throw new NotFoundHttpException('The addresses of this subscription cannot be changed.');
        }

        $customer = $subscription->getCustomer();
        $addressBook = $customer instanceof CustomerInterface ? array_values($customer->getAddresses()->toArray()) : [];
        $form = $this->formFactory->create(SubscriptionAddressChangeType::class, null, ['address_book' => $addressBook]);
        $form->handleRequest($request);

        $offers = [];
        if ($form->isSubmitted() && $form->isValid()) {
            $shippingAddress = $this->shippingAddressOf($form);
            $billingAddress = true === $form->get('differentBillingAddress')->getData() ? $form->get('billingAddress')->getData() : null;
            Assert::nullOrIsInstanceOf($billingAddress, AddressInterface::class);

            $shippingMethod = null;
            $ready = true;
            if ($this->addressChanger->requiresShipping($subscription)) {
                $offers = $this->addressChanger->shippingMethodsFor($subscription, $shippingAddress);
                if ([] === $offers) {
                    $form->addError(new FormError($this->translator->trans('jpm_martin_sylius_subscription.ui.no_shipping_method_reaches')));
                    $ready = false;
                } elseif (!$this->reaches($subscription->getShippingMethod(), $offers)) {
                    $code = $form->get('shippingMethod')->getData();
                    $shippingMethod = $this->chosenAmong($offers, \is_string($code) ? $code : '');
                    $ready = null !== $shippingMethod;
                }
            }

            if ($ready) {
                $this->addressChanger->change($subscription, $shippingAddress, $billingAddress, $shippingMethod);
                $this->subscriptionManager->flush();

                $session = $request->getSession();
                if ($session instanceof FlashBagAwareSessionInterface) {
                    $session->getFlashBag()->add('success', 'jpm_martin_sylius_subscription.subscription.address_changed');
                }

                return new RedirectResponse($this->urlGenerator->generate(
                    $request->attributes->getString('redirect_route'),
                    ['id' => $subscription->getId()],
                ));
            }
        }

        return new Response(
            $this->twig->render($request->attributes->getString('template'), [
                'subscription' => $subscription,
                'resource' => $subscription,
                'form' => $form->createView(),
                'offers' => $offers,
            ]),
            $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK,
        );
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

    private function shippingAddressOf(FormInterface $form): AddressInterface
    {
        $address = $form->has('addressBookEntry') ? $form->get('addressBookEntry')->getData() : null;
        $address ??= $form->get('shippingAddress')->getData();
        Assert::isInstanceOf($address, AddressInterface::class);

        return $address;
    }

    /** @param list<ShippingMethodOffer> $offers */
    private function reaches(?ShippingMethodInterface $shippingMethod, array $offers): bool
    {
        foreach ($offers as $offer) {
            if ($offer->method === $shippingMethod) {
                return true;
            }
        }

        return false;
    }

    /** @param list<ShippingMethodOffer> $offers */
    private function chosenAmong(array $offers, string $code): ?ShippingMethodInterface
    {
        foreach ($offers as $offer) {
            if ('' !== $code && $offer->method->getCode() === $code) {
                return $offer->method;
            }
        }

        return null;
    }
}
