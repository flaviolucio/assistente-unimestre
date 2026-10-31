<?php

declare(strict_types=1);

use App\Core\Application;

require dirname(__DIR__) . '/bootstrap/app.php';

$application = new Application();
$application->run();