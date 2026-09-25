<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Frequency;

use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\RepeatableVariantInterface;
use Sylius\Component\Core\Model\ProductVariantInterface;
use Sylius\Resource\Factory\FactoryInterface;

final class RepeatableVariants implements RepeatableVariantsInterface
{
    /**
     * @param FactoryInterface<RepeatableVariantInterface> $repeatableVariantFactory
     * @param class-string<RepeatableVariantInterface> $repeatableVariantClass
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly FactoryInterface $repeatableVariantFactory,
        private readonly string $repeatableVariantClass,
    ) {
    }

    public function isRepeatable(ProductVariantInterface $productVariant): bool
    {
        return null !== $this->find($productVariant);
    }

    public function filterRepeatable(iterable $productVariants): array
    {
        $saved = [];
        foreach ($productVariants as $productVariant) {
            if (null !== $productVariant->getId()) {
                $saved[] = $productVariant;
            }
        }
        if ([] === $saved) {
            return [];
        }

        /** @var list<RepeatableVariantInterface> $marks */
        $marks = $this->entityManager->createQueryBuilder()
            ->select('mark')
            ->from($this->repeatableVariantClass, 'mark')
            ->where('mark.productVariant IN (:productVariants)')
            ->setParameter('productVariants', $saved)
            ->getQuery()
            ->getResult()
        ;
        $repeatable = array_map(static fn (RepeatableVariantInterface $mark): ?ProductVariantInterface => $mark->getProductVariant(), $marks);

        return array_values(array_filter(
            $saved,
            static fn (ProductVariantInterface $productVariant): bool => in_array($productVariant, $repeatable, true),
        ));
    }

    public function findAllRepeatable(): array
    {
        /** @var list<RepeatableVariantInterface> $marks */
        $marks = $this->entityManager->getRepository($this->repeatableVariantClass)->findBy([], ['id' => 'ASC']);

        return array_values(array_filter(array_map(
            static fn (RepeatableVariantInterface $mark): ?ProductVariantInterface => $mark->getProductVariant(),
            $marks,
        )));
    }

    public function markRepeatable(ProductVariantInterface $productVariant, bool $repeatable): void
    {
        $mark = $this->find($productVariant);
        if ($repeatable && null === $mark) {
            $mark = $this->repeatableVariantFactory->createNew();
            $mark->setProductVariant($productVariant);
            $this->entityManager->persist($mark);
        }

        if (!$repeatable && null !== $mark) {
            $this->entityManager->remove($mark);
        }
    }

    private function find(ProductVariantInterface $productVariant): ?RepeatableVariantInterface
    {
        if (null === $productVariant->getId()) {
            return null;
        }

        return $this->entityManager->getRepository($this->repeatableVariantClass)->findOneBy(['productVariant' => $productVariant]);
    }
}
