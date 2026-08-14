<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Tests\Unit\Jobs;

use PHPUnit\Framework\TestCase;
use Tclp\WpMarkdownForAgents\Core\Options;
use Tclp\WpMarkdownForAgents\Generator\BundleGenerator;
use Tclp\WpMarkdownForAgents\Generator\Generator;
use Tclp\WpMarkdownForAgents\Generator\TaxonomyArchiveGenerator;
use Tclp\WpMarkdownForAgents\Jobs\BundleStage;
use Tclp\WpMarkdownForAgents\Jobs\PostTypeStage;
use Tclp\WpMarkdownForAgents\Jobs\StageFactory;
use Tclp\WpMarkdownForAgents\Jobs\TaxonomyStage;

/**
 * @covers \Tclp\WpMarkdownForAgents\Jobs\StageFactory
 */
class StageFactoryTest extends TestCase {

    private StageFactory $factory;

    protected function setUp(): void {
        $options = array_merge( Options::get_defaults(), [ 'post_types' => [ 'post', 'page' ] ] );

        $this->factory = new StageFactory(
            new \wpdb(),
            $options,
            $this->createMock( Generator::class ),
            $this->createMock( TaxonomyArchiveGenerator::class ),
            $this->createMock( BundleGenerator::class )
        );
    }

    /** @return list<string> */
    private function shape( array $stages ): array {
        return array_map(
            static fn( array $stage ): string => $stage['type'] . ( isset( $stage['slug'] ) ? ':' . $stage['slug'] : '' ),
            $stages
        );
    }

    public function test_all_scope_is_every_post_type_then_taxonomy_then_bundle(): void {
        $stages = $this->factory->build_stage_list( 'all' );

        $this->assertSame( [ 'post_type:post', 'post_type:page', 'taxonomy', 'bundle' ], $this->shape( $stages ) );
    }

    public function test_single_post_type_scope_is_that_type_then_bundle(): void {
        $stages = $this->factory->build_stage_list( 'post_type:page' );

        $this->assertSame( [ 'post_type:page', 'bundle' ], $this->shape( $stages ) );
    }

    public function test_taxonomy_scope_is_taxonomy_then_bundle(): void {
        $this->assertSame( [ 'taxonomy', 'bundle' ], $this->shape( $this->factory->build_stage_list( 'taxonomy' ) ) );
    }

    public function test_every_scope_ends_in_exactly_one_bundle_stage(): void {
        foreach ( [ 'all', 'post_type:post', 'taxonomy' ] as $scope ) {
            $types = array_column( $this->factory->build_stage_list( $scope ), 'type' );

            $this->assertSame( 1, array_count_values( $types )['bundle'], $scope );
            $this->assertSame( 'bundle', end( $types ), $scope );
        }
    }

    public function test_unknown_scope_and_disabled_post_type_build_nothing(): void {
        $this->assertSame( [], $this->factory->build_stage_list( 'nonsense' ) );
        $this->assertSame( [], $this->factory->build_stage_list( 'post_type:attachment' ) );
        $this->assertSame( [], $this->factory->build_stage_list( '' ) );
    }

    public function test_descriptors_start_with_zeroed_counters_and_unknown_total(): void {
        $stage = $this->factory->build_stage_list( 'taxonomy' )[0];

        $this->assertNull( $stage['total'] );
        $this->assertSame( 0, $stage['processed'] );
        $this->assertSame( 0, $stage['skipped'] );
        $this->assertSame( 0, $stage['error_count'] );
        $this->assertSame( 'pending', $stage['state'] );
    }

    public function test_make_returns_the_matching_stage_implementation(): void {
        $this->assertInstanceOf( PostTypeStage::class, $this->factory->make( [ 'type' => 'post_type', 'slug' => 'post' ] ) );
        $this->assertInstanceOf( TaxonomyStage::class, $this->factory->make( [ 'type' => 'taxonomy' ] ) );
        $this->assertInstanceOf( BundleStage::class, $this->factory->make( [ 'type' => 'bundle' ] ) );
    }

    public function test_make_returns_null_for_an_unusable_descriptor(): void {
        $this->assertNull( $this->factory->make( [ 'type' => 'wat' ] ) );
        $this->assertNull( $this->factory->make( [ 'type' => 'post_type' ] ) );
        $this->assertNull( $this->factory->make( [ 'type' => 'post_type', 'slug' => 'attachment' ] ) );
    }
}
