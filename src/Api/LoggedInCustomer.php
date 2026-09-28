<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Api;

use Sylius\Bundle\ApiBundle\Context\UserContextInterface;
use Sylius\Component\Core\Model\CustomerInterface;
use Sylius\Component\Core\Model\ShopUserInterface;

/** The customer signed in to the shop API, if any. */
final class LoggedInCustomer
{
    public function __construct(private readonly UserContextInterface $userContext)
    {
    }

    public function get(): ?CustomerInterface
    {
        $user = $this->userContext->getUser();
        $customer = $user instanceof ShopUserInterface ? $user->getCustomer() : null;

        return $customer instanceof CustomerInterface ? $customer : null;
    }
}
