<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Schema\Table;
use Doctrine\Migrations\AbstractMigration;

/**
 * The store's frequencies, the variants that may be repeated with them and the frequency a cart is
 * repeated with; and the frequency of a subscription item and of an order line, next to their plan.
 * Nothing existing changes: every current line and item keeps its plan.
 */
final class Version20260923120300 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds the subscription frequencies, the repeatable variants, the cart frequencies and the frequency of subscription items and order lines.';
    }

    public function up(Schema $schema): void
    {
        $frequency = $this->createTable($schema, 'jpm_martin_sylius_subscription_frequency');
        $frequency->addColumn('id', 'integer', ['autoincrement' => true]);
        $frequency->addColumn('code', 'string', ['length' => 255]);
        $frequency->addColumn('name', 'string', ['length' => 255]);
        $frequency->addColumn('interval_count', 'integer');
        $frequency->addColumn('interval_unit', 'string', ['length' => 8]);
        $frequency->addColumn('discount_percentage', 'integer');
        $frequency->addColumn('max_cycles', 'integer', ['notnull' => false]);
        $frequency->addColumn('enabled', 'boolean');
        $frequency->setPrimaryKey(['id']);
        $frequency->addUniqueIndex(['code'], 'uniq_jpm_martin_sylius_subscription_frequency_code');

        $channels = $this->createTable($schema, 'jpm_martin_sylius_subscription_frequency_channels');
        $channels->addColumn('frequency_id', 'integer');
        $channels->addColumn('channel_id', 'integer');
        $channels->setPrimaryKey(['frequency_id', 'channel_id']);
        $channels->addForeignKeyConstraint('jpm_martin_sylius_subscription_frequency', ['frequency_id'], ['id'], ['onDelete' => 'CASCADE']);
        $channels->addForeignKeyConstraint('sylius_channel', ['channel_id'], ['id'], ['onDelete' => 'CASCADE']);

        $repeatable = $this->createTable($schema, 'jpm_martin_sylius_subscription_repeatable_variant');
        $repeatable->addColumn('id', 'integer', ['autoincrement' => true]);
        $repeatable->addColumn('product_variant_id', 'integer');
        $repeatable->setPrimaryKey(['id']);
        $repeatable->addUniqueIndex(['product_variant_id']);
        $repeatable->addForeignKeyConstraint('sylius_product_variant', ['product_variant_id'], ['id'], ['onDelete' => 'CASCADE']);

        $cart = $this->createTable($schema, 'jpm_martin_sylius_subscription_cart_frequency');
        $cart->addColumn('id', 'integer', ['autoincrement' => true]);
        $cart->addColumn('order_id', 'integer');
        $cart->addColumn('frequency_id', 'integer');
        $cart->setPrimaryKey(['id']);
        $cart->addUniqueIndex(['order_id']);
        $cart->addForeignKeyConstraint('sylius_order', ['order_id'], ['id'], ['onDelete' => 'CASCADE']);
        $cart->addForeignKeyConstraint('jpm_martin_sylius_subscription_frequency', ['frequency_id'], ['id']);

        // Restricted, as the plan is: a frequency something points at is disabled, not deleted.
        $item = $schema->getTable('jpm_martin_sylius_subscription_item');
        $item->addColumn('frequency_id', 'integer', ['notnull' => false]);
        $item->addForeignKeyConstraint('jpm_martin_sylius_subscription_frequency', ['frequency_id'], ['id']);

        $orderItem = $schema->getTable('sylius_order_item');
        $orderItem->addColumn('subscription_frequency_id', 'integer', ['notnull' => false]);
        $orderItem->addForeignKeyConstraint('jpm_martin_sylius_subscription_frequency', ['subscription_frequency_id'], ['id']);
    }

    public function down(Schema $schema): void
    {
        $this->dropReference($schema->getTable('sylius_order_item'), 'subscription_frequency_id');
        $this->dropReference($schema->getTable('jpm_martin_sylius_subscription_item'), 'frequency_id');

        $schema->dropTable('jpm_martin_sylius_subscription_cart_frequency');
        $schema->dropTable('jpm_martin_sylius_subscription_repeatable_variant');
        $schema->dropTable('jpm_martin_sylius_subscription_frequency_channels');
        $schema->dropTable('jpm_martin_sylius_subscription_frequency');
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
