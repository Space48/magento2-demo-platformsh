<?php

include __DIR__ . '/lib/Platformsh/Magento/Platformsh.php';

$platformSh = new \Platformsh\Magento\Platformsh();
$platformSh->build();
