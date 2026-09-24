<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\Migrations\AbstractMigration;

/**
 * First half of turning one subscription per order line into subscriptions of several items: the
 * new tables, and the new columns still nullable. The next migration fills them from the existing
 * subscriptions, then makes them mandatory and drops what moved to the items.
 */
final class Version20260923120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds the subscription items and cycle items, and the consecutive failures and manual retry columns, still nullable.';
    }

    public function up(Schema $schema): void
    {
        $this->createItemTable($schema);
        $this->createCycleItemTable($schema);

        $schema->getTable('jpm_martin_sylius_subscription')->addColumn('consecutive_failed_cycles', 'integer', ['notnull' => false]);
        $schema->getTable('jpm_martin_sylius_subscription_cycle')->addColumn('manual_retry', 'boolean', ['notnull' => false]);
    }

    /**
     * The next migration's down() has put the subscription's own columns back, empty. They are filled
     * from each subscription's only item before the items go: Doctrine runs these statements before
     * the SQL of the schema changes below.
     */
    public function down(Schema $schema): void
    {
        foreach (['product_variant_id', 'plan_id', 'origin_order_item_id', 'quantity', 'unit_price'] as $column) {
            $this->addSql(\sprintf(
                'UPDATE jpm_martin_sylius_subscription SET %1$s = (SELECT i.%1$s FROM jpm_martin_sylius_subscription_item i WHERE i.subscription_id = jpm_martin_sylius_subscription.id)',
                $column,
            ));
        }
        $this->addSql('UPDATE jpm_martin_sylius_subscription SET failed_cycles_count = 0');

        $subscription = $schema->getTable('jpm_martin_sylius_subscription');
        // The default is set to none on purpose: on MariaDB taken for MySQL (a serverVersion without
        // "mariadb"), a nullable column's default is read back as the string 'NULL' and kept otherwise.
        foreach (['product_variant_id', 'plan_id', 'quantity', 'unit_price', 'failed_cycles_count'] as $column) {
            $subscription->modifyColumn($column, ['notnull' => true, 'default' => null]);
        }
        $subscription->dropColumn('consecutive_failed_cycles');
        $schema->getTable('jpm_martin_sylius_subscription_cycle')->dropColumn('manual_retry');

        $schema->dropTable('jpm_martin_sylius_subscription_cycle_item');
        $schema->dropTable('jpm_martin_sylius_subscription_item');
    }

    /** An item goes with its subscription; its variant and plan are restricted, like the subscription's were. */
    private function createItemTable(Schema $schema): void
    {
        $table = $this->createTable($schema, 'jpm_martin_sylius_subscription_item');

        $table->addColumn('id', 'integer', ['autoincrement' => true]);
        $table->addColumn('subscription_id', 'integer');
        $table->addColumn('product_variant_id', 'integer');
        $table->addColumn('plan_id', 'integer', ['notnull' => false]);
        $table->addColumn('origin_order_item_id', 'integer', ['notnull' => false]);
        $table->addColumn('quantity', 'integer');
        $table->addColumn('unit_price', 'integer');
        $table->addColumn('paid_cycles', 'integer');
        $table->setPrimaryKey(['id']);

        $table->addForeignKeyConstraint('jpm_martin_sylius_subscription', ['subscription_id'], ['id'], ['onDelete' => 'CASCADE']);
        $table->addForeignKeyConstraint('sylius_product_variant', ['product_variant_id'], ['id']);
        $table->addForeignKeyConstraint('jpm_martin_sylius_subscription_plan', ['plan_id'], ['id']);
        $table->addForeignKeyConstraint('sylius_order_item', ['origin_order_item_id'], ['id'], ['onDelete' => 'SET NULL']);
    }

    private function createCycleItemTable(Schema $schema): void
    {
        $table = $this->createTable($schema, 'jpm_martin_sylius_subscription_cycle_item');

        $table->addColumn('id', 'integer', ['autoincrement' => true]);
        $table->addColumn('cycle_id', 'integer');
        $table->addColumn('subscription_item_id', 'integer');
        $table->addColumn('quantity', 'integer');
        $table->addColumn('unit_price', 'integer');
        $table->addColumn('skipped_reason', 'string', ['length' => 32, 'notnull' => false]);
        $table->setPrimaryKey(['id']);

        $table->addForeignKeyConstraint('jpm_martin_sylius_subscription_cycle', ['cycle_id'], ['id'], ['onDelete' => 'CASCADE']);
        $table->addForeignKeyConstraint('jpm_martin_sylius_subscription_item', ['subscription_item_id'], ['id'], ['onDelete' => 'CASCADE']);
    }

    /**
     * In utf8mb4, as Sylius's own tables are, so text takes any character, emojis included; left to
     * itself, Doctrine would create a MySQL or MariaDB table in utf8mb3. PostgreSQL ignores both.
     */
    private function createTable(Schema $schema, string $name): Table
    {
        $table = $schema->createTable($name);
        $table->addOption('charset', 'utf8mb4');
        $table->addOption('collation', 'utf8mb4_unicode_ci');

        return $table;
    }
}
