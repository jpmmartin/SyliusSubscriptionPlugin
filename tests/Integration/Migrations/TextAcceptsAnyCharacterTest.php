<?php

declare(strict_types=1);

namespace Tests\JpmMartin\SyliusSubscriptionPlugin\Integration\Migrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\ORM\EntityManagerInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionFrequencyInterface;
use JpmMartin\SyliusSubscriptionPlugin\Entity\SubscriptionIntervalUnit;
use Sylius\Behat\Context\Hook\DoctrineORMContext;
use Sylius\Behat\Context\Setup\ChannelContext;
use Sylius\Behat\Service\SharedStorageInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Text in the plugin's tables takes any character, emojis included, as Sylius's own tables do. On
 * MySQL and MariaDB that depends on each table's character set, which the plugin's migrations set;
 * the connection's is the store's, and a store that stores emojis anywhere runs it in utf8mb4.
 *
 * It means something only on a database the migrations built (`doctrine:migrations:migrate`).
 */
final class TextAcceptsAnyCharacterTest extends KernelTestCase
{
    public function testEveryTextColumnOfThePluginIsInUtf8mb4OnMySqlAndMariaDb(): void
    {
        $connection = $this->entityManager()->getConnection();
        if (!$connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            self::markTestSkipped('Only MySQL and MariaDB keep a character set for each table.');
        }

        /** @var list<array{TABLE_NAME: string, COLUMN_NAME: string, COLLATION_NAME: string}> $columns */
        $columns = $connection->fetchAllAssociative(
            'SELECT TABLE_NAME, COLUMN_NAME, COLLATION_NAME FROM information_schema.COLUMNS'
            . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE ? AND COLLATION_NAME IS NOT NULL'
            . ' ORDER BY TABLE_NAME, COLUMN_NAME',
            ['jpm_martin_sylius_subscription%'],
        );
        self::assertNotEmpty($columns, 'The plugin has no text column here. Was the database built by the migrations?');

        $narrow = [];
        foreach ($columns as $column) {
            if (!str_starts_with($column['COLLATION_NAME'], 'utf8mb4')) {
                $narrow[] = \sprintf('%s.%s (%s)', $column['TABLE_NAME'], $column['COLUMN_NAME'], $column['COLLATION_NAME']);
            }
        }

        self::assertSame([], $narrow, 'These columns cannot hold four-byte characters such as emojis.');
    }

    public function testAFrequencyNamedWithAnEmojiIsReadBackAsItWasWritten(): void
    {
        $container = self::getContainer();
        /** @var DoctrineORMContext $database */
        $database = $container->get('sylius.behat.context.hook.doctrine_orm');
        $database->purgeDatabase();
        /** @var ChannelContext $channels */
        $channels = $container->get('sylius.behat.context.setup.channel');
        $channels->storeOperatesOnASingleChannelInUnitedStates();
        /** @var SharedStorageInterface $sharedStorage */
        $sharedStorage = $container->get('sylius.behat.shared_storage');
        /** @var ChannelInterface $channel */
        $channel = $sharedStorage->get('channel');

        $entityManager = $this->entityManager();
        $connection = $entityManager->getConnection();
        if ($connection->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            // As a store that keeps emojis anywhere, Sylius's tables included, runs its connection.
            $connection->executeStatement('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci');
        }

        /** @var FactoryInterface<SubscriptionFrequencyInterface> $factory */
        $factory = $container->get('jpm_martin_sylius_subscription.factory.subscription_frequency');
        $frequency = $factory->createNew();
        $frequency->setCode('MONTHLY');
        $frequency->setName('Mensual 🚚');
        $frequency->setIntervalCount(1);
        $frequency->setIntervalUnit(SubscriptionIntervalUnit::Month);
        $frequency->setDiscountPercentage(5);
        $frequency->addChannel($channel);
        $entityManager->persist($frequency);
        $entityManager->flush();
        $id = $frequency->getId();
        $entityManager->clear();

        $stored = $entityManager->find($frequency::class, $id);
        self::assertInstanceOf(SubscriptionFrequencyInterface::class, $stored);
        self::assertSame('Mensual 🚚', $stored->getName());
    }

    private function entityManager(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $entityManager */
        $entityManager = self::getContainer()->get('doctrine.orm.entity_manager');

        return $entityManager;
    }
}
