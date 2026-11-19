<?php
/**
 * Web Palm Beaches — Secure Configuration Loader
 *
 * Loads secrets from (in priority order):
 *   1. Above-webroot file: ../wpb-config.php
 *   2. Local gitignored file: ./config.local.php
 *   3. Server environment variables (getenv / $_ENV / $_SERVER)
 */

if (!function_exists('wpb_config')) {
    function wpb_load_config_array(): array {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }

        $cached = [];
        $candidates = [
            dirname(__DIR__) . '/wpb-config.php',
            __DIR__ . '/config.local.php',
        ];

        foreach ($candidates as $path) {
            if (is_file($path) && is_readable($path)) {
                $data = include $path;
                if (is_array($data)) {
                    $cached = array_merge($cached, $data);
                    break;
                }
            }
        }

        return $cached;
    }

    function wpb_config(string $key, $default = '') {
        $fileConfig = wpb_load_config_array();
        if (array_key_exists($key, $fileConfig) && $fileConfig[$key] !== '' && $fileConfig[$key] !== null) {
            return $fileConfig[$key];
        }

        $envVal = getenv($key);
        if ($envVal !== false && $envVal !== '') {
            return $envVal;
        }
        if (isset($_ENV[$key]) && $_ENV[$key] !== '') {
            return $_ENV[$key];
        }
        if (isset($_SERVER[$key]) && $_SERVER[$key] !== '') {
            return $_SERVER[$key];
        }

        return $default;
    }
}
