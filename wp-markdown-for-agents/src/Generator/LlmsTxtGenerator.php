<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Generator;

/**
 * Generates an llms.txt index file from exported Markdown files.
 *
 * Adapted from wp-to-file LLMsTxtIndexBuilder. Scans the export directory,
 * parses YAML frontmatter for title and excerpt, and builds an llms.txt per
 * the llmstxt.org specification.
 *
 * @since  1.0.0
 * @package Tclp\WpMarkdownForAgents\Generator
 */
class LlmsTxtGenerator {

    /**
     * @since  1.0.0
     * @param  array<string, mixed> $options Plugin options.
     */
    public function __construct( private readonly array $options = [] ) {}

    /**
     * Generate an llms.txt file for the export directory.
     *
     * Scans all .md files, builds a sectioned index grouped by post type,
     * and writes llms.txt to the export base directory.
     *
     * @since  1.0.0
     * @param  string $export_base Absolute path to the export base directory.
     * @return bool True on success, false on failure.
     */
    public function generate( string $export_base ): bool {
        if ( ! is_dir( $export_base ) ) {
            return false;
        }

        $site_name    = get_bloginfo( 'name' ) ?: home_url();
        $site_tagline = get_bloginfo( 'description' );

        $output   = [];
        $output[] = '# ' . $site_name;
        $output[] = '';

        if ( $site_tagline ) {
            $output[] = '> ' . $site_tagline;
            $output[] = '';
        }

        // Iterate post-type subdirectories.
        $type_dirs = glob( rtrim( $export_base, '/' ) . '/*', GLOB_ONLYDIR ) ?: [];

        foreach ( $type_dirs as $type_dir ) {
            $post_type = basename( $type_dir );
            $files     = glob( $type_dir . '/*.md' ) ?: [];

            if ( empty( $files ) ) {
                continue;
            }

            $output[] = '## ' . ucfirst( $post_type );
            $output[] = '';

            foreach ( $files as $file ) {
                $frontmatter = $this->parse_frontmatter( $file );
                $title       = (string) ( $frontmatter['title'] ?? basename( $file, '.md' ) );
                $permalink   = (string) ( $frontmatter['permalink'] ?? '' );
                $excerpt     = (string) ( $frontmatter['excerpt'] ?? '' );

                if ( empty( $permalink ) ) {
                    continue;
                }

                $line = '- [' . $title . '](' . $permalink . ')';
                if ( $excerpt ) {
                    $line .= ': ' . $this->truncate( $excerpt, 120 );
                }
                $output[] = $line;
            }

            $output[] = '';
        }

        $content    = implode( "\n", $output );
        $llms_path  = rtrim( $export_base, '/' ) . '/llms.txt';

        return false !== file_put_contents( $llms_path, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
    }

    /**
     * Parse the YAML frontmatter from a Markdown file.
     *
     * Reads only the frontmatter block (between the two `---` delimiters)
     * without a full YAML library. Handles simple scalar key: value pairs only.
     *
     * @since  1.0.0
     * @param  string $filepath Absolute path to the .md file.
     * @return array<string, string>
     */
    public function parse_frontmatter( string $filepath ): array {
        if ( ! file_exists( $filepath ) ) {
            return [];
        }

        $handle = fopen( $filepath, 'r' );
        if ( false === $handle ) {
            return [];
        }

        $data    = [];
        $in_fm   = false;
        $started = false;

        while ( false !== ( $line = fgets( $handle ) ) ) {
            $trimmed = rtrim( $line );

            if ( ! $started && '---' === $trimmed ) {
                $in_fm   = true;
                $started = true;
                continue;
            }

            if ( $started && '---' === $trimmed ) {
                break; // End of frontmatter.
            }

            if ( $in_fm ) {
                // Skip indented lines (YAML values like "  - News") and
                // bare list items at column 0 ("- value").
                if ( '' !== $trimmed && ( $line !== ltrim( $line ) || '-' === $trimmed[0] ) ) {
                    continue;
                }

                if ( str_contains( $trimmed, ':' ) ) {
                    $parts = explode( ':', $trimmed, 2 );
                    $key   = trim( $parts[0] );
                    $value = trim( $parts[1] );

                    // Strip surrounding single or double quotes (matched pairs only,
                    // length >= 2 required to avoid substr returning false).
                    if (
                        strlen( $value ) >= 2 &&
                        (
                            ( str_starts_with( $value, '"' ) && str_ends_with( $value, '"' ) ) ||
                            ( str_starts_with( $value, "'" ) && str_ends_with( $value, "'" ) )
                        )
                    ) {
                        $value = substr( $value, 1, -1 );
                    }

                    if ( '' !== $key ) {
                        $data[ $key ] = $value;
                    }
                }
            }
        }

        fclose( $handle );

        return $data;
    }

    /**
     * Truncate a string to a maximum length without cutting words.
     *
     * @since  1.0.0
     * @param  string $text   The string to truncate.
     * @param  int    $length Maximum length.
     * @return string
     */
    private function truncate( string $text, int $length ): string {
        if ( mb_strlen( $text ) <= $length ) {
            return $text;
        }

        return mb_substr( $text, 0, $length - 1 ) . '…';
    }
}
