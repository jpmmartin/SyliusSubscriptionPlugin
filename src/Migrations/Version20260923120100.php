<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Migrations;

use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\Migrations\AbstractMigration;

/**
 * Second half: every existing subscription becomes a subscription of one item. Doctrine runs the
 * statements added with addSql() before the SQL of the schema changes, so the data is copied while the
 * subscription still has its own columns, and only then are they dropped.
 */
final class Version20260923120100 extends AbstractMigration
{
    /** What moves from the subscription to its items. */
    private const MOVED_REFERENCES = [
        'product_variant_id' => ['sylius_product_variant', null],
        'plan_id' => ['jpm_martin_sylius_subscription_plan', null],
        'origin_order_item_id' => ['sylius_order_item', 'SET NULL'],
    ];

    public function getDescription(): string
    {
        return 'Turns each subscription into a subscription of one item, and drops the columns that moved to the items.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT INTO jpm_martin_sylius_subscription_item (subscription_id, product_variant_id, plan_id, origin_order_item_id, quantity, unit_price, paid_cycles)
            SELECT s.id, s.product_variant_id, s.plan_id, s.origin_order_item_id, s.quantity, s.unit_price,
                (SELECT COUNT(*) FROM jpm_martin_sylius_subscription_cycle c WHERE c.subscription_id = s.id AND c.state = 'paid')
            FROM jpm_martin_sylius_subscription s
            SQL);
        // Every cycle that placed an order carried the subscription's only item.
        $this->addSql(<<<'SQL'
            INSERT INTO jpm_martin_sylius_subscription_cycle_item (cycle_id, subscription_item_id, quantity, unit_price)
            SELECT c.id, i.id, i.quantity, i.unit_price
            FROM jpm_martin_sylius_subscription_cycle c
            INNER JOIN jpm_martin_sylius_subscription_item i ON i.subscription_id = c.subscription_id
            WHERE c.order_id IS NOT NULL
            SQL);
        // The old count was over the subscription's whole life; the new one starts afresh.
        $this->addSql('UPDATE jpm_martin_sylius_subscription SET consecutive_failed_cycles = 0');
        $this->addSql('UPDATE jpm_martin_sylius_subscription_cycle SET manual_retry = ?', [false], [ParameterType::BOOLEAN]);

        $subscription = $schema->getTable('jpm_martin_sylius_subscription');
        $subscription->modifyColumn('consecutive_failed_cycles', ['notnull' => true]);
        foreach (array_keys(self::MOVED_REFERENCES) as $column) {
            $this->dropReference($subscription, $column);
        }
        foreach (['quantity', 'unit_price', 'failed_cycles_count'] as $column) {
            $subscription->dropColumn($column);
        }

        $schema->getTable('jpm_martin_sylius_subscription_cycle')->modifyColumn('manual_retry', ['notnull' => true]);
    }

    /** Only a subscription of one item, on a plan, fits back into one subscription per line. */
    public function preDown(Schema $schema): void
    {
        $this->abortIf(
            0 < $this->count('SELECT COUNT(*) FROM (SELECT subscription_id FROM jpm_martin_sylius_subscription_item GROUP BY subscription_id HAVING COUNT(*) > 1) several'),
            'Some subscriptions have more than one item, so they cannot go back to one subscription per order line.',
        );
        $this->abortIf(
            0 < $this->count('SELECT COUNT(*) FROM jpm_martin_sylius_subscription_item WHERE plan_id IS NULL'),
            'Some subscription items follow no plan, which one subscription per order line cannot express.',
        );
    }

    /** The subscription's own columns come back empty; the previous migration's down() fills them. */
    public function down(Schema $schema): void
    {
        $subscription = $schema->getTable('jpm_martin_sylius_subscription');
        foreach (self::MOVED_REFERENCES as $column => [$foreignTable, $onDelete]) {
            $subscription->addColumn($column, 'integer', ['notnull' => false]);
            $subscription->addForeignKeyConstraint($foreignTable, [$column], ['id'], null === $onDelete ? [] : ['onDelete' => $onDelete]);
        }
        foreach (['quantity', 'unit_price', 'failed_cycles_count'] as $column) {
            $subscription->addColumn($column, 'integer', ['notnull' => false]);
        }
        $subscription->modifyColumn('consecutive_failed_cycles', ['notnull' => false]);

        $schema->getTable('jpm_martin_sylius_subscription_cycle')->modifyColumn('manual_retry', ['notnull' => false]);
    }

    private function count(string $sql): int
    {
        $count = $this->connection->fetchOne($sql);

        return is_numeric($count) ? (int) $count : 0;
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
