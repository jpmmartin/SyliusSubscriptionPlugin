<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\CommandHandler\Shop;

use ApiPlatform\Validator\Exception\ValidationException;
use JpmMartin\SyliusSubscriptionPlugin\Api\LoggedInCustomer;
use JpmMartin\SyliusSubscriptionPlugin\Api\ShopSubscriptionActions;
use JpmMartin\SyliusSubscriptionPlugin\Command\Shop\AcceptSubscriptionPriceIncrease;
use JpmMartin\SyliusSubscriptionPlugin\Command\Shop\AddItemToSubscription;
use JpmMartin\SyliusSubscriptionPlugin\Command\Shop\CancelSubscription;
use JpmMartin\SyliusSubscriptionPlugin\Command\Shop\ChangeSubscriptionAddress;
use JpmMartin\SyliusSubscriptionPlugin\Command\Shop\ChangeSubscriptionFrequency;
use JpmMartin\SyliusSubscriptionPlugin\Command\Shop\ChangeSubscriptionItems;
use JpmMartin\SyliusSubscriptionPlugin\Command\Shop\PauseSubscription;
use JpmMartin\SyliusSubscriptionPlugin\Command\Shop\ResumeSubscription;
use JpmMartin\SyliusSubscriptionPlugin\Command\Shop\SkipNextSubscriptionRenewal;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionIntervalUnit;
use JpmMartin\SyliusSubscriptionPlugin\Management\SubscriptionAddressChangerInterface;
use JpmMartin\SyliusSubscriptionPlugin\Management\SubscriptionFrequencyChangerInterface;
use JpmMartin\SyliusSubscriptionPlugin\Management\SubscriptionItemChanges;
use JpmMartin\SyliusSubscriptionPlugin\Management\SubscriptionItemEditorInterface;
use JpmMartin\SyliusSubscriptionPlugin\Management\SubscriptionRenewalSkipperInterface;
use JpmMartin\SyliusSubscriptionPlugin\Pricing\PendingPriceApplier;
use JpmMartin\SyliusSubscriptionPlugin\Repository\SubscriptionRepositoryInterface;
use JpmMartin\SyliusSubscriptionPlugin\Schedule\SubscriptionInterval;
use JpmMartin\SyliusSubscriptionPlugin\StateMachine\SubscriptionTransitions;
use Sylius\Abstraction\StateMachine\StateMachineInterface;
use Sylius\Component\Core\Model\AddressInterface;
use Sylius\Component\Core\Model\ShippingMethodInterface;
use Sylius\Component\Locale\Context\LocaleContextInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The shop API's actions on one of the customer's subscriptions. Each finds it among the customer's
 * own, as the account does, refuses with a validation error what the account would not offer, and
 * calls the same service as the account. The command bus stores the change in one transaction, and
 * delivers the events after it.
 */
final class ShopSubscriptionActionsHandler
{
    /**
     * @param SubscriptionRepositoryInterface<SubscriptionInterface> $subscriptionRepository
     * @param FactoryInterface<AddressInterface> $addressFactory
     * @param RepositoryInterface<ShippingMethodInterface> $shippingMethodRepository
     */
    public function __construct(
        private readonly SubscriptionRepositoryInterface $subscriptionRepository,
        private readonly LoggedInCustomer $loggedInCustomer,
        private readonly ShopSubscriptionActions $actions,
        private readonly StateMachineInterface $stateMachine,
        private readonly SubscriptionRenewalSkipperInterface $renewalSkipper,
        private readonly SubscriptionFrequencyChangerInterface $frequencyChanger,
        private readonly SubscriptionAddressChangerInterface $addressChanger,
        private readonly SubscriptionItemEditorInterface $itemEditor,
        private readonly PendingPriceApplier $pendingPriceApplier,
        private readonly FactoryInterface $addressFactory,
        private readonly RepositoryInterface $shippingMethodRepository,
        private readonly ValidatorInterface $validator,
        private readonly TranslatorInterface $translator,
        private readonly LocaleContextInterface $localeContext,
        private readonly string $consentVersion,
    ) {
    }

    public function cancel(CancelSubscription $command): SubscriptionInterface
    {
        $subscription = $this->subscription($command->subscriptionId, ShopSubscriptionActions::CANCEL);
        // With deliveries paid for, as in the account, it is cancelled once they are placed.
        if (0 < $subscription->getPrepaidDeliveriesLeft()) {
            $subscription->setCancelsAfterPrepaidDeliveries(true);
        } else {
            $this->stateMachine->apply($subscription, SubscriptionTransitions::GRAPH, SubscriptionTransitions::TRANSITION_CANCEL);
        }

        return $subscription;
    }

