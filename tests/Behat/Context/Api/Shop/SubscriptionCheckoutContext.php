<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Behat\Context\Api\Shop;

use Behat\Behat\Context\Context;
use Behat\Step\Then;
use Behat\Step\When;
use Sylius\Behat\Client\ApiClientInterface;
use Sylius\Behat\Client\RequestFactoryInterface;
use Sylius\Behat\Context\Api\Resources;
use Sylius\Behat\Service\SharedStorageInterface;
use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Webmozart\Assert\Assert;

/** The same steps as the UI context, through the shop API, on the cart the scenario picked up. */
final class SubscriptionCheckoutContext implements Context
{
    public function __construct(
        private readonly ApiClientInterface $client,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly SharedStorageInterface $sharedStorage,
    ) {
    }

    #[When('I accept the recurring charges of my subscriptions')]
    public function iAcceptTheRecurringChargesOfMySubscriptions(): void
    {
        $tokenValue = $this->sharedStorage->get('cart_token');
        Assert::string($tokenValue);

        $request = $this->requestFactory->customItemAction('shop', Resources::ORDERS, $tokenValue, HttpRequest::METHOD_PATCH, 'subscription-consent');
        $request->setContent([]);

        $response = $this->client->executeCustomRequest($request);
        Assert::same($response->getStatusCode(), 200, (string) $response->getContent());
    }

    #[Then('I should be told that subscriptions need an account')]
    public function iShouldBeToldThatSubscriptionsNeedAnAccount(): void
    {
        $this->assertViolation('Subscriptions need an account. Please sign in or create an account to place this order.');
    }

    #[Then('/^I should be told that the "([^"]+)" payment method cannot be used for subscriptions$/')]
    public function iShouldBeToldThatThePaymentMethodCannotBeUsedForSubscriptions(string $paymentMethodName): void
    {
        $this->assertViolation(\sprintf('The "%s" payment method cannot be used for subscriptions. Please choose another one.', $paymentMethodName));
    }

    #[Then('I should be told to accept the recurring charges of my subscriptions')]
    public function iShouldBeToldToAcceptTheRecurringCharges(): void
    {
        $this->assertViolation('Please accept the recurring charges of your subscriptions to place this order.');
    }

    private function assertViolation(string $message): void
    {
        $response = $this->client->getLastResponse();
        Assert::same($response->getStatusCode(), 422, (string) $response->getContent());

        /** @var array{violations?: list<array{message: string}>} $body */
        $body = json_decode((string) $response->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        $messages = array_map(static fn (array $violation): string => $violation['message'], $body['violations'] ?? []);

        Assert::inArray($message, $messages);
    }
}
