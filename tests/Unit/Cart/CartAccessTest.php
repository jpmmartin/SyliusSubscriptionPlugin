<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Unit\Cart;

use JpmMartin\SyliusSubscriptionPlugin\Cart\CartAccess;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Sylius\Bundle\ApiBundle\Context\UserContextInterface;
use Sylius\Component\Core\Model\Customer;
use Sylius\Component\Core\Model\Order;
use Sylius\Component\Core\Model\ShopUser;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Extracted verbatim from AddSubscriptionItemToCartHandler's own private method, so this pins the behaviour that
 * had no test of its own before it became a service two handlers share (choose-subscription-per-cart-line,
 * task 4.1). A cart is a guest cart by default; only setCustomerWithAuthorization() turns it into a registered
 * customer's, so that is how the non-guest fixtures below are built.
 */
final class CartAccessTest extends TestCase
{
    public function testAGuestCartIsAccessibleToAnyone(): void
    {
        $userContext = $this->createMock(UserContextInterface::class);
        $userContext->expects(self::never())->method('getUser');

        $cart = new Order();
        self::assertTrue($cart->isCreatedByGuest(), 'A fresh order is a guest cart by default.');

        (new CartAccess($userContext))->assertAccessible($cart);
    }

    public function testACartIsAccessibleWithoutAUserContextAtAll(): void
    {
        $cart = new Order();
        $cart->setCustomerWithAuthorization($this->customerWithId(1));

        $this->expectNotToPerformAssertions();
        (new CartAccess(null))->assertAccessible($cart);
    }

    /** Not a state setCustomerWithAuthorization() can reach on its own, but CartAccess guards it anyway. */
    public function testARegisteredCartWithNoCustomerSomehowIsAccessibleToAnyone(): void
    {
        $userContext = $this->createMock(UserContextInterface::class);

        $cart = new Order();
        $cart->setCustomerWithAuthorization(null);

        $this->expectNotToPerformAssertions();
        (new CartAccess($userContext))->assertAccessible($cart);
    }

    public function testTheCustomerWhoOwnsTheCartMayAccessIt(): void
    {
        $customer = $this->customerWithId(1);
        $user = new ShopUser();
        $user->setCustomer($customer);

        $cart = new Order();
        $cart->setCustomerWithAuthorization($customer);

        /** @var UserContextInterface&MockObject $userContext */
        $userContext = $this->createMock(UserContextInterface::class);
        $userContext->method('getUser')->willReturn($user);

        $this->expectNotToPerformAssertions();
        (new CartAccess($userContext))->assertAccessible($cart);
    }

    public function testADifferentRegisteredCustomersCartIsNotFound(): void
    {
        $owner = $this->customerWithId(1);
        $owner->setUser(new ShopUser());

        $cart = new Order();
        $cart->setCustomerWithAuthorization($owner);

        $someoneElse = new ShopUser();
        $someoneElse->setCustomer($this->customerWithId(2));

        /** @var UserContextInterface&MockObject $userContext */
        $userContext = $this->createMock(UserContextInterface::class);
        $userContext->method('getUser')->willReturn($someoneElse);

        $this->expectException(NotFoundHttpException::class);
        (new CartAccess($userContext))->assertAccessible($cart);
    }

    public function testNoSignedInUserCannotAccessARegisteredCustomersCart(): void
    {
        $owner = $this->customerWithId(1);
        $owner->setUser(new ShopUser());

        $cart = new Order();
        $cart->setCustomerWithAuthorization($owner);

        /** @var UserContextInterface&MockObject $userContext */
        $userContext = $this->createMock(UserContextInterface::class);
        $userContext->method('getUser')->willReturn(null);

        $this->expectException(NotFoundHttpException::class);
        (new CartAccess($userContext))->assertAccessible($cart);
    }

    private function customerWithId(int $id): Customer
    {
        $customer = new Customer();
        (new \ReflectionProperty(Customer::class, 'id'))->setValue($customer, $id);

        return $customer;
    }
}
