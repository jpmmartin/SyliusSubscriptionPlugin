<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\Migrations\AbstractMigration;

/**
 * The plugin's schema, described once and for every engine Sylius runs on.
 *
 * Nothing here is SQL. The tables are built on the schema object Doctrine hands to `up()`, and
 * Doctrine derives the statements for the connected engine when the migration runs: identity
 * columns, text types and the column comments the ORM needs differ between MySQL, MariaDB and
 * PostgreSQL, and there is no copy per engine to keep in step.
 *
 * The mapping in `config/doctrine/model/` is the truth this file is held to: an integration test
 * compares the tables this migration builds with the ones the ORM expects and fails on any
 * difference. Foreign keys are added without naming their index, so Doctrine derives the same
 * implicit index names the ORM does.
 *
 * Besides its own tables, the plugin adds one column to Sylius's `sylius_order_item`: the plan a
 * line was added with. Every store installing the plugin maps it through SubscriptionPlanAwareTrait.
 */
final class Version20260922120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Creates the subscription plans, subscriptions, cycles, charge attempts and consents, and the plan column of the order item.';
    }

    public function up(Schema $schema): void
    {
        $this->createPlanTable($schema);
        $this->addPlanToOrderItem($schema);
        $this->createSubscriptionTable($schema);
        $this->createCycleTable($schema);
        $this->createChargeAttemptTable($schema);
        $this->createConsentTable($schema);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('jpm_martin_sylius_subscription_consent');
        $schema->dropTable('jpm_martin_sylius_subscription_charge_attempt');
        $schema->dropTable('jpm_martin_sylius_subscription_cycle');
        $schema->dropTable('jpm_martin_sylius_subscription');

        $orderItem = $schema->getTable('sylius_order_item');
        foreach ($orderItem->getForeignKeys() as $foreignKey) {
            if ($foreignKey->getLocalColumns() === ['subscription_plan_id']) {
                $orderItem->removeForeignKey($foreignKey->getName());
            }
        }
        foreach ($orderItem->getIndexes() as $index) {
            if ($index->getColumns() === ['subscription_plan_id']) {
                $orderItem->dropIndex($index->getName());
            }
        }
        $orderItem->dropColumn('subscription_plan_id');

        $schema->dropTable('jpm_martin_sylius_subscription_plan');
    }

    /** A plan hangs off its variant and goes with it. */
    private function createPlanTable(Schema $schema): void
    {
        $table = $this->createTable($schema, 'jpm_martin_sylius_subscription_plan');

        $table->addColumn('id', 'integer', ['autoincrement' => true]);
        $table->addColumn('product_variant_id', 'integer');
        $table->addColumn('code', 'string', ['length' => 255]);
        $table->addColumn('name', 'string', ['length' => 255]);
        $table->addColumn('interval_count', 'integer');
        $table->addColumn('interval_unit', 'string', ['length' => 8]);
        $table->addColumn('discount_percentage', 'integer');
        $table->addColumn('max_cycles', 'integer', ['notnull' => false]);
        $table->addColumn('enabled', 'boolean');
        $table->setPrimaryKey(['id']);

        $table->addForeignKeyConstraint('sylius_product_variant', ['product_variant_id'], ['id'], ['onDelete' => 'CASCADE']);
        $table->addUniqueIndex(['code'], 'uniq_jpm_martin_sylius_subscription_plan_code');
    }

    /** Deleting a plan an order line points at is refused: a plan is disabled, not deleted. */
    private function addPlanToOrderItem(Schema $schema): void
    {
        $table = $schema->getTable('sylius_order_item');

        $table->addColumn('subscription_plan_id', 'integer', ['notnull' => false]);
        $table->addForeignKeyConstraint('jpm_martin_sylius_subscription_plan', ['subscription_plan_id'], ['id']);
    }

    /**
     * The customer, channel, variant, plan and methods are restricted rather than cascaded: a
     * subscription is a commercial record, and whatever it points at cannot vanish from under it.
     */
    private function createSubscriptionTable(Schema $schema): void
    {
        $table = $this->createTable($schema, 'jpm_martin_sylius_subscription');

        $table->addColumn('id', 'integer', ['autoincrement' => true]);
        $table->addColumn('customer_id', 'integer');
        $table->addColumn('channel_id', 'integer');
        $table->addColumn('product_variant_id', 'integer');
        $table->addColumn('plan_id', 'integer');
        $table->addColumn('payment_method_id', 'integer');
        $table->addColumn('shipping_method_id', 'integer', ['notnull' => false]);
        $table->addColumn('origin_order_item_id', 'integer', ['notnull' => false]);
        $table->addColumn('quantity', 'integer');
        $table->addColumn('unit_price', 'integer');
        $table->addColumn('currency_code', 'string', ['length' => 3]);
        $table->addColumn('billing_interval_count', 'integer');
        $table->addColumn('billing_interval_unit', 'string', ['length' => 8]);
        $table->addColumn('delivery_interval_count', 'integer');
        $table->addColumn('delivery_interval_unit', 'string', ['length' => 8]);
        $table->addColumn('consent_version', 'string', ['length' => 255, 'notnull' => false]);
        $table->addColumn('consent_text', 'text', ['notnull' => false]);
        $table->addColumn('consent_accepted_at', 'datetime_immutable', ['notnull' => false]);
        $table->addColumn('activated_at', 'datetime_immutable', ['notnull' => false]);
        $table->addColumn('schedule_anchor_at', 'datetime_immutable', ['notnull' => false]);
        $table->addColumn('schedule_anchor_cycle', 'integer');
        $table->addColumn('state', 'string', ['length' => 16]);
        $table->addColumn('failed_cycles_count', 'integer');
        $table->addColumn('created_at', 'datetime');
        $table->addColumn('updated_at', 'datetime', ['notnull' => false]);
        $table->setPrimaryKey(['id']);

        $table->addForeignKeyConstraint('sylius_customer', ['customer_id'], ['id']);
        $table->addForeignKeyConstraint('sylius_channel', ['channel_id'], ['id']);
        $table->addForeignKeyConstraint('sylius_product_variant', ['product_variant_id'], ['id']);
        $table->addForeignKeyConstraint('jpm_martin_sylius_subscription_plan', ['plan_id'], ['id']);
        $table->addForeignKeyConstraint('sylius_payment_method', ['payment_method_id'], ['id']);
        $table->addForeignKeyConstraint('sylius_shipping_method', ['shipping_method_id'], ['id']);
        $table->addForeignKeyConstraint('sylius_order_item', ['origin_order_item_id'], ['id'], ['onDelete' => 'SET NULL']);
        $table->addIndex(['state'], 'idx_jpm_martin_sylius_subscription_state');
    }

    /** A cycle goes with its subscription; losing its order keeps the cycle and its history. */
    private function createCycleTable(Schema $schema): void
    {
        $table = $this->createTable($schema, 'jpm_martin_sylius_subscription_cycle');

        $table->addColumn('id', 'integer', ['autoincrement' => true]);
        $table->addColumn('subscription_id', 'integer');
        $table->addColumn('order_id', 'integer', ['notnull' => false]);
        $table->addColumn('number', 'integer');
        $table->addColumn('scheduled_at', 'datetime_immutable');
        $table->addColumn('state', 'string', ['length' => 24]);
        $table->addColumn('hold_until', 'datetime_immutable', ['notnull' => false]);
        $table->addColumn('hold_reason', 'text', ['notnull' => false]);
        $table->addColumn('next_attempt_at', 'datetime_immutable', ['notnull' => false]);
        $table->addColumn('cancellation_reason', 'text', ['notnull' => false]);
        $table->addColumn('version', 'integer', ['default' => 1]);
        $table->addColumn('created_at', 'datetime');
        $table->addColumn('updated_at', 'datetime', ['notnull' => false]);
        $table->setPrimaryKey(['id']);

        $table->addForeignKeyConstraint('jpm_martin_sylius_subscription', ['subscription_id'], ['id'], ['onDelete' => 'CASCADE']);
        $table->addForeignKeyConstraint('sylius_order', ['order_id'], ['id'], ['onDelete' => 'SET NULL']);
        $table->addUniqueIndex(['subscription_id', 'number'], 'uniq_jpm_martin_sylius_subscription_cycle_number');
        $table->addIndex(['state', 'scheduled_at'], 'idx_jpm_martin_sylius_subscription_cycle_due');
        $table->addIndex(['state', 'next_attempt_at'], 'idx_jpm_martin_sylius_subscription_cycle_next_attempt');
    }

    private function createChargeAttemptTable(Schema $schema): void
    {
        $table = $this->createTable($schema, 'jpm_martin_sylius_subscription_charge_attempt');

        $table->addColumn('id', 'integer', ['autoincrement' => true]);
        $table->addColumn('cycle_id', 'integer');
        $table->addColumn('payment_id', 'integer', ['notnull' => false]);
        $table->addColumn('type', 'string', ['length' => 16]);
        $table->addColumn('outcome', 'string', ['length' => 16]);
        $table->addColumn('reason', 'text', ['notnull' => false]);
        $table->addColumn('attempted_at', 'datetime_immutable');
        $table->setPrimaryKey(['id']);

        $table->addForeignKeyConstraint('jpm_martin_sylius_subscription_cycle', ['cycle_id'], ['id'], ['onDelete' => 'CASCADE']);
        $table->addForeignKeyConstraint('sylius_payment', ['payment_id'], ['id'], ['onDelete' => 'SET NULL']);
    }

    private function createConsentTable(Schema $schema): void
    {
        $table = $this->createTable($schema, 'jpm_martin_sylius_subscription_consent');

        $table->addColumn('id', 'integer', ['autoincrement' => true]);
        $table->addColumn('order_id', 'integer');
        $table->addColumn('text_version', 'string', ['length' => 255]);
        $table->addColumn('text', 'text');
        $table->addColumn('accepted_at', 'datetime_immutable');
        $table->setPrimaryKey(['id']);

        $table->addForeignKeyConstraint('sylius_order', ['order_id'], ['id'], ['onDelete' => 'CASCADE']);
        $table->addUniqueIndex(['order_id'], 'uniq_jpm_martin_sylius_subscription_consent_order');
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
