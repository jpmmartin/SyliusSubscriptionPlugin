<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Behat\Context\Setup;

use Behat\Behat\Context\Context;
use Behat\Step\Given;
use Doctrine\Persistence\ObjectManager;
use JpmMartin\SyliusSubscriptionPlugin\Entity\CartFrequencyInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\RepeatableVariantInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionFrequencyInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionIntervalUnit;
use JpmMartin\SyliusSubscriptionPlugin\Frequency\CartRepeaterInterface;
use Sylius\Behat\Service\SharedStorageInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Component\Core\Repository\OrderRepositoryInterface;
use Sylius\Component\Order\Processor\OrderProcessorInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Webmozart\Assert\Assert;

final class SubscriptionFrequencyContext implements Context
{
    /**
     * @param FactoryInterface<SubscriptionFrequencyInterface> $frequencyFactory
     * @param RepositoryInterface<SubscriptionFrequencyInterface> $frequencyRepository
     * @param FactoryInterface<RepeatableVariantInterface> $repeatableVariantFactory
     * @param FactoryInterface<CartFrequencyInterface> $cartFrequencyFactory
     * @param FactoryInterface<OrderInterface> $orderFactory
     * @param OrderRepositoryInterface<OrderInterface> $orderRepository
     */
    public function __construct(
        private readonly FactoryInterface $frequencyFactory,
        private readonly RepositoryInterface $frequencyRepository,
        private readonly FactoryInterface $repeatableVariantFactory,
        private readonly FactoryInterface $cartFrequencyFactory,
        private readonly FactoryInterface $orderFactory,
        private readonly ObjectManager $entityManager,
        private readonly SharedStorageInterface $sharedStorage,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly CartRepeaterInterface $cartRepeater,
        private readonly OrderProcessorInterface $orderProcessor,
    ) {
    }

    #[Given('/^the store offers a "([^"]+)" subscription frequency renewing every (\d+) (day|week|month|year)s?(?: with a (\d+)% discount)?$/')]
    public function theStoreOffersASubscriptionFrequency(string $code, string $intervalCount, string $intervalUnit, string $discountPercentage = '0'): void
    {
        $frequency = $this->frequencyFactory->createNew();
        $frequency->setCode($code);
        $frequency->setName($code);
        $frequency->setIntervalCount((int) $intervalCount);
        $frequency->setIntervalUnit(SubscriptionIntervalUnit::from($intervalUnit));
        $frequency->setDiscountPercentage((int) $discountPercentage);
        $frequency->addChannel($this->channel());

        $this->frequencyRepository->add($frequency);
        $this->sharedStorage->set('subscription_frequency', $frequency);
    }

    #[Given('/^the "([^"]+)" subscription frequency is disabled$/')]
    public function theSubscriptionFrequencyIsDisabled(string $code): void
    {
        $frequency = $this->frequency($code);
        $frequency->disable();
        $this->entityManager->flush();
    }

    #[Given('/^the ("[^"]+" variant) can be repeated$/')]
    public function theVariantCanBeRepeated(ProductVariantInterface $variant): void
    {
        $repeatable = $this->repeatableVariantFactory->createNew();
        $repeatable->setProductVariant($variant);
        $this->entityManager->persist($repeatable);
        $this->entityManager->flush();
    }

    #[Given('/^a cart is repeated with the "([^"]+)" subscription frequency$/')]
    public function aCartIsRepeatedWith(string $code): void
    {
        $channel = $this->channel();
        $cart = $this->orderFactory->createNew();
        $cart->setChannel($channel);
        $cart->setCurrencyCode($channel->getBaseCurrency()?->getCode());
        $cart->setLocaleCode($channel->getDefaultLocale()?->getCode());
        $this->entityManager->persist($cart);

        $cartFrequency = $this->cartFrequencyFactory->createNew();
        $cartFrequency->setOrder($cart);
        $cartFrequency->setFrequency($this->frequency($code));
        $this->entityManager->persist($cartFrequency);
        $this->entityManager->flush();
    }

    /** The cart Sylius's own setup put the scenario's products in, repeated as the customer would from the cart page. */
    #[Given('/^I chose to repeat my cart with the "([^"]+)" subscription frequency$/')]
    public function iChoseToRepeatMyCartWith(string $code): void
    {
        $tokenValue = $this->sharedStorage->get('cart_token');
        Assert::string($tokenValue);
        $cart = $this->orderRepository->findCartByTokenValue($tokenValue);
        Assert::isInstanceOf($cart, OrderInterface::class);

        $this->cartRepeater->repeat($cart, $this->frequency($code));
        $this->orderProcessor->process($cart);
        $this->entityManager->flush();
    }

    private function frequency(string $code): SubscriptionFrequencyInterface
    {
        $frequency = $this->frequencyRepository->findOneBy(['code' => $code]);
        Assert::isInstanceOf($frequency, SubscriptionFrequencyInterface::class, \sprintf('There is no "%s" subscription frequency.', $code));

        return $frequency;
    }

    private function channel(): ChannelInterface
    {
        $channel = $this->sharedStorage->get('channel');
        Assert::isInstanceOf($channel, ChannelInterface::class);

        return $channel;
    }
}
