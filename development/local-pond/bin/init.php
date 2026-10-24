<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/bootstrap.php';
Respawn\LocalPond\LocalPondSetup::initialise(localDemoRoot(), localFixturePath());
echo "Development Pond runtime initialized privately.\n";