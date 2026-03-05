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
        $GLOBALS['_mock_meta_boxes'] = [];
        $this->generator = $this->createMock( Generator::class );
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
}