    public function pause(PauseSubscription $command): SubscriptionInterface
    {
        $subscription = $this->subscription($command->subscriptionId, ShopSubscriptionActions::PAUSE);
        $this->stateMachine->apply($subscription, SubscriptionTransitions::GRAPH, SubscriptionTransitions::TRANSITION_PAUSE);

        return $subscription;
    }

    public function resume(ResumeSubscription $command): SubscriptionInterface
    {
        $subscription = $this->subscription($command->subscriptionId, ShopSubscriptionActions::RESUME);
        $this->stateMachine->apply($subscription, SubscriptionTransitions::GRAPH, SubscriptionTransitions::TRANSITION_RESUME);

        return $subscription;
    }

    public function skipNextRenewal(SkipNextSubscriptionRenewal $command): SubscriptionInterface
    {
        $subscription = $this->subscription($command->subscriptionId, ShopSubscriptionActions::SKIP_RENEWAL);
        $this->renewalSkipper->skip($subscription);

        return $subscription;
    }

    public function changeFrequency(ChangeSubscriptionFrequency $command): SubscriptionInterface
    {
        $subscription = $this->subscription($command->subscriptionId, ShopSubscriptionActions::CHANGE_FREQUENCY);
        $unit = SubscriptionIntervalUnit::tryFrom($command->intervalUnit);
        $offered = null;
        foreach ($this->frequencyChanger->frequenciesToChangeTo($subscription) as $interval) {
            if ($interval->count === $command->intervalCount && $interval->unit === $unit) {
                $offered = $interval;
            }
        }
        if (!$offered instanceof SubscriptionInterval) {
            $this->refuse('frequency_not_offered', 'intervalCount');
        }

        $this->frequencyChanger->change($subscription, $offered);

        return $subscription;
    }

    public function changeAddress(ChangeSubscriptionAddress $command): SubscriptionInterface
    {
        $subscription = $this->subscription($command->subscriptionId, ShopSubscriptionActions::CHANGE_ADDRESS);
        $shippingAddress = $this->address($command->shippingAddress, 'shippingAddress');
        $billingAddress = null === $command->billingAddress ? null : $this->address($command->billingAddress, 'billingAddress');
        $shippingMethod = null;
        if (null !== $command->shippingMethod) {
            $shippingMethod = $this->shippingMethodRepository->findOneBy(['code' => $command->shippingMethod]);
            if (!$shippingMethod instanceof ShippingMethodInterface) {
                $this->refuse('shipping_method_not_offered', 'shippingMethod');
            }
        }

        try {
            $this->addressChanger->change($subscription, $shippingAddress, $billingAddress, $shippingMethod);
        } catch (\InvalidArgumentException) {
            $this->refuse('shipping_method_not_offered', 'shippingMethod');
        }

        return $subscription;
    }

    public function changeItems(ChangeSubscriptionItems $command): SubscriptionInterface
    {
        $subscription = $this->subscription($command->subscriptionId, ShopSubscriptionActions::CHANGE_ITEMS);
        $changes = new SubscriptionItemChanges();
        foreach ($command->items as $index => $change) {
            $path = \sprintf('items[%s]', $index);
            $item = null;
            foreach ($this->itemEditor->editableItems($subscription) as $editable) {
                if (\is_array($change) && $editable->getId() === ($change['id'] ?? null)) {
                    $item = $editable;
                }
            }
            if (null === $item || !\is_array($change)) {
                $this->refuse('item_not_changeable', $path . '.id');
            }

            $edit = $changes->edit($item);
            if (\array_key_exists('quantity', $change)) {
                $edit->quantity = $this->quantity($change['quantity'], $path . '.quantity');
            }
            if (isset($change['productVariant'])) {
                $offer = null;
                foreach ($this->itemEditor->variantsFor($item) as $candidate) {
                    if ($candidate->variant->getCode() === $change['productVariant']) {
                        $offer = $candidate;
                    }
                }
                if (null === $offer && $change['productVariant'] !== $item->getProductVariant()?->getCode()) {
                    $this->refuse('item_not_offered', $path . '.productVariant');
                }
                if (null !== $offer) {
                    $edit->variant = $offer->variant;
                }
            }
            if (true === ($change['removed'] ?? false)) {
                if (!$this->itemEditor->canRemove($item)) {
                    $this->refuse('item_not_removable', $path . '.removed');
                }
                $edit->removed = true;
            }
        }

        $this->applyItemChanges($subscription, $changes, $command->acceptedConsentVersion);

        return $subscription;
    }

