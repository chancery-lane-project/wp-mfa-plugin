<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Tests\Unit\Stats;

use PHPUnit\Framework\TestCase;
use Tclp\WpMarkdownForAgents\Core\Options;
use Tclp\WpMarkdownForAgents\Stats\StatsRepository;
use Tclp\WpMarkdownForAgents\Stats\StatsRetention;

/**
 * @covers \Tclp\WpMarkdownForAgents\Stats\StatsRetention
 */
class StatsRetentionTest extends TestCase {

    private \wpdb $wpdb;

    protected function setUp(): void {
        $GLOBALS['_mock_options'] = [];
        reset_mock_scheduled_events();
        $this->wpdb = new \wpdb();
    }

    /** Prepared DELETEs only: the mock logs a query at prepare() and again at query(). */
    private function delete_queries(): array {
        return array_values( array_filter(
            $this->wpdb->queries,
            static fn( array $q ): bool => str_contains( $q['query'], 'DELETE FROM' ) && [] !== $q['args']
        ) );
    }

    // ---------------------------------------------------------------------
    // sanitize
    // ---------------------------------------------------------------------

    public function test_sanitize_accepts_each_choice(): void {
        foreach ( StatsRetention::CHOICES as $days ) {
            $this->assertSame( $days, StatsRetention::sanitize( (string) $days ) );
            $this->assertSame( $days, StatsRetention::sanitize( $days ) );
        }
    }

    public function test_sanitize_falls_back_to_default_for_anything_else(): void {
        foreach ( [ '45', '-1', 'abc', '', null, [ 90 ], 1.5 ] as $value ) {
            $this->assertSame( StatsRetention::DEFAULT_DAYS, StatsRetention::sanitize( $value ), var_export( $value, true ) );
        }
    }

    public function test_default_keeps_a_year_and_zero_means_keep_everything(): void {
        $this->assertSame( 365, StatsRetention::DEFAULT_DAYS );
        $this->assertContains( 0, StatsRetention::CHOICES );
    }

    // ---------------------------------------------------------------------
    // run
    // ---------------------------------------------------------------------

    public function test_run_deletes_rows_older_than_the_saved_period(): void {
        update_option( Options::OPTION_KEY, [ 'stats_retention_days' => 90 ] );

        ( new StatsRetention( new StatsRepository( $this->wpdb ) ) )->run();

        $deletes = $this->delete_queries();
        $this->assertCount( 1, $deletes );
        $this->assertContains( gmdate( 'Y-m-d', strtotime( '-90 days' ) ), $deletes[0]['args'] );
    }

    public function test_run_uses_the_default_when_nothing_is_saved(): void {
        ( new StatsRetention( new StatsRepository( $this->wpdb ) ) )->run();

        $deletes = $this->delete_queries();
        $this->assertCount( 1, $deletes );
        $this->assertContains( gmdate( 'Y-m-d', strtotime( '-365 days' ) ), $deletes[0]['args'] );
    }

    public function test_run_deletes_nothing_when_keeping_everything(): void {
        update_option( Options::OPTION_KEY, [ 'stats_retention_days' => 0 ] );

        ( new StatsRetention( new StatsRepository( $this->wpdb ) ) )->run();

        $this->assertSame( [], $this->delete_queries() );
    }

    public function test_run_treats_a_corrupt_saved_value_as_the_default(): void {
        update_option( Options::OPTION_KEY, [ 'stats_retention_days' => '7; DROP TABLE' ] );

        ( new StatsRetention( new StatsRepository( $this->wpdb ) ) )->run();

        $deletes = $this->delete_queries();
        $this->assertCount( 1, $deletes );
        $this->assertContains( gmdate( 'Y-m-d', strtotime( '-365 days' ) ), $deletes[0]['args'] );
    }

    // ---------------------------------------------------------------------
    // schedule
    // ---------------------------------------------------------------------

    public function test_schedule_adds_one_daily_event(): void {
        StatsRetention::schedule();
        StatsRetention::schedule();

        $events = array_values( array_filter(
            $GLOBALS['_mock_scheduled_events'],
            static fn( array $e ): bool => StatsRetention::HOOK === $e['hook']
        ) );
        $this->assertCount( 1, $events );
        $this->assertSame( 'daily', $events[0]['recurrence'] );
    }
}
