<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Integration\Trial;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionPlanAwareInterface;
use Sylius\Behat\Context\Setup\PaymentContext;
use Sylius\Behat\Service\SharedStorageInterface;
use Sylius\Bundle\ApiBundle\Command\Checkout\CompleteOrder;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Integration\Lifecycle\LifecycleTestCase;

/**
 * Coffee at $100.00 on its monthly plan with 10% off and 14 days of free trial, shipped for free, in
 * the cart of a customer; the test store keeps cards with "Card on file" only.
 */
final class TrialCartTest extends LifecycleTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->coffeeMonthly->setTrialDays(14);
        $this->entityManager()->flush();
    }

    public function testALineWithAFreeTrialCostsNothingKeepsItsDaysAndItsOrderAPaymentOfZero(): void
    {
        $cart = $this->cart();
        $line = $this->addLine($cart, $this->coffee, 1, $this->coffeeMonthly);

        self::assertSame(0, $line->getUnitPrice());
        self::assertSame(14, $this->trialDaysOf($line));
        self::assertSame(0, $cart->getTotal());
        self::assertCount(1, $cart->getPayments(), 'Sylius would drop the payments of an order of 0.');
        self::assertSame(0, $cart->getLastPayment()?->getAmount());
    }

    public function testACustomerWhoAlreadyHadTheProductPaysItsNormalPriceAndStillGetsTheTrialOfAnother(): void
    {
        $this->coffeeMonthly->setTrialDays(null);
        $this->entityManager()->flush();
        $this->placedCoffeeOrder();
        $this->coffeeMonthly->setTrialDays(14);
        $this->teaMonthly->setTrialDays(7);
        $this->entityManager()->flush();

        $cart = $this->cart();
        $coffee = $this->addLine($cart, $this->coffee, 1, $this->coffeeMonthly);
        $tea = $this->addLine($cart, $this->tea, 1, $this->teaMonthly);

        self::assertSame([9000, null], [$coffee->getUnitPrice(), $this->trialDaysOf($coffee)], 'Its subscription to Coffee, even pending, used up the trial.');
        self::assertSame([0, 7], [$tea->getUnitPrice(), $this->trialDaysOf($tea)]);
    }

    public function testTheCheckoutOfAFreeTrialNeedsAPaymentMethodThatKeepsTheCard(): void
    {
        /** @var PaymentContext $payments */
        $payments = self::getContainer()->get('sylius.behat.context.setup.payment');
        $payments->storeAllowsPaying('Other card');
        /** @var SharedStorageInterface $sharedStorage */
        $sharedStorage = self::getContainer()->get('sylius.behat.shared_storage');
        $otherCard = $sharedStorage->get('payment_method');
        self::assertInstanceOf(PaymentMethodInterface::class, $otherCard);

        $cart = $this->cart();
        $this->addLine($cart, $this->coffee, 1, $this->coffeeMonthly);

        $cart->getLastPayment()?->setMethod($otherCard);
        self::assertContains('jpm_martin_sylius_subscription.checkout.trial_payment_method_required', $this->checkoutViolationsOf($cart));

        $cart->getLastPayment()?->setMethod($this->paymentMethod);
        self::assertNotContains('jpm_martin_sylius_subscription.checkout.trial_payment_method_required', $this->checkoutViolationsOf($cart));
    }

    public function testTheApiRefusesToCompleteACartWithAFreeTrialAndTheShopDoesNot(): void
    {
        $cart = $this->cart();
        $cart->setTokenValue('cart-with-a-trial');
        $this->addLine($cart, $this->coffee, 1, $this->coffeeMonthly);
        $cart->getLastPayment()?->setMethod($this->paymentMethod);
        $this->entityManager()->persist($cart);
        $this->entityManager()->flush();

        self::assertContains('jpm_martin_sylius_subscription.checkout.trial_not_through_the_api', $this->checkoutViolationsOf(new CompleteOrder('cart-with-a-trial')));
        self::assertNotContains('jpm_martin_sylius_subscription.checkout.trial_not_through_the_api', $this->checkoutViolationsOf($cart));
    }

    private function trialDaysOf(OrderItemInterface $line): ?int
    {
        self::assertInstanceOf(SubscriptionPlanAwareInterface::class, $line);

        return $line->getSubscriptionTrialDays();
    }

    /** @return list<string> the message template of each violation of the last checkout step, in the shop or through the API */
    private function checkoutViolationsOf(OrderInterface|CompleteOrder $order): array
    {
        /** @var ValidatorInterface $validator */
        $validator = self::getContainer()->get('validator');
        $templates = [];
        foreach ($validator->validate($order, null, ['sylius_checkout_complete']) as $violation) {
            $templates[] = $violation->getMessageTemplate();
        }

        return $templates;
    }
}
