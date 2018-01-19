<?php

include __DIR__ . '/../lib/Space48/PlatformSh/Magento/DemoProvisioner.php';

use Space48\PlatformSh\Magento\DemoProvisioner;

$magentoProvisioner = new DemoProvisioner();
$magentoProvisioner->build();
