<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Form\Type;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use JpmMartin\SyliusSubscriptionPlugin\Management\SubscriptionFrequencyChangerInterface;
use JpmMartin\SyliusSubscriptionPlugin\Schedule\SubscriptionInterval;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

/** Only the frequencies the subscription can change to are offered, so any other is refused as an invalid choice. */
final class SubscriptionFrequencyChangeType extends AbstractType
{
    public function __construct(private readonly SubscriptionFrequencyChangerInterface $frequencyChanger)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var SubscriptionInterface $subscription */
        $subscription = $options['subscription'];

        $builder->add('frequency', ChoiceType::class, [
            'choices' => $this->frequencyChanger->frequenciesToChangeTo($subscription),
            'choice_value' => static fn (?SubscriptionInterval $interval): ?string => $interval?->key(),
            'choice_label' => static fn (SubscriptionInterval $interval): string => 'jpm_martin_sylius_subscription.ui.every.' . $interval->unit->value,
            'choice_translation_parameters' => static fn (SubscriptionInterval $interval): array => ['%count%' => $interval->count],
            'expanded' => true,
            'label' => 'jpm_martin_sylius_subscription.ui.new_frequency',
            'constraints' => [new NotBlank(groups: ['sylius'])],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setRequired('subscription')
            ->setAllowedTypes('subscription', SubscriptionInterface::class)
            ->setDefault('validation_groups', ['sylius'])
        ;
    }

    public function getBlockPrefix(): string
    {
        return 'jpm_martin_sylius_subscription_frequency_change';
    }
}
