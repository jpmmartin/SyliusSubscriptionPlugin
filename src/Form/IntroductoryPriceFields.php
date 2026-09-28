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
 * The introductory price of a plan or a frequency, as the admin forms ask for it: none, the first order
 * only, or the first N cycles, with its discount. None clears the discount, and the first order only
 * lasts one cycle, whatever the cycles field says.
 */
final class IntroductoryPriceFields
{
    public const FIELD = 'introductoryPrice';

    public const NONE = 'none';

    public const FIRST_ORDER = 'first_order';

    public const FIRST_CYCLES = 'first_cycles';

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
            ->addEventListener(FormEvents::POST_SET_DATA, static function (FormEvent $event): void {
                $terms = $event->getData();
                $isTerms = $terms instanceof SubscriptionPlanInterface || $terms instanceof SubscriptionFrequencyInterface;

                $event->getForm()->get(self::FIELD)->setData(match (true) {
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
                if (self::NONE === $mode) {
                    $terms->setIntroductoryDiscountPercentage(null);
                }
                if (self::FIRST_CYCLES !== $mode) {
                    $terms->setIntroductoryCycles(1);
                }
            })
        ;
    }

    private static function requireTheDiscount(mixed $discount, ExecutionContextInterface $context): void
    {
        $field = $context->getObject();
        $mode = $field instanceof FormInterface ? $field->getParent()?->get(self::FIELD)->getData() : null;
        if (null === $discount && null !== $mode && self::NONE !== $mode) {
            $context->buildViolation('jpm_martin_sylius_subscription.subscription_plan.introductory_discount_percentage.not_blank')
                ->setTranslationDomain('validators')
                ->addViolation();
        }
    }
}
