<?php
require_once __DIR__ . '/includes/functions.php';
$tests = [
    'service.(accounting)@kbmc.com',
    'vacant.(accounting)@kbmc.com',
    'bad@',
    'user+tag@example.com'
];
foreach ($tests as $t) {
    echo $t . ': ' . (isValidEmail($t) ? 'OK' : 'FAIL') . PHP_EOL;
}
