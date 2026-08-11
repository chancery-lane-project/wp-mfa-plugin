<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Tests\Unit\Jobs;

use PHPUnit\Framework\TestCase;
use Tclp\WpMarkdownForAgents\Jobs\TickMutex;
use Tclp\WpMarkdownForAgents\Tests\Support\FrozenClock;

/**
 * @covers \Tclp\WpMarkdownForAgents\Jobs\TickMutex
 */
class TickMutexTest extends TestCase {

    private FrozenClock $clock;
    private TickMutex $mutex;

    protected function setUp(): void {
        $GLOBALS['_mock_options'] = [];
        $this->clock              = new FrozenClock( 1_000_000 );
        $this->mutex              = new TickMutex( $this->clock );
    }

    public function test_first_acquire_wins_and_stores_token_with_timestamp(): void {
        $token = $this->mutex->acquire();

        $this->assertNotNull( $token );

        $stored = get_option( TickMutex::OPTION );

        $this->assertSame( $token, $stored['token'] );
        $this->assertSame( 1_000_000, $stored['acquired_at'] );
    }

    public function test_second_acquire_fails_while_the_lock_is_fresh(): void {
        $this->mutex->acquire();

        $this->clock->advance( 10 );

        $this->assertNull( ( new TickMutex( $this->clock ) )->acquire() );
    }

    public function test_stale_lock_is_stolen(): void {
        $first = $this->mutex->acquire();

        $this->clock->advance( $this->mutex->window() + 1 );
        $second = ( new TickMutex( $this->clock ) )->acquire();

        $this->assertNotNull( $second );
        $this->assertNotSame( $first, $second );
        $this->assertSame( $second, get_option( TickMutex::OPTION )['token'] );
    }

    /**
     * The stale-recovery race: if a competing tick steals the lock between our
     * delete_option() and our confirming read, we must back off rather than
     * run concurrently. Simulated by overwriting the option during add_option().
     */
    public function test_losing_a_concurrent_steal_backs_off(): void {
        $this->mutex->acquire();
        $this->clock->advance( $this->mutex->window() + 1 );

        $GLOBALS['_mock_add_option_side_effect'] = static function (): void {
            $GLOBALS['_mock_options'][ TickMutex::OPTION ] = [ 'token' => 'rival', 'acquired_at' => 1_000_000 ];
        };

        try {
            $this->assertNull( ( new TickMutex( $this->clock ) )->acquire() );
        } finally {
            unset( $GLOBALS['_mock_add_option_side_effect'] );
        }
    }

    public function test_heartbeat_refreshes_only_our_own_lock(): void {
        $token = $this->mutex->acquire();

        $this->clock->advance( 120 );
        $this->mutex->heartbeat( (string) $token );

        $this->assertSame( 1_000_120, get_option( TickMutex::OPTION )['acquired_at'] );

        $this->mutex->heartbeat( 'someone-else' );

        $this->assertSame( 1_000_120, get_option( TickMutex::OPTION )['acquired_at'] );
    }

    public function test_release_deletes_only_our_own_lock(): void {
        $token = $this->mutex->acquire();

        $this->mutex->release( 'someone-else' );
        $this->assertIsArray( get_option( TickMutex::OPTION ) );

        $this->mutex->release( (string) $token );
        $this->assertFalse( get_option( TickMutex::OPTION ) );
    }

    public function test_window_is_never_shorter_than_five_minutes(): void {
        $this->assertGreaterThanOrEqual( 300, $this->mutex->window() );
    }
}
