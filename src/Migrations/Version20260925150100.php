<?php

declare(strict_types=1);

namespace JpmMartin\SyliusSubscriptionPlugin\Migrations;

use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Second half: nothing says why a subscription already suspended was, so none counts as suspended
 * for unpaid renewals, and its customer cannot recover it until it is suspended that way again.
 */
final class Version20260925150100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Marks every existing subscription as not suspended for unpaid renewals and requires the flag.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('UPDATE jpm_martin_sylius_subscription SET suspended_for_unpaid_renewals = ?', [false], [ParameterType::BOOLEAN]);

        // The default is set to none on purpose: on MariaDB taken for MySQL (a serverVersion without
        // "mariadb"), a nullable column's default is read back as the string 'NULL' and kept otherwise.
        $schema->getTable('jpm_martin_sylius_subscription')->modifyColumn('suspended_for_unpaid_renewals', ['notnull' => true, 'default' => null]);
    }

    public function down(Schema $schema): void
    {
        $schema->getTable('jpm_martin_sylius_subscription')->modifyColumn('suspended_for_unpaid_renewals', ['notnull' => false, 'default' => null]);
    }
}
