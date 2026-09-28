<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Form;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionFrequencyInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionPlanInterface;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * The way into a plan or a frequency, as the admin forms ask for it: none, an introductory price for the
 * first order only or for the first N cycles, with its discount, or a free trial of N days. Each choice
 * clears what the others would have kept, and the first order only lasts one cycle, whatever the cycles
 * field says.
 */
final class IntroductoryPriceFields
{
    public const FIELD = 'introductoryPrice';

    public const NONE = 'none';

    public const FIRST_ORDER = 'first_order';

    public const FIRST_CYCLES = 'first_cycles';

    public const FREE_TRIAL = 'free_trial';

    /** @param list<string> $validationGroups those of the form, in which the discount is required unless none is chosen */
    public static function addTo(FormBuilderInterface $builder, array $validationGroups): void
    {
        $builder
            ->add(self::FIELD, ChoiceType::class, [
                'mapped' => false,
                'label' => 'jpm_martin_sylius_subscription.form.subscription_plan.introductory_price',
                'choices' => [
                    'jpm_martin_sylius_subscription.form.subscription_plan.introductory_price_none' => self::NONE,
                    'jpm_martin_sylius_subscription.form.subscription_plan.introductory_price_first_order' => self::FIRST_ORDER,
                    'jpm_martin_sylius_subscription.form.subscription_plan.introductory_price_first_cycles' => self::FIRST_CYCLES,
                    'jpm_martin_sylius_subscription.form.subscription_plan.introductory_price_free_trial' => self::FREE_TRIAL,
                ],
                'expanded' => true,
            ])
            ->add('introductoryDiscountPercentage', IntegerType::class, [
                'label' => 'jpm_martin_sylius_subscription.form.subscription_plan.introductory_discount_percentage',
                'required' => false,
                'attr' => ['min' => 0, 'max' => 100],
                'constraints' => [new Callback(callback: self::requireTheDiscount(...), groups: $validationGroups)],
            ])
            // A blank count reaches validation as 0, with the message the administrator needs.
            ->add('introductoryCycles', IntegerType::class, [
                'label' => 'jpm_martin_sylius_subscription.form.subscription_plan.introductory_cycles',
                'help' => 'jpm_martin_sylius_subscription.form.subscription_plan.introductory_cycles_help',
                'empty_data' => '0',
                'attr' => ['min' => 1],
            ])
            ->add('trialDays', IntegerType::class, [
                'label' => 'jpm_martin_sylius_subscription.form.subscription_plan.trial_days',
                'help' => 'jpm_martin_sylius_subscription.form.subscription_plan.trial_days_help',
                'required' => false,
                'attr' => ['min' => 1],
                'constraints' => [new Callback(callback: self::requireTheTrialDays(...), groups: $validationGroups)],
            ])
            ->addEventListener(FormEvents::POST_SET_DATA, static function (FormEvent $event): void {
                $terms = $event->getData();
                $isTerms = $terms instanceof SubscriptionPlanInterface || $terms instanceof SubscriptionFrequencyInterface;

                $event->getForm()->get(self::FIELD)->setData(match (true) {
                    $isTerms && null !== $terms->getTrialDays() => self::FREE_TRIAL,
                    !$isTerms || null === $terms->getIntroductoryDiscountPercentage() => self::NONE,
                    1 === $terms->getIntroductoryCycles() => self::FIRST_ORDER,
                    default => self::FIRST_CYCLES,
                });
            })
            ->addEventListener(FormEvents::SUBMIT, static function (FormEvent $event): void {
                $terms = $event->getData();
                if (!$terms instanceof SubscriptionPlanInterface && !$terms instanceof SubscriptionFrequencyInterface) {
                    return;
                }

                // A request without the choice keeps no introductory price either.
                $mode = $event->getForm()->get(self::FIELD)->getData() ?? self::NONE;
                if (self::NONE === $mode || self::FREE_TRIAL === $mode) {
                    $terms->setIntroductoryDiscountPercentage(null);
                }
                if (self::FIRST_CYCLES !== $mode) {
                    $terms->setIntroductoryCycles(1);
                }
                if (self::FREE_TRIAL !== $mode) {
                    $terms->setTrialDays(null);
                }
            })
        ;
    }

    private static function requireTheDiscount(mixed $discount, ExecutionContextInterface $context): void
    {
        $mode = self::modeOf($context);
        if (null === $discount && \in_array($mode, [self::FIRST_ORDER, self::FIRST_CYCLES], true)) {
            $context->buildViolation('jpm_martin_sylius_subscription.subscription_plan.introductory_discount_percentage.not_blank')
                ->setTranslationDomain('validators')
                ->addViolation();
        }
    }

    private static function requireTheTrialDays(mixed $trialDays, ExecutionContextInterface $context): void
    {
        if (null === $trialDays && self::FREE_TRIAL === self::modeOf($context)) {
            $context->buildViolation('jpm_martin_sylius_subscription.subscription_plan.trial_days.not_blank')
                ->setTranslationDomain('validators')
                ->addViolation();
        }
    }

    private static function modeOf(ExecutionContextInterface $context): mixed
    {
        $field = $context->getObject();

        return $field instanceof FormInterface ? $field->getParent()?->get(self::FIELD)->getData() : null;
    }
}
