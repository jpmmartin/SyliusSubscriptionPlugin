<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The free trial of plans and frequencies, in days, the one each order line was given, and the one each
 * subscription started with. None had one before.
 */
final class Version20260928140000 extends AbstractMigration
{
    private const TABLES = ['jpm_martin_sylius_subscription_plan', 'jpm_martin_sylius_subscription_frequency', 'jpm_martin_sylius_subscription'];

    public function getDescription(): string
    {
        return 'Adds the free trial days of subscription plans, frequencies, order lines and subscriptions.';
    }

    public function up(Schema $schema): void
    {
        foreach (self::TABLES as $table) {
            $schema->getTable($table)->addColumn('trial_days', 'integer', ['notnull' => false]);
        }
        $schema->getTable('sylius_order_item')->addColumn('subscription_trial_days', 'integer', ['notnull' => false]);
    }

    /** A subscription still pending with a free trial would be activated without it: activate them first. */
    public function down(Schema $schema): void
    {
        foreach (self::TABLES as $table) {
            $schema->getTable($table)->dropColumn('trial_days');
        }
        $schema->getTable('sylius_order_item')->dropColumn('subscription_trial_days');
    }
}
