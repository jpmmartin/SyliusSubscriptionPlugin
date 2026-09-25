<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Order;

use Sylius\Component\Core\Model\AddressInterface;
use Sylius\Resource\Factory\FactoryInterface;

/**
 * A new address with the same fields, as Sylius copies an address into an order: an order or a
 * subscription keeps its own, whatever happens later to the one it was copied from.
 */
final class AddressCopier
{
    /** @param FactoryInterface<AddressInterface> $addressFactory */
    public function __construct(private readonly FactoryInterface $addressFactory)
    {
    }

    public function copy(AddressInterface $address): AddressInterface
    {
        $copy = $this->addressFactory->createNew();
        $copy->setFirstName($address->getFirstName());
        $copy->setLastName($address->getLastName());
        $copy->setPhoneNumber($address->getPhoneNumber());
        $copy->setCompany($address->getCompany());
        $copy->setStreet($address->getStreet());
        $copy->setCity($address->getCity());
        $copy->setPostcode($address->getPostcode());
        $copy->setCountryCode($address->getCountryCode());
        $copy->setProvinceCode($address->getProvinceCode());
        $copy->setProvinceName($address->getProvinceName());

        return $copy;
    }
}
