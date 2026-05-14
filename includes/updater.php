<?php
defined('ABSPATH') || exit;

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$updateChecker = PucFactory::buildUpdateChecker(
    'https://github.com/prinnotech/cleanconvert-wp',
    plugin_dir_path(__FILE__) . '../cleanconvert.php',
    'cleanconvert'
);

$updateChecker->setBranch('main');