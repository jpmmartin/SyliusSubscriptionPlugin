<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/** When each cycle's renewal was announced, so it is announced once; no cycle has been announced before. */
final class Version20260924120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds when the renewal of each subscription cycle was announced.';
    }

    public function up(Schema $schema): void
    {
        $schema->getTable('jpm_martin_sylius_subscription_cycle')->addColumn('renewal_notice_at', 'datetime_immutable', ['notnull' => false]);
    }

    public function down(Schema $schema): void
    {
        $schema->getTable('jpm_martin_sylius_subscription_cycle')->dropColumn('renewal_notice_at');
    }
}
