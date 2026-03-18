<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Tests\Unit\Generator;

use PHPUnit\Framework\TestCase;
use Tclp\WpMarkdownForAgents\Generator\YamlFormatter;

/**
 * @covers \Tclp\WpMarkdownForAgents\Generator\YamlFormatter
 */
class YamlFormatterTest extends TestCase {

    private YamlFormatter $formatter;

    protected function setUp(): void {
        $this->formatter = new YamlFormatter();
    }

    public function test_format_returns_frontmatter_delimiters(): void {
        $output = $this->formatter->format(['title' => 'Hello']);
        $this->assertStringStartsWith("---\n", $output);
        $this->assertStringEndsWith("---\n", $output);
    }

    public function test_format_simple_string(): void {
        $output = $this->formatter->format(['title' => 'Hello World']);
        $this->assertStringContainsString('title: Hello World', $output);
    }

    public function test_format_string_with_colon_is_quoted(): void {
        $output = $this->formatter->format(['title' => 'Hello: World']);
        $this->assertStringContainsString('title: "Hello: World"', $output);
    }

    public function test_format_string_with_hash_is_quoted(): void {
        $output = $this->formatter->format(['title' => 'Hello #World']);
        $this->assertStringContainsString('title: "Hello #World"', $output);
    }

    public function test_format_string_with_double_quote_is_escaped(): void {
        $output = $this->formatter->format(['title' => 'Say "Hello"']);
        $this->assertStringContainsString('title: "Say \\"Hello\\""', $output);
    }

    public function test_format_boolean_true(): void {
        $output = $this->formatter->format(['enabled' => true]);
        $this->assertStringContainsString('enabled: true', $output);
    }

    public function test_format_boolean_false(): void {
        $output = $this->formatter->format(['enabled' => false]);
        $this->assertStringContainsString('enabled: false', $output);
    }

    public function test_format_integer(): void {
        $output = $this->formatter->format(['wpid' => 42]);
        $this->assertStringContainsString('wpid: 42', $output);
    }

    public function test_format_iso8601_date_is_not_quoted(): void {
        $output = $this->formatter->format(['date' => '2025-03-01T10:00:00Z']);
        $this->assertStringContainsString('date: 2025-03-01T10:00:00Z', $output);
    }

    public function test_format_simple_array_as_yaml_list(): void {
        $output = $this->formatter->format(['tags' => ['climate', 'legal']]);
        $this->assertStringContainsString("tags:\n  - climate\n  - legal", $output);
    }

    public function test_format_empty_array(): void {
        $output = $this->formatter->format(['tags' => []]);
        $this->assertStringContainsString('tags: []', $output);
    }

    public function test_format_array_item_with_special_chars_is_quoted(): void {
        $output = $this->formatter->format(['categories' => ['News & Updates']]);
        // Ampersand does not need quoting in YAML scalars.
        // But colon does.
        $output2 = $this->formatter->format(['categories' => ['A: B']]);
        $this->assertStringContainsString('- "A: B"', $output2);
    }

    public function test_format_bool_like_string_is_quoted(): void {
        $output = $this->formatter->format(['value' => 'true']);
        $this->assertStringContainsString('value: "true"', $output);
    }

    public function test_format_numeric_string_is_quoted(): void {
        $output = $this->formatter->format(['value' => '123']);
        $this->assertStringContainsString('value: "123"', $output);
    }

    public function test_format_url_is_not_over_quoted(): void {
        $output = $this->formatter->format(['permalink' => 'https://example.com/post/']);
        // URL contains colon so must be quoted.
        $this->assertStringContainsString('permalink: "https://example.com/post/"', $output);
    }

    public function test_format_empty_string(): void {
        $output = $this->formatter->format(['excerpt' => '']);
        $this->assertStringContainsString('excerpt: ""', $output);
    }

    public function test_format_multiple_fields_in_order(): void {
        $output = $this->formatter->format([
            'title' => 'Test',
            'wpid'  => 1,
            'tags'  => ['a', 'b'],
        ]);
        $title_pos = strpos($output, 'title:');
        $wpid_pos  = strpos($output, 'wpid:');
        $tags_pos  = strpos($output, 'tags:');
        $this->assertLessThan($wpid_pos, $title_pos);
        $this->assertLessThan($tags_pos, $wpid_pos);
    }
}
