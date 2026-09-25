<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Form\Type;

use JpmMartin\SyliusSubscriptionPlugin\Consent\SubscriptionConsentRecorder;
use JpmMartin\SyliusSubscriptionPlugin\Management\SubscriptionItemEditorInterface;
use JpmMartin\SyliusSubscriptionPlugin\Management\SubscriptionItemOffer;
use Sylius\Bundle\MoneyBundle\Formatter\MoneyFormatterInterface;
use Sylius\Component\Locale\Context\LocaleContextInterface;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotNull;
use Symfony\Component\Validator\Constraints\Range;

/**
 * The "Add a product" page: one of the variants that can be added, by product, with the price it would
 * renew at, and how many, with the consent: the action requires it when the product raises what each
 * renewal costs, which only a free one does not.
 */
final class SubscriptionItemAdditionType extends AbstractType
{
    public function __construct(
        private readonly SubscriptionItemEditorInterface $itemEditor,
        private readonly MoneyFormatterInterface $moneyFormatter,
        private readonly LocaleContextInterface $localeContext,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var string $currencyCode */
        $currencyCode = $options['currency_code'];

        $builder
            ->add('offer', ChoiceType::class, [
                'label' => 'sylius.ui.product',
                'choices' => $options['offers'],
                'choice_value' => static fn (?SubscriptionItemOffer $offer): ?string => $offer?->variant->getCode(),
                'choice_label' => fn (SubscriptionItemOffer $offer): string => \sprintf(
                    '%s · %s',
                    $offer->variant->getName() ?? $offer->variant->getProduct()?->getName() ?? $offer->variant->getCode(),
                    $this->moneyFormatter->format($offer->unitPrice, $currencyCode, $this->localeContext->getLocaleCode()),
                ),
                'group_by' => static fn (SubscriptionItemOffer $offer): string => (string) $offer->variant->getProduct()?->getName(),
                'choice_translation_domain' => false,
                'placeholder' => 'jpm_martin_sylius_subscription.ui.choose_product',
                'constraints' => [new NotNull(message: 'jpm_martin_sylius_subscription.subscription_item.product.not_blank', groups: ['sylius'])],
            ])
            ->add('quantity', IntegerType::class, [
                'label' => 'sylius.ui.quantity',
                'empty_data' => '0',
                'attr' => ['min' => 1, 'max' => $this->itemEditor->maxQuantity()],
                'constraints' => [new Range(
                    notInRangeMessage: 'jpm_martin_sylius_subscription.subscription_item.quantity.range',
                    min: 1,
                    max: $this->itemEditor->maxQuantity(),
                    groups: ['sylius'],
                )],
            ])
            ->add('consent', CheckboxType::class, [
                'label' => SubscriptionConsentRecorder::TEXT_KEY,
                'required' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setRequired(['offers', 'currency_code'])
            ->setAllowedTypes('offers', 'array')
            ->setAllowedTypes('currency_code', 'string')
            ->setDefault('validation_groups', ['sylius'])
        ;
    }

    public function getBlockPrefix(): string
    {
        return 'jpm_martin_sylius_subscription_item_addition';
    }
}
