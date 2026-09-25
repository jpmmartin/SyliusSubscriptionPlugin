<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Form\Type;

use JpmMartin\SyliusSubscriptionPlugin\Management\SubscriptionItemEdit;
use JpmMartin\SyliusSubscriptionPlugin\Management\SubscriptionItemEditorInterface;
use JpmMartin\SyliusSubscriptionPlugin\Management\SubscriptionItemOffer;
use Sylius\Bundle\MoneyBundle\Formatter\MoneyFormatterInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Locale\Context\LocaleContextInterface;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Range;

/**
 * One item of the "Change items" page: its quantity, the variants it can move to, when there are any,
 * and whether to remove it, when it can be. Each variant shows the unit price it would renew at.
 */
final class SubscriptionItemEditType extends AbstractType
{
    public function __construct(
        private readonly SubscriptionItemEditorInterface $itemEditor,
        private readonly MoneyFormatterInterface $moneyFormatter,
        private readonly LocaleContextInterface $localeContext,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('quantity', IntegerType::class, [
            'label' => 'sylius.ui.quantity',
            // An emptied field fails the range instead of the type of the edit's quantity.
            'empty_data' => '0',
            'attr' => ['min' => 1, 'max' => $this->itemEditor->maxQuantity()],
            'constraints' => [new Range(
                notInRangeMessage: 'jpm_martin_sylius_subscription.subscription_item.quantity.range',
                min: 1,
                max: $this->itemEditor->maxQuantity(),
                groups: ['sylius'],
            )],
        ]);

        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event): void {
            $edit = $event->getData();
            if (!$edit instanceof SubscriptionItemEdit) {
                return;
            }

            $offers = $this->itemEditor->variantsFor($edit->item);
            if ([] !== $offers) {
                $unitPrices = [spl_object_id($edit->variant) => $edit->item->getUnitPrice()];
                foreach ($offers as $offer) {
                    $unitPrices[spl_object_id($offer->variant)] = $offer->unitPrice;
                }
                $currencyCode = (string) $edit->item->getSubscription()?->getCurrencyCode();

                $event->getForm()->add('variant', ChoiceType::class, [
                    'label' => 'sylius.ui.variant',
                    'choices' => [$edit->variant, ...array_map(static fn (SubscriptionItemOffer $offer): ProductVariantInterface => $offer->variant, $offers)],
                    'choice_value' => static fn (?ProductVariantInterface $variant): ?string => $variant?->getCode(),
                    'choice_label' => fn (ProductVariantInterface $variant): string => \sprintf(
                        '%s · %s',
                        $variant->getName() ?? $variant->getProduct()?->getName() ?? $variant->getCode(),
                        $this->moneyFormatter->format($unitPrices[spl_object_id($variant)], $currencyCode, $this->localeContext->getLocaleCode()),
                    ),
                    'choice_translation_domain' => false,
                ]);
            }

            if ($this->itemEditor->canRemove($edit->item)) {
                $event->getForm()->add('removed', CheckboxType::class, [
                    'label' => 'jpm_martin_sylius_subscription.ui.remove',
                    'required' => false,
                ]);
            }
        });
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefault('data_class', SubscriptionItemEdit::class);
        // The edit exists before the form: an empty one is never built.
        $resolver->setDefault('empty_data', null);
    }

    public function getBlockPrefix(): string
    {
        return 'jpm_martin_sylius_subscription_item_edit';
    }
}
