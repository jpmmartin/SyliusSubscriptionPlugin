<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Form\Type;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionIntervalUnit;
use Sylius\Bundle\ChannelBundle\Form\Type\ChannelChoiceType;
use Sylius\Bundle\ResourceBundle\Form\EventSubscriber\AddCodeFormSubscriber;
use Sylius\Bundle\ResourceBundle\Form\Type\AbstractResourceType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;

/** The admin form of one of the store's frequencies: a plan's fields, and the channels it is offered in. */
final class SubscriptionFrequencyType extends AbstractResourceType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            // Editable while creating, locked afterwards: the code identifies the frequency to integrations.
            ->addEventSubscriber(new AddCodeFormSubscriber(null, ['label' => 'sylius.ui.code']))
            ->add('name', TextType::class, [
                'label' => 'sylius.ui.name',
            ])
            // Blank integers reach validation as 0, with the message the administrator needs.
            ->add('intervalCount', IntegerType::class, [
                'label' => 'jpm_martin_sylius_subscription.form.subscription_plan.interval_count',
                'empty_data' => '0',
                'attr' => ['min' => 1],
            ])
            ->add('intervalUnit', EnumType::class, [
                'class' => SubscriptionIntervalUnit::class,
                'label' => 'jpm_martin_sylius_subscription.form.subscription_plan.interval_unit',
                'choice_label' => static fn (SubscriptionIntervalUnit $unit): string => 'jpm_martin_sylius_subscription.ui.interval_unit.' . $unit->value,
            ])
            ->add('discountPercentage', IntegerType::class, [
                'label' => 'jpm_martin_sylius_subscription.form.subscription_plan.discount_percentage',
                'empty_data' => '0',
                'attr' => ['min' => 0, 'max' => 100],
            ])
            ->add('maxCycles', IntegerType::class, [
                'label' => 'jpm_martin_sylius_subscription.form.subscription_plan.max_cycles',
                'help' => 'jpm_martin_sylius_subscription.form.subscription_plan.max_cycles_help',
                'required' => false,
                'attr' => ['min' => 1],
            ])
            ->add('channels', ChannelChoiceType::class, [
                'multiple' => true,
                'expanded' => true,
                'label' => 'sylius.ui.channels',
            ])
            ->add('enabled', CheckboxType::class, [
                'label' => 'sylius.ui.enabled',
                'required' => false,
            ])
        ;
    }

    public function getBlockPrefix(): string
    {
        return 'jpm_martin_sylius_subscription_subscription_frequency';
    }
}
