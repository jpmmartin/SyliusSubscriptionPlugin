<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Form\Type;

use JpmMartin\SyliusSubscriptionPlugin\Consent\SubscriptionConsentRecorder;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\CollectionType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The "Change items" page: an edit of each item the customer can change, and the consent, asked only
 * when the changes raise what each renewal costs: the action decides, so it is never required here.
 */
final class SubscriptionItemsChangeType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('items', CollectionType::class, [
                'entry_type' => SubscriptionItemEditType::class,
                'label' => false,
            ])
            ->add('consent', CheckboxType::class, [
                'label' => SubscriptionConsentRecorder::TEXT_KEY,
                'required' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefault('validation_groups', ['sylius']);
    }

    public function getBlockPrefix(): string
    {
        return 'jpm_martin_sylius_subscription_items_change';
    }
}
