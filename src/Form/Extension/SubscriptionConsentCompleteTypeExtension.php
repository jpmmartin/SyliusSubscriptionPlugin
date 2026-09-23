<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Form\Extension;

use JpmMartin\SyliusSubscriptionPlugin\Consent\SubscriptionConsentRecorder;
use JpmMartin\SyliusSubscriptionPlugin\Consent\SubscriptionConsentRecorderInterface;
use JpmMartin\SyliusSubscriptionPlugin\Order\SubscriptionLines;
use Sylius\Bundle\CoreBundle\Form\Type\Checkout\CompleteType;
use Sylius\Component\Core\Model\OrderInterface;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;

/**
 * The recurring-charge consent on the last checkout step, shown only when the order has
 * subscription lines. Ticking it records the acceptance before the order is validated, which is
 * where the plugin refuses to complete an order with subscriptions and no consent.
 */
final class SubscriptionConsentCompleteTypeExtension extends AbstractTypeExtension
{
    public const FIELD = 'subscriptionConsent';

    public function __construct(private readonly SubscriptionConsentRecorderInterface $consentRecorder)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event): void {
            $order = $event->getData();
            if (!$order instanceof OrderInterface || !SubscriptionLines::existIn($order)) {
                return;
            }

            $event->getForm()->add(self::FIELD, CheckboxType::class, [
                'mapped' => false,
                'required' => false,
                'label' => SubscriptionConsentRecorder::TEXT_KEY,
                'data' => $this->consentRecorder->isGivenFor($order),
            ]);
        });

        // Before Symfony's validation listener (priority 0), so the order is validated with it.
        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event): void {
            $form = $event->getForm();
            $order = $form->getData();
            if (!$form->has(self::FIELD) || !$order instanceof OrderInterface) {
                return;
            }

            if (true === $form->get(self::FIELD)->getData()) {
                $this->consentRecorder->record($order);
            }
        }, 10);
    }

    public static function getExtendedTypes(): iterable
    {
        return [CompleteType::class];
    }
}
