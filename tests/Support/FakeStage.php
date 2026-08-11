<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Tests\Support;

use Tclp\WpMarkdownForAgents\Jobs\Stage;

/**
 * Stage double: returns queued batch results, records the cursors it was
 * called with, and optionally burns clock time per batch so time-boxing can
 * be tested without sleeping.
 */
final class FakeStage implements Stage {

    /** @var list<int> */
    public array $cursors = [];

    public int $count_total_calls = 0;

    /**
     * @param list<array<string, mixed>> $pages             Batch results, consumed in order.
     * @param int                        $total             count_total() return value.
     * @param FrozenClock|null           $clock             Advanced by $seconds_per_batch per batch.
     * @param int                        $seconds_per_batch Simulated batch duration.
     * @param \Throwable|null            $count_error       Thrown by count_total() when set.
     */
    public function __construct(
        private array $pages,
        private int $total = 0,
        private ?FrozenClock $clock = null,
        private int $seconds_per_batch = 0,
        private ?\Throwable $count_error = null,
    ) {}

    public function count_total(): int {
        ++$this->count_total_calls;

        if ( null !== $this->count_error ) {
            throw $this->count_error;
        }

        return $this->total;
    }

    public function process_batch( int $cursor, int $limit ): array {
        $this->cursors[] = $cursor;

        if ( null !== $this->clock && $this->seconds_per_batch > 0 ) {
            $this->clock->advance( $this->seconds_per_batch );
        }

        $page = array_shift( $this->pages );

        return $page ?? [
            'processed'   => 0,
            'skipped'     => 0,
            'errors'      => [],
            'next_cursor' => $cursor,
            'done'        => true,
        ];
    }
}
