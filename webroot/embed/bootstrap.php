<?php
declare(strict_types=1);

if (function_exists('scrapegoat_load_asset')) {
    return;
}

$parentBootstrap = __DIR__ . '/../bootstrap.php';
if (is_file($parentBootstrap)) {
    require_once $parentBootstrap;
}

if (function_exists('scrapegoat_load_asset')) {
    return;
}

if (!defined('SCRAPEGOAT_REMOTE_BASE_DEFAULT')) {
    define('SCRAPEGOAT_REMOTE_BASE_DEFAULT', 'https://raw.githubusercontent.com/omgsideburns/scrapegoat/main/webroot');
}

function scrapegoat_remote_base_url(): string
{
    static $base = null;
    if ($base !== null) {
        return $base;
    }

    $env = getenv('SCRAPEGOAT_REMOTE_BASE_URL');
    if (is_string($env) && trim($env) !== '') {
        $base = rtrim(trim($env), '/');
    } else {
        $base = SCRAPEGOAT_REMOTE_BASE_DEFAULT;
    }

    return $base;
}

function scrapegoat_fetch_remote(string $relativePath): ?string
{
    $baseUrl = scrapegoat_remote_base_url();
    if ($baseUrl === '') {
        return null;
    }

    $url = $baseUrl . '/' . ltrim($relativePath, '/');

    if (function_exists('curl_init')) {
        $handle = curl_init($url);
        if ($handle === false) {
            return null;
        }
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_USERAGENT => 'scrapegoat-embed',
        ]);
        $body = curl_exec($handle);
        $status = curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);
        if ($body !== false && $status >= 200 && $status < 300) {
            return (string) $body;
        }
        return null;
    }

    $context = stream_context_create([
        'http' => [
            'timeout' => 10,
            'header' => "User-Agent: scrapegoat-embed\r\n",
        ],
        'https' => [
            'timeout' => 10,
            'header' => "User-Agent: scrapegoat-embed\r\n",
        ],
    ]);
    $body = @file_get_contents($url, false, $context);
    if ($body === false) {
        return null;
    }
    return $body;
}

function scrapegoat_load_asset(string $relativePath, bool $required = true): ?string
{
    static $cache = [];
    $key = ltrim($relativePath, '/');

    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $localPath = __DIR__ . '/' . $key;
    if (is_file($localPath)) {
        $contents = @file_get_contents($localPath);
        if ($contents !== false) {
            $cache[$key] = $contents;
            return $contents;
        }
    }

    $remote = scrapegoat_fetch_remote($key);
    if ($remote !== null) {
        $cache[$key] = $remote;
        return $remote;
    }

    $cache[$key] = null;
    return $cache[$key];
}
