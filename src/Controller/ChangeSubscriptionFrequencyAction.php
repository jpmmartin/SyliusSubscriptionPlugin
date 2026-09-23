<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Controller;

use Doctrine\Persistence\ObjectManager;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use JpmMartin\SyliusSubscriptionPlugin\Form\Type\SubscriptionFrequencyChangeType;
use JpmMartin\SyliusSubscriptionPlugin\Management\SubscriptionFrequencyChangerInterface;
use JpmMartin\SyliusSubscriptionPlugin\Repository\SubscriptionRepositoryInterface;
use JpmMartin\SyliusSubscriptionPlugin\Schedule\SubscriptionInterval;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Customer\Context\CustomerContextInterface;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;
use Webmozart\Assert\Assert;

/**
 * Serves the customer's account and the admin alike. The route says which: "customer_only" limits it
 * to the signed-in customer's own subscriptions, so anyone else's is not found; "template" and
 * "redirect_route" say where to show the form and where to go once the frequency is changed.
 */
final class ChangeSubscriptionFrequencyAction
{
    /** @param SubscriptionRepositoryInterface<SubscriptionInterface> $subscriptionRepository */
    public function __construct(
        private readonly SubscriptionRepositoryInterface $subscriptionRepository,
        private readonly CustomerContextInterface $customerContext,
        private readonly SubscriptionFrequencyChangerInterface $frequencyChanger,
        private readonly FormFactoryInterface $formFactory,
        private readonly ObjectManager $subscriptionManager,
        private readonly Environment $twig,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function __invoke(Request $request, string $id): Response
    {
        $subscription = $this->findSubscription($request, $id);

        $form = $this->formFactory->create(SubscriptionFrequencyChangeType::class, null, ['subscription' => $subscription]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $frequency = $form->get('frequency')->getData();
            Assert::isInstanceOf($frequency, SubscriptionInterval::class);
            $this->frequencyChanger->change($subscription, $frequency);
            $this->subscriptionManager->flush();

            $session = $request->getSession();
            if ($session instanceof FlashBagAwareSessionInterface) {
                $session->getFlashBag()->add('success', 'jpm_martin_sylius_subscription.subscription.frequency_changed');
            }

            return new RedirectResponse($this->urlGenerator->generate(
                $request->attributes->getString('redirect_route'),
                ['id' => $subscription->getId()],
            ));
        }

        return new Response(
            $this->twig->render($request->attributes->getString('template'), [
                'subscription' => $subscription,
                'resource' => $subscription,
                'form' => $form->createView(),
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
}
