#!/usr/bin/env php
<?php

declare(strict_types=1);

/*
 * Takes the plugin's migrations down, newest first, and up again, on the database DATABASE_URL points
 * at. Run it on a freshly migrated database: going down refuses to lose subscriptions it cannot
 * express, so a database with test data left in it may stop it on purpose.
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

$run(['doctrine:migrations:migrate', '--no-interaction', '--allow-no-migration']);
