<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Integration\Migrations;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use JpmMartin\SyliusSubscriptionPlugin\Entity\CartFrequency;
use JpmMartin\SyliusSubscriptionPlugin\Entity\RepeatableVariant;
use JpmMartin\SyliusSubscriptionPlugin\Entity\Subscription;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionChargeAttempt;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionConsent;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionCycle;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionCycleItem;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionFrequency;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionItem;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionPlan;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Tests\JpmMartin\SyliusSubscriptionPlugin\Entity\OrderItem;

/**
 * The mapping is the truth; the migration is held to it here.
 *
 * For each table the plugin creates or changes, the table the ORM expects from the mapping is
 * compared with the table that actually exists, and any statement the platform would need to bring
 * the second in line with the first is a failure: a column, a default, an index, a foreign-key
 * rule. It is the comparison `doctrine:schema:validate` performs, narrowed to these tables so that
 * whatever Sylius's own schema does elsewhere is not this test's verdict.
 *
 * The order item is Sylius's table with the plugin's plan and frequency columns added; comparing the
 * whole table through the test application's OrderItem, which uses SubscriptionPlanAwareTrait,
 * covers those columns the way a store maps them.
 *
 * It means something only on a database the migrations built (`doctrine:migrations:migrate`). On a
 * database made by `doctrine:schema:create` it passes without proving anything about the migration.
 */
final class MigrationMatchesMappingTest extends KernelTestCase
{
    private EntityManagerInterface $manager;

    protected function setUp(): void
    {
        self::bootKernel();

        /** @var EntityManagerInterface $manager */
        $manager = self::getContainer()->get('doctrine.orm.default_entity_manager');
        $this->manager = $manager;
    }

    /**
     * @dataProvider entities
     *
     * @param class-string $entity
     * @param string|null $table a table of the entity's other than its own, such as a join table
     */
    public function testTheTableTheMigrationBuiltIsTheOneTheMappingDescribes(string $entity, ?string $table = null): void
    {
        $metadata = $this->manager->getClassMetadata($entity);
        $tableName = $table ?? $metadata->getTableName();
        $expected = (new SchemaTool($this->manager))->getSchemaFromMetadata([$metadata])->getTable($tableName);

        $connection = $this->manager->getConnection();
        $schemaManager = $connection->createSchemaManager();
        self::assertTrue(
            $schemaManager->tablesExist([$tableName]),
            \sprintf('The table %s does not exist. Was the database built by the migrations?', $tableName),
        );
        $actual = $schemaManager->introspectTable($tableName);

        $difference = $schemaManager->createComparator()->compareTables($actual, $expected);

        self::assertSame(
            [],
            $connection->getDatabasePlatform()->getAlterTableSQL($difference),
            \sprintf('%s differs from what the mapping of %s describes; the statements above are what it would take to match.', $tableName, $entity),
        );
    }

    /** @return iterable<string, array{0: class-string, 1?: string}> */
    public static function entities(): iterable
    {
        yield 'the plans' => [SubscriptionPlan::class];
        yield 'the subscriptions' => [Subscription::class];
        yield 'the subscription items' => [SubscriptionItem::class];
        yield 'the cycles' => [SubscriptionCycle::class];
        yield 'the cycle items' => [SubscriptionCycleItem::class];
        yield 'the charge attempts' => [SubscriptionChargeAttempt::class];
        yield 'the consents' => [SubscriptionConsent::class];
        yield 'the frequencies' => [SubscriptionFrequency::class];
        yield 'the channels of the frequencies' => [SubscriptionFrequency::class, 'jpm_martin_sylius_subscription_frequency_channels'];
        yield 'the repeatable variants' => [RepeatableVariant::class];
        yield 'the cart frequencies' => [CartFrequency::class];
        yield 'the order item and its plan and frequency columns' => [OrderItem::class];
    }
}
