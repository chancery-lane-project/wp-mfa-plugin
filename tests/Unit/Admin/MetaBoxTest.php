<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Tests\Unit\Admin;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Tclp\WpMarkdownForAgents\Admin\MetaBox;
use Tclp\WpMarkdownForAgents\Generator\Generator;

/**
 * @covers \Tclp\WpMarkdownForAgents\Admin\MetaBox
 */
class MetaBoxTest extends TestCase {

    /** @var Generator&MockObject */
    private Generator $generator;

    protected function setUp(): void {
        $GLOBALS['_mock_meta_boxes']       = [];
        $GLOBALS['_mock_post_meta']        = [];
        $GLOBALS['_mock_verify_nonce']     = 1;
        $GLOBALS['_mock_current_user_can'] = true;
        $GLOBALS['_mock_is_post_revision'] = false;
        $_POST                             = [];
        $this->generator = $this->createMock( Generator::class );
    }

    protected function tearDown(): void {
        // Reset mutable globals so later test classes (e.g. GeneratorTest) are
        // not affected by values set inside individual test methods.
        $GLOBALS['_mock_is_post_revision'] = false;
        $_POST                             = [];
    }

    public function test_register_adds_meta_box_for_each_post_type(): void {
        $meta_box = new MetaBox(
            [ 'post_types' => [ 'post', 'page' ] ],
            $this->generator
        );

        $meta_box->register();

        $registered_screens = array_column( $GLOBALS['_mock_meta_boxes'], 'screen' );
        $this->assertContains( 'post', $registered_screens );
        $this->assertContains( 'page', $registered_screens );
    }

    public function test_register_adds_no_boxes_when_no_post_types(): void {
        $meta_box = new MetaBox( [ 'post_types' => [] ], $this->generator );
        $meta_box->register();
        $this->assertEmpty( $GLOBALS['_mock_meta_boxes'] );
    }

    public function test_render_shows_no_file_message_when_missing(): void {
        $post = new \WP_Post( [ 'ID' => 1, 'post_name' => 'test', 'post_type' => 'post' ] );

        $this->generator->method( 'get_export_path' )
            ->willReturn( '/nonexistent/path.md' );

        $meta_box = new MetaBox( [ 'post_types' => [ 'post' ] ], $this->generator );

        ob_start();
        $meta_box->render( $post );
        $output = ob_get_clean();

        $this->assertStringContainsString( 'No Markdown file generated yet', $output );
    }

    // -----------------------------------------------------------------------
    // save()
    // -----------------------------------------------------------------------

    public function test_save_sets_meta_and_deletes_file_when_excluded(): void {
        $_POST['markdown_for_agents_exclude_nonce'] = 'valid';
        $_POST['markdown_for_agents_excluded']      = '1';

        $this->generator->expects( $this->once() )->method( 'delete_post' )->with( 1 );

        $meta_box = new MetaBox( [ 'post_types' => [ 'post' ] ], $this->generator );
        $meta_box->save( 1 );

        $this->assertSame( '1', $GLOBALS['_mock_post_meta'][1]['_markdown_for_agents_excluded'] ?? null );
    }

    public function test_save_clears_meta_when_not_excluded(): void {
        $GLOBALS['_mock_post_meta'][1]['_markdown_for_agents_excluded'] = '1';
        $_POST['markdown_for_agents_exclude_nonce'] = 'valid';
        // $_POST['markdown_for_agents_excluded'] intentionally absent — checkbox unticked.

        $this->generator->expects( $this->never() )->method( 'delete_post' );

        $meta_box = new MetaBox( [ 'post_types' => [ 'post' ] ], $this->generator );
        $meta_box->save( 1 );

        $this->assertArrayNotHasKey( '_markdown_for_agents_excluded', $GLOBALS['_mock_post_meta'][1] ?? [] );
    }

    public function test_save_does_nothing_with_invalid_nonce(): void {
        $GLOBALS['_mock_verify_nonce']              = false;
        $_POST['markdown_for_agents_exclude_nonce'] = 'bad';
        $_POST['markdown_for_agents_excluded']      = '1';

        $this->generator->expects( $this->never() )->method( 'delete_post' );

        $meta_box = new MetaBox( [ 'post_types' => [ 'post' ] ], $this->generator );
        $meta_box->save( 1 );

        $this->assertArrayNotHasKey( '_markdown_for_agents_excluded', $GLOBALS['_mock_post_meta'][1] ?? [] );
    }

    public function test_save_skips_revision(): void {
        $GLOBALS['_mock_is_post_revision']          = 5; // non-false = is a revision
        $_POST['markdown_for_agents_exclude_nonce'] = 'valid';
        $_POST['markdown_for_agents_excluded']      = '1';

        $this->generator->expects( $this->never() )->method( 'delete_post' );

        $meta_box = new MetaBox( [ 'post_types' => [ 'post' ] ], $this->generator );
        $meta_box->save( 1 );

        $this->assertArrayNotHasKey( '_markdown_for_agents_excluded', $GLOBALS['_mock_post_meta'][1] ?? [] );
    }

