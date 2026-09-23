<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Serializer;

use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionPlanAwareInterface;
use Sylius\Bundle\ApiBundle\SectionResolver\ShopApiSection;
use Sylius\Bundle\CoreBundle\SectionResolver\SectionProviderInterface;
use Sylius\Component\Core\Model\OrderItemInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Webmozart\Assert\Assert;

/**
 * Tells, on each line of a shop cart or order, the code of the plan it was added on and of the
 * frequency its cart is repeated with, or null. Added here rather than mapped on the line, so the
 * store's OrderItem needs nothing more than the plugin's trait.
 */
final class SubscriptionOrderItemNormalizer implements NormalizerInterface, NormalizerAwareInterface
{
    use NormalizerAwareTrait;

    private const ALREADY_CALLED = 'jpm_martin_sylius_subscription_order_item_normalizer_already_called';

    /** @param list<string> $serializationGroups */
    public function __construct(
        private readonly SectionProviderInterface $sectionProvider,
        private readonly array $serializationGroups,
    ) {
    }

    /** @return array<string, mixed> */
    public function normalize(mixed $object, ?string $format = null, array $context = []): array
    {
        Assert::isInstanceOf($object, SubscriptionPlanAwareInterface::class);

        $context[self::ALREADY_CALLED] = true;
        $data = $this->normalizer->normalize($object, $format, $context);
        Assert::isArray($data);

        $data['subscriptionPlan'] = $object->getSubscriptionPlan()?->getCode();
        $data['subscriptionFrequency'] = $object->getSubscriptionFrequency()?->getCode();

        return $data;
    }

    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        if (isset($context[self::ALREADY_CALLED])) {
            return false;
        }

        $groups = (array) ($context['groups'] ?? []);

        return
            $data instanceof OrderItemInterface &&
            $data instanceof SubscriptionPlanAwareInterface &&
            $this->sectionProvider->getSection() instanceof ShopApiSection &&
            [] !== array_intersect($this->serializationGroups, $groups)
        ;
    }

    public function getSupportedTypes(?string $format): array
    {
        return [OrderItemInterface::class => false];
    }
}
