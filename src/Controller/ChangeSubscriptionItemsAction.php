<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Controller;

use Doctrine\Persistence\ObjectManager;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use JpmMartin\SyliusSubscriptionPlugin\Form\Type\SubscriptionItemsChangeType;
use JpmMartin\SyliusSubscriptionPlugin\Management\SubscriptionItemChanges;
use JpmMartin\SyliusSubscriptionPlugin\Management\SubscriptionItemEditorInterface;
use JpmMartin\SyliusSubscriptionPlugin\Repository\SubscriptionRepositoryInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Customer\Context\CustomerContextInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

/**
 * The customer's "Change items" page. When the changes raise what each renewal costs, the page comes
 * back with the new total and the consent, and saves nothing until the consent is accepted.
 */
final class ChangeSubscriptionItemsAction
{
    /** @param SubscriptionRepositoryInterface<SubscriptionInterface> $subscriptionRepository */
    public function __construct(
        private readonly SubscriptionRepositoryInterface $subscriptionRepository,
        private readonly CustomerContextInterface $customerContext,
        private readonly SubscriptionItemEditorInterface $itemEditor,
        private readonly FormFactoryInterface $formFactory,
        private readonly ObjectManager $subscriptionManager,
        private readonly Environment $twig,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(Request $request, string $id): Response
    {
        $customer = $this->customerContext->getCustomer();
        $subscription = $this->subscriptionRepository->findOneByIdAndCustomer($id, $customer instanceof CustomerInterface ? $customer : null);
        if (null === $subscription) {
            throw new NotFoundHttpException('The subscription does not exist.');
        }

        $editableItems = $this->itemEditor->editableItems($subscription);
        if ([] === $editableItems) {
            throw new NotFoundHttpException('The items of this subscription cannot be changed now.');
        }

        $changes = new SubscriptionItemChanges();
        foreach ($editableItems as $item) {
            $changes->edit($item);
        }
        $form = $this->formFactory->create(SubscriptionItemsChangeType::class, ['items' => $changes->edits(), 'consent' => false]);
        $form->handleRequest($request);

        $newRenewalTotal = null;
        $consentRequired = false;
        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $newRenewalTotal = $this->itemEditor->renewalTotalAfter($subscription, $changes);
                $consentRequired = $newRenewalTotal > $subscription->getRenewalTotal();

                if ($consentRequired && true !== $form->get('consent')->getData()) {
                    $form->get('consent')->addError(new FormError($this->translator->trans('jpm_martin_sylius_subscription.subscription_item.consent_required', [], 'validators')));
                } else {
                    $changed = $this->itemEditor->apply($subscription, $changes, $consentRequired ? $request->getLocale() : null);
                    $this->subscriptionManager->flush();

                    return $this->redirectToSubscription($request, $subscription, $changed ? 'items_changed' : 'items_unchanged');
                }
            } catch (\InvalidArgumentException) {
                $form->addError(new FormError($this->translator->trans('jpm_martin_sylius_subscription.subscription_item.not_offered', [], 'validators')));
            }
        }

        return new Response(
            $this->twig->render($request->attributes->getString('template'), [
                'subscription' => $subscription,
                'resource' => $subscription,
                'form' => $form->createView(),
                'renewal_total' => $subscription->getRenewalTotal(),
                'new_renewal_total' => $newRenewalTotal,
                'consent_required' => $consentRequired,
            ]),
            $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK,
        );
    }

    private function redirectToSubscription(Request $request, SubscriptionInterface $subscription, string $flash): RedirectResponse
    {
        $session = $request->getSession();
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add('items_changed' === $flash ? 'success' : 'info', 'jpm_martin_sylius_subscription.subscription.' . $flash);
        }

        return new RedirectResponse($this->urlGenerator->generate(
            $request->attributes->getString('redirect_route'),
            ['id' => $subscription->getId()],
        ));
    }
}
