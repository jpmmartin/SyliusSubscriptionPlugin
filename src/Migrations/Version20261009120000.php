<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * One row per cart line whose subscription was chosen on the line itself (choose-subscription-per-cart-line, D1):
 * compra única or a store frequency of its own, instead of following "repeat this cart". The frequency stays on the
 * line, as it already did; this table only marks which lines own their choice. Nothing existing changes: a line
 * without a row keeps following the frequency of its cart, as every line does today.
 */
final class Version20261009120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds the cart lines whose subscription was chosen on the line itself.';
    }

    public function up(Schema $schema): void
    {
        $choice = $schema->createTable('jpm_martin_sylius_subscription_cart_item_choice');
        $choice->addOption('charset', 'utf8mb4');
        $choice->addOption('collation', 'utf8mb4_unicode_ci');
        $choice->addColumn('id', 'integer', ['autoincrement' => true]);
        $choice->addColumn('order_item_id', 'integer');
        $choice->setPrimaryKey(['id']);
        $choice->addUniqueIndex(['order_item_id']);
        $choice->addForeignKeyConstraint('sylius_order_item', ['order_item_id'], ['id'], ['onDelete' => 'CASCADE']);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('jpm_martin_sylius_subscription_cart_item_choice');
    }
}
