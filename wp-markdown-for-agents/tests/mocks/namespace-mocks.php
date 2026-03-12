<?php

/**
 * Namespace-scoped function stubs for unit tests.
 *
 * PHP resolves unqualified function calls by first checking the current
 * namespace, then falling back to the global namespace. Defining shims here
 * lets us intercept built-in PHP functions (like header()) that cannot be
 * overridden globally, without needing extensions such as runkit7.
 */

namespace {
    if ( defined( 'WP_MFA_NAMESPACE_MOCKS_LOADED' ) ) {
        return;
    }
    define( 'WP_MFA_NAMESPACE_MOCKS_LOADED', true );
}

namespace Tclp\WpMarkdownForAgents\Negotiate {
    /**
     * Namespace-scoped stub for PHP's header() built-in.
     *
     * Records sent headers in $GLOBALS['_mock_sent_headers'] so tests can
     * inspect them, without triggering "headers already sent" warnings that
     * PHPUnit converts to exceptions in CLI SAPI.
     */
    function header( string $header, bool $replace = true, int $response_code = 0 ): void {
        $GLOBALS['_mock_sent_headers'][] = $header;
    }

    /**
     * Namespace-scoped stub for PHP's readfile() built-in.
     *
     * Records the path and throws to prevent the subsequent exit() from
     * terminating the PHPUnit process.
     *
     * @throws \RuntimeException Always — caught by tests' try/catch wrappers.
     */
    function readfile( string $filename, bool $use_include_path = false, $context = null ): int|false {
        $GLOBALS['_mock_readfile_path'] = $filename;
        throw new \RuntimeException( 'readfile_mock: ' . $filename );
    }
}
