<?php

define('ROOT', dirname(__DIR__) . '/');
const PRIVATE_DIR = ROOT . 'tests/private/';
const TMP_DIR = ROOT . 'tests/tmp/';
const LOG_DIR = ROOT . 'tests/logs/';
const LANGUAGE_DIR = ROOT . 'languages/';
const TEMPLATE_DIR = ROOT . 'templates/';
const LANGUAGE_FILE_NAME = 'translations';
const DEFAULT_LANGUAGE = 'cs_CZ';
const CHECK_TRANSLATIONS = true;
const PRODUCTION = true;
const ASSETS_DIR = ROOT . 'assets/';

ini_set('open_basedir', ROOT);

if (!file_exists(TMP_DIR) && !mkdir(TMP_DIR) && !is_dir(TMP_DIR)) {
    throw new Exception('Cannot create temporary directory: ' . TMP_DIR);
}

require_once ROOT . 'vendor/autoload.php';
