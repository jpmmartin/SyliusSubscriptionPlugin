<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Whether a subscription was suspended after cycles failed in a row, which its customer can recover
 * from by paying, rather than by an administrator. The next migration fills it and requires it.
 */
final class Version20260925150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds whether each subscription was suspended after cycles failed in a row.';
    }

    public function up(Schema $schema): void
    {
        $schema->getTable('jpm_martin_sylius_subscription')->addColumn('suspended_for_failed_cycles', 'boolean', ['notnull' => false]);
    }

    public function down(Schema $schema): void
    {
        $schema->getTable('jpm_martin_sylius_subscription')->dropColumn('suspended_for_failed_cycles');
    }
}
