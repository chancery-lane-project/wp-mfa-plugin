<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Tests\Unit\Generator;

use PHPUnit\Framework\TestCase;
use Tclp\WpMarkdownForAgents\Generator\ExportPolicy;

class ExportPolicyTest extends TestCase {

    private const OPTIONS = [ 'post_types' => [ 'post', 'clause' ] ];

    protected function setUp(): void {
        parent::setUp();
        $GLOBALS['_mock_post_meta']      = [];
        $GLOBALS['_mock_post_ancestors'] = [];
        $GLOBALS['_mock_post_objects']   = [];
        $GLOBALS['_mock_hierarchical_types'] = ['page'];
    }

    protected function tearDown(): void {
        unset(
            $GLOBALS['_mock_apply_filters']['markdown_for_agents_post_relative_path'],
            $GLOBALS['_mock_apply_filters']['markdown_for_agents_is_eligible']
        );
        $GLOBALS['_mock_post_ancestors'] = [];
        $GLOBALS['_mock_post_objects']   = [];
        parent::tearDown();
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

    public function test_is_eligible_filter_can_narrow(): void {
        $GLOBALS['_mock_apply_filters']['markdown_for_agents_is_eligible'] = fn( bool $eligible, \WP_Post $post ) => 42 !== $post->ID;
        $this->assertFalse(ExportPolicy::is_eligible($this->make_post(), self::OPTIONS));
    }

    public function test_is_eligible_filter_cannot_widen_to_drafts(): void {
        $GLOBALS['_mock_apply_filters']['markdown_for_agents_is_eligible'] = fn() => true;
        $post = $this->make_post(['post_status' => 'draft']);
        $this->assertFalse(ExportPolicy::is_eligible($post, self::OPTIONS));
    }

    public function test_child_page_path_nests_under_ancestors(): void {
        $GLOBALS['_mock_post_objects'][10] = new \WP_Post(['ID' => 10, 'post_type' => 'page', 'post_name' => 'people']);
        $GLOBALS['_mock_post_objects'][5]  = new \WP_Post(['ID' => 5, 'post_type' => 'page', 'post_name' => 'about']);
        // Nearest ancestor first, as get_post_ancestors() returns them.
        $GLOBALS['_mock_post_ancestors'][42] = [10, 5];

        $post = $this->make_post(['post_type' => 'page', 'post_name' => 'team']);

        $this->assertSame('page/about/people/team.md', ExportPolicy::post_relative_path($post));
        $this->assertSame('about/people/team.md', ExportPolicy::post_type_relative_path($post));
    }

    public function test_same_slug_under_different_parents_gets_different_paths(): void {
        $GLOBALS['_mock_post_objects'][5] = new \WP_Post(['ID' => 5, 'post_type' => 'page', 'post_name' => 'about']);
        $GLOBALS['_mock_post_objects'][6] = new \WP_Post(['ID' => 6, 'post_type' => 'page', 'post_name' => 'services']);
        $GLOBALS['_mock_post_ancestors'][42] = [5];
        $GLOBALS['_mock_post_ancestors'][43] = [6];

        $a = $this->make_post(['ID' => 42, 'post_type' => 'page', 'post_name' => 'team']);
        $b = $this->make_post(['ID' => 43, 'post_type' => 'page', 'post_name' => 'team']);

        $this->assertSame('page/about/team.md', ExportPolicy::post_relative_path($a));
        $this->assertSame('page/services/team.md', ExportPolicy::post_relative_path($b));
    }

    public function test_top_level_page_path_is_unchanged(): void {
        $post = $this->make_post(['post_type' => 'page', 'post_name' => 'about']);
        $this->assertSame('page/about.md', ExportPolicy::post_relative_path($post));
    }

    public function test_non_hierarchical_type_ignores_ancestors(): void {
        $GLOBALS['_mock_post_objects'][5]    = new \WP_Post(['ID' => 5, 'post_type' => 'post', 'post_name' => 'parent']);
        $GLOBALS['_mock_post_ancestors'][42] = [5];

        $this->assertSame('post/my-post.md', ExportPolicy::post_relative_path($this->make_post()));
    }

    public function test_relative_path_filter_is_honoured(): void {
        $GLOBALS['_mock_apply_filters']['markdown_for_agents_post_relative_path'] = fn( string $path ) => str_replace('.md', '.de.md', $path);

        $post = $this->make_post();

        $this->assertSame('post/my-post.de.md', ExportPolicy::post_relative_path($post));
        $this->assertSame('my-post.de.md', ExportPolicy::post_type_relative_path($post));
    }

    /**
     * @dataProvider invalid_filtered_paths
     */
    public function test_relative_path_filter_rejects_paths_outside_type_dir(string $filtered): void {
        $GLOBALS['_mock_apply_filters']['markdown_for_agents_post_relative_path'] = fn() => $filtered;

        $this->assertSame('post/my-post.md', ExportPolicy::post_relative_path($this->make_post()));
    }

    /** @return array<string, array{string}> */
    public function invalid_filtered_paths(): array {
        return [
            'other type dir'   => ['page/my-post.md'],
            'parent traversal' => ['post/../page/my-post.md'],
            'not markdown'     => ['post/my-post.php'],
            'empty segment'    => ['post//my-post.md'],
            'backslash'        => ['post\\..\\my-post.md'],
            'absolute'         => ['/post/my-post.md'],
        ];
    }

    public function test_term_relative_path_sanitises_segments(): void {
        $this->assertSame(
            'taxonomy/practice-area/construction.md',
            ExportPolicy::term_relative_path('practice-area', 'construction')
        );
    }
}
