<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Migrations;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\Migrations\AbstractMigration;

/**
 * The shipping and billing addresses a subscription renews with, once they are changed. No subscription
 * has them before: its renewals keep taking its last order's.
 */
final class Version20260925130000 extends AbstractMigration
{
    private const COLUMNS = ['shipping_address_id', 'billing_address_id'];

    public function getDescription(): string
    {
        return 'Adds the shipping and billing addresses of a subscription.';
    }

    public function up(Schema $schema): void
    {
        $subscription = $schema->getTable('jpm_martin_sylius_subscription');
        foreach (self::COLUMNS as $column) {
            $subscription->addColumn($column, 'integer', ['notnull' => false]);
            $subscription->addUniqueIndex([$column]);
            $subscription->addForeignKeyConstraint('sylius_address', [$column], ['id']);
        }
    }

    /**
     * The addresses belong to the subscriptions alone, so they go too. Doctrine runs the statements added
     * with addSql() before the SQL of the schema changes: the subscriptions let go of them first, then they
     * are deleted, and only then are the columns dropped.
     */
    public function down(Schema $schema): void
    {
        /** @var list<int|string> $addressIds */
        $addressIds = $this->connection->fetchFirstColumn(
            'SELECT shipping_address_id FROM jpm_martin_sylius_subscription WHERE shipping_address_id IS NOT NULL
             UNION SELECT billing_address_id FROM jpm_martin_sylius_subscription WHERE billing_address_id IS NOT NULL',
        );
        if ([] !== $addressIds) {
            $this->addSql('UPDATE jpm_martin_sylius_subscription SET shipping_address_id = NULL, billing_address_id = NULL');
            $this->addSql(
                'DELETE FROM sylius_address WHERE id IN (?)',
                [array_map('intval', $addressIds)],
                [ArrayParameterType::INTEGER],
            );
        }

        $subscription = $schema->getTable('jpm_martin_sylius_subscription');
        foreach (self::COLUMNS as $column) {
            $this->dropReference($subscription, $column);
        }
    }

    private function dropReference(Table $table, string $column): void
    {
        foreach ($table->getForeignKeys() as $foreignKey) {
            if ($foreignKey->getLocalColumns() === [$column]) {
                $table->removeForeignKey($foreignKey->getName());
            }
        }
        foreach ($table->getIndexes() as $index) {
            if ($index->getColumns() === [$column]) {
                $table->dropIndex($index->getName());
            }
        }
        $table->dropColumn($column);
    }
}
