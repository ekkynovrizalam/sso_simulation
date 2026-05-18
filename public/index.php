<?php

declare(strict_types=1);

use Iae\Central\AppBootstrap;

require __DIR__ . '/../vendor/autoload.php';

$app = AppBootstrap::create();
$app->run();
