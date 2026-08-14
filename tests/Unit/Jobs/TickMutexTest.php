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
        unset( $GLOBALS['_mock_get_option_side_effect'] );
        $this->clock = new FrozenClock( 1_000_000 );
        $this->mutex = new TickMutex( $this->clock );
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

    /**
     * Narrower than test_losing_a_concurrent_steal_backs_off(): that test
     * simulates a rival landing during our *insert*; this one simulates a
     * rival landing between our staleness read and our re-read immediately
     * before the delete — the gap the re-read exists to catch.
     */
    public function test_a_rival_fresh_lock_landing_before_the_steal_backs_off(): void {
        $this->mutex->acquire();
        $this->clock->advance( $this->mutex->window() + 1 );

        $calls = 0;
        $GLOBALS['_mock_get_option_side_effect'] = static function ( string $option ) use ( &$calls ): void {
            if ( TickMutex::OPTION !== $option ) {
                return;
            }

            ++$calls;

            if ( 1 === $calls ) {
                // Fires after our staleness read (call 1) has already
                // resolved its own return value, so $held below still sees
                // the stale snapshot — but the store is now mutated for the
                // *next* read, simulating a rival re-acquiring the same
                // stale lock before our re-read (call 2).
                $GLOBALS['_mock_options'][ TickMutex::OPTION ] = [ 'token' => 'rival', 'acquired_at' => 2_000_000 ];
            }
        };

        try {
            $this->assertNull( ( new TickMutex( $this->clock ) )->acquire() );
        } finally {
            unset( $GLOBALS['_mock_get_option_side_effect'] );
        }

        // The rival's lock must still be standing — we backed off rather
        // than deleting it.
        $this->assertSame( 'rival', get_option( TickMutex::OPTION )['token'] );
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

    public function test_window_is_never_shorter_than_the_jobs_stale_after(): void {
        $this->assertGreaterThanOrEqual( \Tclp\WpMarkdownForAgents\Jobs\GenerationJob::STALE_AFTER, $this->mutex->window() );
    }
}
