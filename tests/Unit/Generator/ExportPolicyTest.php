<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Tests\Unit\Generator;

use PHPUnit\Framework\TestCase;
use Tclp\WpMarkdownForAgents\Generator\ExportPolicy;

class ExportPolicyTest extends TestCase {

    private const OPTIONS = [ 'post_types' => [ 'post', 'clause' ] ];

    protected function setUp(): void {
        parent::setUp();
        $GLOBALS['_mock_post_meta'] = [];
    }

    private function make_post(array $props = []): \WP_Post {
        return new \WP_Post(array_merge([
            'ID'        => 42,
            'post_type' => 'post',
            'post_name' => 'my-post',
        ], $props));
    }

    public function test_enabled_post_types_returns_option_value(): void {
        $this->assertSame(['post', 'clause'], ExportPolicy::enabled_post_types(self::OPTIONS));
    }

    public function test_enabled_post_types_defaults_to_empty_array(): void {
        $this->assertSame([], ExportPolicy::enabled_post_types([]));
    }

    public function test_eligible_published_post_of_enabled_type(): void {
        $this->assertTrue(ExportPolicy::is_eligible($this->make_post(), self::OPTIONS));
    }

    public function test_ineligible_when_type_not_enabled(): void {
        $post = $this->make_post(['post_type' => 'attachment']);
        $this->assertFalse(ExportPolicy::is_eligible($post, self::OPTIONS));
    }

    public function test_ineligible_when_not_published(): void {
        $post = $this->make_post(['post_status' => 'draft']);
        $this->assertFalse(ExportPolicy::is_eligible($post, self::OPTIONS));
    }

    public function test_ineligible_when_password_protected(): void {
        $post = $this->make_post(['post_password' => 'secret']);
        $this->assertFalse(ExportPolicy::is_eligible($post, self::OPTIONS));
    }

    public function test_ineligible_when_excluded_via_meta(): void {
        $post = $this->make_post();
        $GLOBALS['_mock_post_meta'][42]['_markdown_for_agents_excluded'] = '1';
        $this->assertFalse(ExportPolicy::is_eligible($post, self::OPTIONS));
    }

    public function test_post_relative_path_sanitises_segments(): void {
        $post = $this->make_post(['post_type' => 'clause', 'post_name' => 'my-clause']);
        $this->assertSame('clause/my-clause.md', ExportPolicy::post_relative_path($post));
    }

    public function test_term_relative_path_sanitises_segments(): void {
        $this->assertSame(
            'taxonomy/practice-area/construction.md',
            ExportPolicy::term_relative_path('practice-area', 'construction')
        );
    }
}
