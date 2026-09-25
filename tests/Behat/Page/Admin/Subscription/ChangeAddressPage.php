<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Behat\Page\Admin\Subscription;

use FriendsOfBehat\PageObjectExtension\Page\SymfonyPage;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Behat\Page\Shared\AddressChangeForm;

final class ChangeAddressPage extends SymfonyPage
{
    public function getRouteName(): string
    {
        return 'jpm_martin_sylius_subscription_admin_subscription_change_address';
    }

    public function chooseFromAddressBook(string $street): void
    {
        AddressChangeForm::chooseFromAddressBook($this->getDocument(), $street);
    }

    public function writeShippingAddress(string $fullName, string $street, string $postcode, string $city, string $country): void
    {
        AddressChangeForm::writeShippingAddress($this->getDocument(), $fullName, $street, $postcode, $city, $country);
    }

    /** @return list<string> */
    public function getOfferedShippingMethods(): array
    {
        return AddressChangeForm::offeredShippingMethods($this->getDocument());
    }

    public function chooseShippingMethod(string $name): void
    {
        AddressChangeForm::chooseShippingMethod($this->getDocument(), $name);
    }

    public function confirm(): void
    {
        AddressChangeForm::confirm($this->getDocument());
    }
}
