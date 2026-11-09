<?php

namespace Enle\DailyCron;

defined('ABSPATH') || exit;

/**
 * PSR-4 autoloader for Enle\DailyCron\ → src/. No Composer at runtime: the
 * plugin is deployed by copying the folder.
 */
final class Autoloader
{
    private const PREFIX = 'Enle\\DailyCron\\';

    public static function register(string $base_dir): void
    {
        spl_autoload_register(static function (string $class) use ($base_dir): void {
            if (strncmp($class, self::PREFIX, strlen(self::PREFIX)) !== 0) {
                return;
            }

            $file = $base_dir . str_replace('\\', '/', substr($class, strlen(self::PREFIX))) . '.php';

            if (is_readable($file)) {
                require $file;
            }
        });
    }
}
