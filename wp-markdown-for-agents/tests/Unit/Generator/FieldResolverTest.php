<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Tests\Unit\Generator;

use PHPUnit\Framework\TestCase;
use Tclp\WpMarkdownForAgents\Generator\FieldResolver;

/**
 * @covers \Tclp\WpMarkdownForAgents\Generator\FieldResolver
 */
class FieldResolverTest extends TestCase {

    private FieldResolver $resolver;

    protected function setUp(): void {
        $this->resolver            = new FieldResolver();
        $GLOBALS['_mock_post_meta'] = [];
    }

    public function test_resolves_plain_meta_key(): void {
        $GLOBALS['_mock_post_meta'][42]['my_field'] = 'my value';

        $result = $this->resolver->resolve( 42, 'my_field' );

        $this->assertSame( 'my value', $result );
    }

    public function test_returns_null_for_missing_meta_key(): void {
        $result = $this->resolver->resolve( 42, 'nonexistent_field' );

        $this->assertNull( $result );
    }

    public function test_returns_null_for_empty_string_meta_value(): void {
        $GLOBALS['_mock_post_meta'][42]['empty_field'] = '';

        $result = $this->resolver->resolve( 42, 'empty_field' );

        $this->assertNull( $result );
    }

    public function test_returns_null_for_dot_notation_when_get_field_unavailable(): void {
        // get_field() is not defined in the unit test environment, so dot-notation
        // paths always return null without ACF.
        $result = $this->resolver->resolve( 42, 'group.subfield' );

        $this->assertNull( $result );
    }
}
