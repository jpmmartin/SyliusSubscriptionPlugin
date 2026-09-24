#!/usr/bin/env php
<?php

declare(strict_types=1);

/*
 * Takes the plugin's migrations down, newest first, checks that nothing of the plugin is left, and
 * takes them up again, on the database DATABASE_URL points at. Run it on a freshly migrated
 * database: going down refuses to lose subscriptions it cannot express, so a database with test data
 * left in it may stop it on purpose.
 */

$root = dirname(__DIR__);
$console = $root . '/vendor/bin/console';

$versions = array_map(
    static fn (string $file): string => 'JpmMartin\\SyliusSubscriptionPlugin\\Migrations\\' . basename($file, '.php'),
    glob($root . '/src/Migrations/Version*.php') ?: [],
);
rsort($versions);

if ([] === $versions) {
    fwrite(\STDERR, "No migrations found in src/Migrations.\n");
    exit(1);
}

$run = static function (array $arguments) use ($console): void {
    $command = implode(' ', array_map('escapeshellarg', array_merge([\PHP_BINARY, $console], $arguments)));
    echo '> ', implode(' ', $arguments), \PHP_EOL;
    passthru($command, $exitCode);
    if (0 !== $exitCode) {
        exit($exitCode);
    }
};

foreach ($versions as $version) {
    $run(['doctrine:migrations:execute', '--down', '--no-interaction', $version]);
}

// Down means gone: no table of the plugin and none of its columns on Sylius's order items. The
// database is found the way the console commands above find it, through the test application.
require $root . '/vendor/autoload.php';
require $root . '/vendor/sylius/test-application/config/bootstrap.php';
$kernel = new Sylius\TestApplication\Kernel((string) $_SERVER['APP_ENV'], (bool) $_SERVER['APP_DEBUG']);
$kernel->boot();
/** @var Doctrine\Persistence\ManagerRegistry $doctrine */
$doctrine = $kernel->getContainer()->get('doctrine');
/** @var Doctrine\DBAL\Connection $connection */
$connection = $doctrine->getConnection();
$schemaManager = $connection->createSchemaManager();
$left = array_values(array_filter(
    $schemaManager->listTableNames(),
    static fn (string $table): bool => str_contains($table, 'jpm_martin_sylius_subscription'),
));
foreach (array_keys($schemaManager->listTableColumns('sylius_order_item')) as $column) {
    if (in_array($column, ['subscription_plan_id', 'subscription_frequency_id'], true)) {
        $left[] = 'sylius_order_item.' . $column;
    }
}
$kernel->shutdown();
if ([] !== $left) {
    fwrite(\STDERR, 'Taking the migrations down left behind: ' . implode(', ', $left) . \PHP_EOL);
    exit(1);
}
echo '> nothing of the plugin is left in the database', \PHP_EOL;

$run(['doctrine:migrations:migrate', '--no-interaction', '--allow-no-migration']);
