<?php

declare(strict_types=1);

/**
 * PHPUnit bootstrap file.
 *
 * Loads the Composer autoloader and WordPress function stubs.
 */

// Composer autoloader (includes src/ PSR-4 and league/html-to-markdown).
require_once dirname(__DIR__) . '/vendor/autoload.php';

// WordPress function stubs for unit tests.
require_once __DIR__ . '/mocks/wordpress-mocks.php';
