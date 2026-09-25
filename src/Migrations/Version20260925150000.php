<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Whether a subscription was suspended because its renewals could not be charged, which its customer
 * can recover from by paying. The next migration fills it and requires it.
 */
final class Version20260925150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds whether each subscription was suspended because its renewals could not be charged.';
    }

    public function up(Schema $schema): void
    {
        $schema->getTable('jpm_martin_sylius_subscription')->addColumn('suspended_for_unpaid_renewals', 'boolean', ['notnull' => false]);
    }

    public function down(Schema $schema): void
    {
        $schema->getTable('jpm_martin_sylius_subscription')->dropColumn('suspended_for_unpaid_renewals');
    }
}
