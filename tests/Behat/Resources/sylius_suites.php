<?php

declare(strict_types=1);

use Behat\Config\Config;

// Sylius's own suites: in suites.yml up to Sylius 2.2, in suites.php from 2.3, which moved its Behat
// configuration to PHP. A YAML import cannot choose between them.
$suites = __DIR__ . '/../../../vendor/sylius/sylius/src/Sylius/Behat/Resources/config/suites';

return (new Config())->import(is_file($suites . '.php') ? $suites . '.php' : $suites . '.yml');
