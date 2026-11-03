<?php

/**
 * World Text API client - PHP 8.2+ bootstrap.
 *
 * No Composer: this file registers a PSR-4 style autoloader for the `WorldText`
 * namespace and does nothing else.
 *
 *     require __DIR__ . '/src/WorldText/bootstrap.php';
 *
 *     $wt = new \WorldText\WorldText('wt_live_...');
 *
 * If the application does have a PSR-4 autoloader (Composer or its own), it can
 * map `WorldText\` to `src/WorldText/` instead and skip this file.
 */

declare(strict_types=1);

if (!defined('WORLD_TEXT_PHP')) {
    /** Guard against a double include, which would re-register the loader. */
    define('WORLD_TEXT_PHP', '1.0.0');

    spl_autoload_register(static function (string $class): void {
        $prefix = 'WorldText\\';

        if (!str_starts_with($class, $prefix)) {
            return;
        }

        // PSR-4: sub-namespaces are directories under src/WorldText/.
        $relative = str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen($prefix)));
        $path = __DIR__ . DIRECTORY_SEPARATOR . $relative . '.php';

        if (is_file($path)) {
            require $path;
        }
    }, true, true);
}