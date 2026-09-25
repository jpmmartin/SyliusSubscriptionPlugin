<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Behat\Page\Shared;

use Behat\Mink\Element\DocumentElement;

/**
 * The address change form, the account's and the admin's alike: an address of the book chosen by its
 * street, or a new one written in, and a shipping method chosen by its name when one is asked for.
 */
final class AddressChangeForm
{
    private const FORM = 'jpm_martin_sylius_subscription_address_change';

    public static function chooseFromAddressBook(DocumentElement $document, string $street): void
    {
        $select = $document->findField(self::FORM . '[addressBookEntry]');
        if (null === $select) {
            throw new \RuntimeException('There is no address book to choose from.');
        }
        foreach ($select->findAll('css', 'option') as $option) {
            if (str_contains($option->getText(), $street)) {
                $select->selectOption((string) $option->getAttribute('value'));

                return;
            }
        }

        throw new \InvalidArgumentException(\sprintf('No address of the book is in "%s".', $street));
    }

    public static function writeShippingAddress(DocumentElement $document, string $fullName, string $street, string $postcode, string $city, string $country): void
    {
        [$firstName, $lastName] = explode(' ', $fullName, 2) + [1 => ''];
        $prefix = self::FORM . '[shippingAddress]';
        $document->fillField($prefix . '[firstName]', $firstName);
        $document->fillField($prefix . '[lastName]', $lastName);
        $document->fillField($prefix . '[street]', $street);
        $document->fillField($prefix . '[postcode]', $postcode);
        $document->fillField($prefix . '[city]', $city);
        $document->selectFieldOption($prefix . '[countryCode]', $country);
    }

    /** @return list<string> the shipping methods asked to choose from, "Europe Express: $15.00" */
    public static function offeredShippingMethods(DocumentElement $document): array
    {
        $offered = [];
        foreach ($document->findAll('css', '[data-test-shipping-methods] .form-check-label') as $label) {
            $offered[] = trim($label->getText());
        }

        return $offered;
    }

    public static function chooseShippingMethod(DocumentElement $document, string $name): void
    {
        $radio = $document->find('css', \sprintf('[data-test-shipping-method="%s"]', $name));
        if (null === $radio) {
            throw new \InvalidArgumentException(\sprintf('"%s" is not offered; these are: %s.', $name, implode(', ', self::offeredShippingMethods($document))));
        }

        $radio->selectOption((string) $radio->getAttribute('value'));
    }

    public static function confirm(DocumentElement $document): void
    {
        $button = $document->find('css', '[data-test-confirm-address-change]');
        if (null === $button) {
            throw new \RuntimeException('There is no button to change the address.');
        }
        $button->press();
    }
}
