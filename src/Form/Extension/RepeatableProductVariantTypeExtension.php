<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Form\Extension;

use JpmMartin\SyliusSubscriptionPlugin\Frequency\RepeatableVariantsInterface;
use Sylius\Bundle\AdminBundle\Form\Type\ProductVariantType;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;

/**
 * The "can be repeated" switch of a variant, shown on the variant's subscription tab. It is not
 * mapped: the mark lives in a table of the plugin, so the store's variant stays as it is. A new
 * variant gets no switch, as the tab only exists once the variant is saved.
 */
final class RepeatableProductVariantTypeExtension extends AbstractTypeExtension
{
    public const FIELD = 'subscriptionRepeatable';

    public function __construct(private readonly RepeatableVariantsInterface $repeatableVariants)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event): void {
            $productVariant = $event->getData();
            if (!$productVariant instanceof ProductVariantInterface || null === $productVariant->getId()) {
                return;
            }

            $event->getForm()->add(self::FIELD, CheckboxType::class, [
                'mapped' => false,
                'required' => false,
                'label' => 'jpm_martin_sylius_subscription.form.product_variant.repeatable',
                'help' => 'jpm_martin_sylius_subscription.form.product_variant.repeatable_help',
                'data' => $this->repeatableVariants->isRepeatable($productVariant),
            ]);
        });

        // After Symfony's validation listener (priority 0), so only a valid form changes the mark,
        // which the variant's save then flushes. The admin's live form re-renders submit the form
        // too, but they never flush.
        $builder->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event): void {
            $form = $event->getForm();
            $productVariant = $form->getData();
            if (!$form->has(self::FIELD) || !$productVariant instanceof ProductVariantInterface || !$form->isValid()) {
                return;
            }

            $this->repeatableVariants->markRepeatable($productVariant, true === $form->get(self::FIELD)->getData());
        }, -10);
    }

    public static function getExtendedTypes(): iterable
    {
        return [ProductVariantType::class];
    }
}
