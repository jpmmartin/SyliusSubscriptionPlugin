<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Form\Type;

use Sylius\Bundle\AddressingBundle\Form\Type\AddressType;
use Sylius\Component\Core\Model\AddressInterface;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The shipping address is one of the customer's address book or one written here, and billing goes
 * there too unless another address is given. An address the customer does not use is not validated.
 * "shippingMethod" carries the code of the method chosen among those that reach the new address, which
 * is only asked for, and drawn as a choice, when the current one does not reach it.
 */
final class SubscriptionAddressChangeType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var list<AddressInterface> $addressBook */
        $addressBook = $options['address_book'];
        if ([] !== $addressBook) {
            $builder->add('addressBookEntry', ChoiceType::class, [
                'choices' => $addressBook,
                'choice_value' => static fn (?AddressInterface $address): string => (string) $address?->getId(),
                'choice_label' => static fn (AddressInterface $address): string => self::describe($address),
                'choice_translation_domain' => false,
                'required' => false,
                'placeholder' => 'jpm_martin_sylius_subscription.ui.another_address',
                'label' => 'sylius.ui.address_book',
            ]);
        }

        $builder
            ->add('shippingAddress', AddressType::class, [
                'label' => 'sylius.ui.shipping_address',
                'required' => false,
                'shippable' => true,
                'validation_groups' => static fn (FormInterface $form): array => self::writesItsOwn($form) ? ['sylius', 'shippable'] : [],
            ])
            ->add('differentBillingAddress', CheckboxType::class, [
                'label' => 'jpm_martin_sylius_subscription.ui.different_billing_address',
                'required' => false,
            ])
            ->add('billingAddress', AddressType::class, [
                'label' => 'sylius.ui.billing_address',
                'required' => false,
                'validation_groups' => static fn (FormInterface $form): array => true === $form->getParent()?->get('differentBillingAddress')->getData() ? ['sylius'] : [],
            ])
            ->add('shippingMethod', TextType::class, [
                'required' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefault('address_book', [])
            ->setAllowedTypes('address_book', 'array')
        ;
    }

    public function getBlockPrefix(): string
    {
        return 'jpm_martin_sylius_subscription_address_change';
    }

    public static function describe(AddressInterface $address): string
    {
        return \sprintf('%s %s, %s, %s %s, %s', $address->getFirstName(), $address->getLastName(), $address->getStreet(), $address->getPostcode(), $address->getCity(), $address->getCountryCode());
    }

    /** Whether the shipping address is the one written in the form, not one of the address book. */
    private static function writesItsOwn(FormInterface $shippingAddress): bool
    {
        $root = $shippingAddress->getParent();

        return null === $root || !$root->has('addressBookEntry') || null === $root->get('addressBookEntry')->getData();
    }
}
