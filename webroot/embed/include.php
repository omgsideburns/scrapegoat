<?php
declare(strict_types=1);

if (!defined('SCRAPEGOAT_EMBED')) {
    define('SCRAPEGOAT_EMBED', true);
}

if (!function_exists('scrapegoat_load_asset')) {
    $parentBootstrap = __DIR__ . '/../bootstrap.php';
    if (is_file($parentBootstrap)) {
        require_once $parentBootstrap;
    }
}

if (!function_exists('scrapegoat_load_asset')) {
    require_once __DIR__ . '/bootstrap.php';
}

$defaults = [
    'show_nav' => true,
    'nav_links' => [
        ['label' => 'Interactive table', 'href' => 'https://xtonyx.org/scrapegoat/sbc.php'],
        ['label' => 'Sample item', 'href' => 'https://xtonyx.org/scrapegoat/item.php?sku={SKU}'],
    ],
    'repo_url' => 'https://github.com/omgsideburns/scrapegoat',
    'repo_label' => 'Browse the repo on GitHub',
    'footer_prefix' => 'Need raw data or charts?',
    'show_footer' => true,
    'wrapper_classes' => 'scrapegoat-embed',
];

if (defined('SCRAPEGOAT_EMBED_OPTIONS') && is_array(SCRAPEGOAT_EMBED_OPTIONS)) {
    $options = array_replace($defaults, SCRAPEGOAT_EMBED_OPTIONS);
} elseif (isset($GLOBALS['scrapegoatEmbedOptions']) && is_array($GLOBALS['scrapegoatEmbedOptions'])) {
    $options = array_replace($defaults, $GLOBALS['scrapegoatEmbedOptions']);
} else {
    $options = $defaults;
}

$GLOBALS['scrapegoatEmbedOptions'] = $options;

require __DIR__ . '/core.php';