    // -----------------------------------------------------------------------
    // render() — exclusion checkbox
    // -----------------------------------------------------------------------

    public function test_render_checkbox_checked_when_excluded(): void {
        $post = new \WP_Post( [ 'ID' => 1, 'post_name' => 'test', 'post_type' => 'post' ] );
        $GLOBALS['_mock_post_meta'][1]['_markdown_for_agents_excluded'] = '1';

        $this->generator->method( 'get_export_path' )->willReturn( '/nonexistent/path.md' );

        $meta_box = new MetaBox( [ 'post_types' => [ 'post' ] ], $this->generator );

        ob_start();
        $meta_box->render( $post );
        $output = ob_get_clean();

        $this->assertStringContainsString( 'checked="checked"', $output );
    }

    public function test_render_checkbox_unchecked_when_not_excluded(): void {
        $post = new \WP_Post( [ 'ID' => 1, 'post_name' => 'test', 'post_type' => 'post' ] );
        // No exclusion meta set.

        $this->generator->method( 'get_export_path' )->willReturn( '/nonexistent/path.md' );

        $meta_box = new MetaBox( [ 'post_types' => [ 'post' ] ], $this->generator );

        ob_start();
        $meta_box->render( $post );
        $output = ob_get_clean();

        $this->assertStringNotContainsString( 'checked="checked"', $output );
    }

    public function test_render_regenerate_disabled_when_excluded(): void {
        $post = new \WP_Post( [ 'ID' => 1, 'post_name' => 'test', 'post_type' => 'post' ] );
        $GLOBALS['_mock_post_meta'][1]['_markdown_for_agents_excluded'] = '1';

        $this->generator->method( 'get_export_path' )->willReturn( '/nonexistent/path.md' );

        ob_start();
        ( new MetaBox( [ 'post_types' => [ 'post' ] ], $this->generator ) )->render( $post );
        $output = ob_get_clean();

        $this->assertStringContainsString( 'aria-disabled="true"', $output );
        $this->assertStringNotContainsString( 'href=', $output );
    }

    public function test_render_regenerate_enabled_when_not_excluded(): void {
        $post = new \WP_Post( [ 'ID' => 1, 'post_name' => 'test', 'post_type' => 'post' ] );

        $this->generator->method( 'get_export_path' )->willReturn( '/nonexistent/path.md' );

        ob_start();
        ( new MetaBox( [ 'post_types' => [ 'post' ] ], $this->generator ) )->render( $post );
        $output = ob_get_clean();

        $this->assertStringNotContainsString( 'aria-disabled="true"', $output );
        $this->assertStringContainsString( 'href=', $output );
    }

    public function test_render_preview_button_disabled_when_excluded(): void {
        $post = new \WP_Post( [ 'ID' => 1, 'post_name' => 'test', 'post_type' => 'post' ] );
        $GLOBALS['_mock_post_meta'][1]['_markdown_for_agents_excluded'] = '1';

        $this->generator->method( 'get_export_path' )->willReturn( '/nonexistent/path.md' );

        ob_start();
        ( new MetaBox( [ 'post_types' => [ 'post' ] ], $this->generator ) )->render( $post );
        $output = ob_get_clean();

        $this->assertStringContainsString( 'disabled="disabled"', $output );
    }

    public function test_render_preview_button_enabled_when_not_excluded(): void {
        $post = new \WP_Post( [ 'ID' => 1, 'post_name' => 'test', 'post_type' => 'post' ] );

        $this->generator->method( 'get_export_path' )->willReturn( '/nonexistent/path.md' );

        ob_start();
        ( new MetaBox( [ 'post_types' => [ 'post' ] ], $this->generator ) )->render( $post );
        $output = ob_get_clean();

        $this->assertStringNotContainsString( 'disabled="disabled"', $output );
    }

    // MUST BE LAST in save() tests — define() cannot be undone in a shared process.
    // @runInSeparateProcess with @preserveGlobalState disabled isolates the constant.
    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_save_skips_autosave(): void {
        if ( ! defined( 'DOING_AUTOSAVE' ) ) {
            define( 'DOING_AUTOSAVE', true );
        }
        $_POST['markdown_for_agents_excluded'] = '1';

        $this->generator->expects( $this->never() )->method( 'delete_post' );

        $meta_box = new MetaBox( [ 'post_types' => [ 'post' ] ], $this->generator );
        $meta_box->save( 1 );

        $this->assertArrayNotHasKey( '_markdown_for_agents_excluded', $GLOBALS['_mock_post_meta'][1] ?? [] );
    }
}
