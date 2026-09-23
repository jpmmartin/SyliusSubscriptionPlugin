<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Form\Extension;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionPlanInterface;
use Sylius\Bundle\OrderBundle\Form\Type\CartItemType;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractTypeExtension;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormInterface;

/**
 * Lets the customer choose, on the product page, between buying the selected variant once and
 * subscribing to it on one of its enabled plans.
 *
 * Only on the add-to-cart form, which is the one built with a "product" option: the cart summary
 * builds the same type without it, and a line's plan is not changed from there. The choices follow
 * the variant: they are set from the line's variant when the form is built, and rebuilt when the
 * variant field is submitted, which it is before this field because it comes first.
 */
final class SubscriptionPlanCartItemTypeExtension extends AbstractTypeExtension
{
    public const FIELD = 'subscriptionPlan';

    /**
     * @param RepositoryInterface<SubscriptionPlanInterface> $subscriptionPlanRepository
     * @param class-string<SubscriptionPlanInterface> $subscriptionPlanClass
     */
    public function __construct(
        private readonly RepositoryInterface $subscriptionPlanRepository,
        private readonly string $subscriptionPlanClass,
    ) {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        if (!isset($options['product'])) {
            return;
        }

        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event): void {
            $cartItem = $event->getData();

            $this->addPlanField(
                $event->getForm(),
                $cartItem instanceof OrderItemInterface ? $cartItem->getVariant() : null,
            );
        });

        if (!$builder->has('variant')) {
            return;
        }

        $builder->get('variant')->addEventListener(FormEvents::POST_SUBMIT, function (FormEvent $event): void {
            $cartItemForm = $event->getForm()->getParent();
            if (null === $cartItemForm) {
                return;
            }

            $variant = $event->getForm()->getData();

            $this->addPlanField($cartItemForm, $variant instanceof ProductVariantInterface ? $variant : null);
        });
    }

    public static function getExtendedTypes(): iterable
    {
        return [CartItemType::class];
    }

    private function addPlanField(FormInterface $cartItemForm, ?ProductVariantInterface $variant): void
    {
        $plans = $this->getEnabledPlans($variant);
        if ([] === $plans) {
            if ($cartItemForm->has(self::FIELD)) {
                $cartItemForm->remove(self::FIELD);
            }

            return;
        }

        $cartItemForm->add(self::FIELD, EntityType::class, [
            'class' => $this->subscriptionPlanClass,
            'choices' => $plans,
            'choice_value' => 'code',
            'choice_label' => static fn (SubscriptionPlanInterface $plan): string => 0 < $plan->getDiscountPercentage()
                ? 'jpm_martin_sylius_subscription.ui.plan_choice_with_discount'
                : 'jpm_martin_sylius_subscription.ui.plan_choice',
            'choice_translation_parameters' => static fn (SubscriptionPlanInterface $plan): array => [
                '%name%' => $plan->getName(),
                '%discount%' => $plan->getDiscountPercentage(),
            ],
            'placeholder' => 'jpm_martin_sylius_subscription.ui.one_time_purchase',
            // An entity field leaves its choices untranslated by default, placeholder included.
            'choice_translation_domain' => 'messages',
            'required' => false,
            'expanded' => true,
            'label' => 'jpm_martin_sylius_subscription.ui.purchase_type',
        ]);
    }

    /** @return list<SubscriptionPlanInterface> */
    private function getEnabledPlans(?ProductVariantInterface $variant): array
    {
        if (null === $variant || null === $variant->getId()) {
            return [];
        }

        /** @var list<SubscriptionPlanInterface> $plans */
        $plans = $this->subscriptionPlanRepository->findBy(['productVariant' => $variant, 'enabled' => true], ['id' => 'ASC']);

        return $plans;
    }
}