    public function addItem(AddItemToSubscription $command): SubscriptionInterface
    {
        $subscription = $this->subscription($command->subscriptionId, ShopSubscriptionActions::ADD_ITEM);
        $offer = null;
        foreach ($this->itemEditor->variantsToAdd($subscription) as $candidate) {
            if ($candidate->variant->getCode() === $command->productVariant) {
                $offer = $candidate;
            }
        }
        if (null === $offer) {
            $this->refuse('item_not_offered', 'productVariant');
        }

        $changes = new SubscriptionItemChanges();
        $changes->add($offer->variant, $this->quantity($command->quantity, 'quantity'));
        $this->applyItemChanges($subscription, $changes, $command->acceptedConsentVersion);

        return $subscription;
    }

    public function acceptPriceIncrease(AcceptSubscriptionPriceIncrease $command): SubscriptionInterface
    {
        $subscription = $this->subscription($command->subscriptionId, ShopSubscriptionActions::ACCEPT_PRICE_INCREASE);
        $this->pendingPriceApplier->accept($subscription);
        if ($command->resume && $this->stateMachine->can($subscription, SubscriptionTransitions::GRAPH, SubscriptionTransitions::TRANSITION_RESUME)) {
            $this->stateMachine->apply($subscription, SubscriptionTransitions::GRAPH, SubscriptionTransitions::TRANSITION_RESUME);
        }

        return $subscription;
    }

    /** The customer's own subscription, refused unless the account would offer the action on it now. */
    private function subscription(string $id, string $action): SubscriptionInterface
    {
        $subscription = $this->subscriptionRepository->findOneByIdAndCustomer($id, $this->loggedInCustomer->get());
        if (null === $subscription) {
            throw new NotFoundHttpException('The subscription does not exist.');
        }

        if (!$this->actions->allows($subscription, $action)) {
            $this->refuse($action . '_not_allowed');
        }

        return $subscription;
    }

    private function applyItemChanges(SubscriptionInterface $subscription, SubscriptionItemChanges $changes, ?string $acceptedConsentVersion): void
    {
        try {
            $requiresConsent = $this->itemEditor->requiresConsent($subscription, $changes);
        } catch (\InvalidArgumentException) {
            $this->refuse('item_not_offered');
        }

        // The recurring charges rise: the customer accepts the current text, by its version.
        if ($requiresConsent && $acceptedConsentVersion !== $this->consentVersion) {
            $this->refuse('consent_required', 'acceptedConsentVersion', ['%version%' => $this->consentVersion]);
        }

        try {
            $this->itemEditor->apply($subscription, $changes, $requiresConsent ? $this->localeContext->getLocaleCode() : null);
        } catch (\InvalidArgumentException) {
            $this->refuse('item_not_offered');
        }
    }

    /** A quantity the account's form would take: a whole number from one to the highest a cart line allows. */
    private function quantity(mixed $quantity, string $propertyPath): int
    {
        $max = $this->itemEditor->maxQuantity();
        if (!\is_int($quantity) || $quantity < 1 || $quantity > $max) {
            $this->refuse('quantity_out_of_range', $propertyPath, ['%max%' => (string) $max]);
        }

        return $quantity;
    }

    /** @param array<mixed> $fields */
    private function address(array $fields, string $propertyPath): AddressInterface
    {
        // A number is taken as its text, as a postcode may be sent; anything else as missing, which the validation reports.
        $field = static fn (string $name): ?string => \is_string($fields[$name] ?? null) || \is_int($fields[$name] ?? null) ? (string) $fields[$name] : null;

        $address = $this->addressFactory->createNew();
        $address->setFirstName($field('firstName'));
        $address->setLastName($field('lastName'));
        $address->setCompany($field('company'));
        $address->setStreet($field('street'));
        $address->setCity($field('city'));
        $address->setPostcode($field('postcode'));
        $address->setCountryCode($field('countryCode'));
        $address->setProvinceCode($field('provinceCode'));
        $address->setProvinceName($field('provinceName'));
        $address->setPhoneNumber($field('phoneNumber'));

        $violations = $this->validator->validate($address, null, ['sylius']);
        if (0 < \count($violations)) {
            $prefixed = new ConstraintViolationList();
            foreach ($violations as $violation) {
                $prefixed->add(new ConstraintViolation(
                    $violation->getMessage(),
                    $violation->getMessageTemplate(),
                    $violation->getParameters(),
                    $address,
                    $propertyPath . '.' . $violation->getPropertyPath(),
                    $violation->getInvalidValue(),
                ));
            }

            throw new ValidationException($prefixed);
        }

        return $address;
    }

    /**
     * @param array<string, string> $parameters
     *
     * @return never
     */
    private function refuse(string $reason, string $propertyPath = '', array $parameters = []): void
    {
        $template = 'jpm_martin_sylius_subscription.shop_api.' . $reason;

        throw new ValidationException(new ConstraintViolationList([new ConstraintViolation(
            $this->translator->trans($template, $parameters, 'validators'),
            $template,
            $parameters,
            null,
            $propertyPath,
            null,
        )]));
    }
}
