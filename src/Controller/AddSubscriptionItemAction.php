<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Controller;

use Doctrine\Persistence\ObjectManager;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use JpmMartin\SyliusSubscriptionPlugin\Form\Type\SubscriptionItemAdditionType;
use JpmMartin\SyliusSubscriptionPlugin\Management\SubscriptionItemChanges;
use JpmMartin\SyliusSubscriptionPlugin\Management\SubscriptionItemEditorInterface;
use JpmMartin\SyliusSubscriptionPlugin\Management\SubscriptionItemOffer;
use JpmMartin\SyliusSubscriptionPlugin\Repository\SubscriptionRepositoryInterface;
use Sylius\Component\Core\Model\CustomerInterface;
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
 * The customer's "Add a product" page, which asks for the consent in the same step: it is required
 * unless the product leaves what each renewal costs as it was.
 */
final class AddSubscriptionItemAction
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

        $offers = $this->itemEditor->variantsToAdd($subscription);
        if ([] === $offers) {
            throw new NotFoundHttpException('Nothing can be added to this subscription now.');
        }

        $form = $this->formFactory->create(
            SubscriptionItemAdditionType::class,
            ['offer' => null, 'quantity' => 1, 'consent' => false],
            ['offers' => $offers, 'currency_code' => (string) $subscription->getCurrencyCode()],
        );
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $offer = $form->get('offer')->getData();
            $quantity = $form->get('quantity')->getData();
            Assert::isInstanceOf($offer, SubscriptionItemOffer::class);
            Assert::integer($quantity);

            $changes = new SubscriptionItemChanges();
            $changes->add($offer->variant, $quantity);

            try {
                $consentRequired = $this->itemEditor->requiresConsent($subscription, $changes);
                if ($consentRequired && true !== $form->get('consent')->getData()) {
                    $form->get('consent')->addError(new FormError($this->translator->trans('jpm_martin_sylius_subscription.subscription_item.consent_required', [], 'validators')));

                    return $this->render($request, $subscription, $form);
                }

                $this->itemEditor->apply($subscription, $changes, $consentRequired ? $request->getLocale() : null);
                $this->subscriptionManager->flush();

                $session = $request->getSession();
                if ($session instanceof FlashBagAwareSessionInterface) {
                    $session->getFlashBag()->add('success', 'jpm_martin_sylius_subscription.subscription.item_added');
                }

                return new RedirectResponse($this->urlGenerator->generate(
                    $request->attributes->getString('redirect_route'),
                    ['id' => $subscription->getId()],
                ));
            } catch (\InvalidArgumentException) {
                $form->addError(new FormError($this->translator->trans('jpm_martin_sylius_subscription.subscription_item.not_offered', [], 'validators')));
            }
        }

        return $this->render($request, $subscription, $form);
    }

    private function render(Request $request, SubscriptionInterface $subscription, FormInterface $form): Response
    {
        return new Response(
            $this->twig->render($request->attributes->getString('template'), [
                'subscription' => $subscription,
                'resource' => $subscription,
                'form' => $form->createView(),
            ]),
            $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK,
        );
    }
}
