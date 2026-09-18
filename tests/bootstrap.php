<?php

/**
 * A PSR-4 autoloader without Composer: the tests cover Core/, which knows nothing about Magento,
 * so they run anywhere — including a checkout with no `composer install` and no Magento at all.
 * Only our own namespaces are loaded, so a test that reaches for a Magento class fails
 * immediately and keeps the boundary honest.
 */

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $roots = [
        'Calmfox\\Smtp\\Tests\\' => __DIR__ . '/',
        'Calmfox\\Smtp\\' => \dirname(__DIR__) . '/',
    ];

    foreach ($roots as $prefix => $dir) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }
        $path = $dir . str_replace('\\', '/', substr($class, \strlen($prefix))) . '.php';
        if (is_file($path)) {
            require_once $path;
        }

        return;
    }
});
