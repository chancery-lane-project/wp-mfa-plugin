<?php

declare(strict_types=1);

namespace Tclp\WpMarkdownForAgents\Stats;

/**
 * Admin page for displaying agent access statistics.
 *
 * Registered as a top-level menu item. Shows a filterable, paginated
 * table of daily access counts by post and agent.
 *
 * @since  1.1.0
 * @package Tclp\WpMarkdownForAgents\Stats
 */
class StatsPage {

    private const PAGE_SLUG = 'wp-mfa-stats';
    private const PER_PAGE  = 50;

    /**
     * @since  1.1.0
     * @param  StatsRepository $repository Stats query layer.
     */
    public function __construct( private readonly StatsRepository $repository ) {}

    /**
     * Register the admin menu page.
     *
     * @since  1.1.0
     */
    public function add_page(): void {
        add_menu_page(
            __( 'Agent Access Statistics', 'wp-markdown-for-agents' ),
            __( 'Agent Stats', 'wp-markdown-for-agents' ),
            'manage_options',
            self::PAGE_SLUG,
            [ $this, 'render_page' ],
            'dashicons-chart-bar'
        );
    }

    /**
     * Render the stats page.
     *
     * @since  1.1.0
     */
    public function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $filter_post_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0;    // phpcs:ignore WordPress.Security.NonceVerification
        $filter_agent   = isset( $_GET['agent'] ) ? sanitize_file_name( (string) $_GET['agent'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
        $paged          = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;             // phpcs:ignore WordPress.Security.NonceVerification

        $filters = [];
        if ( $filter_post_id > 0 ) {
            $filters['post_id'] = $filter_post_id;
        }
        if ( '' !== $filter_agent ) {
            $filters['agent'] = $filter_agent;
        }

        $filters['limit']  = self::PER_PAGE;
        $filters['offset'] = ( $paged - 1 ) * self::PER_PAGE;

        $rows        = $this->repository->get_stats( $filters );
        $total       = $this->repository->get_total_count( $filters );
        $agents      = $this->repository->get_distinct_agents();
        $posts       = $this->repository->get_posts_with_stats();
        $total_pages = (int) ceil( $total / self::PER_PAGE );

        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Agent Access Statistics', 'wp-markdown-for-agents' ); ?></h1>

            <form method="get" action="">
                <input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
                <div class="tablenav top">
                    <select name="post_id">
                        <option value=""><?php esc_html_e( 'All posts', 'wp-markdown-for-agents' ); ?></option>
                        <?php foreach ( $posts as $id => $title ) : ?>
                            <option value="<?php echo esc_attr( (string) $id ); ?>" <?php selected( $filter_post_id, $id ); ?>>
                                <?php echo esc_html( $title ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <select name="agent">
                        <option value=""><?php esc_html_e( 'All agents', 'wp-markdown-for-agents' ); ?></option>
                        <?php foreach ( $agents as $agent ) : ?>
                            <option value="<?php echo esc_attr( $agent ); ?>" <?php selected( $filter_agent, $agent ); ?>>
                                <?php echo esc_html( $agent ); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php submit_button( __( 'Filter', 'wp-markdown-for-agents' ), 'secondary', 'filter', false ); ?>
                </div>
            </form>

            <?php if ( empty( $rows ) ) : ?>
                <p><?php esc_html_e( 'No access data recorded yet.', 'wp-markdown-for-agents' ); ?></p>
            <?php else : ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Post', 'wp-markdown-for-agents' ); ?></th>
                            <th><?php esc_html_e( 'Agent', 'wp-markdown-for-agents' ); ?></th>
                            <th><?php esc_html_e( 'Date', 'wp-markdown-for-agents' ); ?></th>
                            <th><?php esc_html_e( 'Count', 'wp-markdown-for-agents' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $rows as $row ) : ?>
                            <tr>
                                <td><?php echo esc_html( get_the_title( (int) $row->post_id ) ); ?></td>
                                <td><?php echo esc_html( $row->agent ); ?></td>
                                <td><?php echo esc_html( $row->access_date ); ?></td>
                                <td><?php echo esc_html( (string) $row->count ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <?php if ( $total_pages > 1 ) : ?>
                    <div class="tablenav bottom">
                        <div class="tablenav-pages">
                            <?php for ( $i = 1; $i <= $total_pages; $i++ ) : ?>
                                <?php if ( $i === $paged ) : ?>
                                    <span class="tablenav-pages-navspan button disabled"><?php echo esc_html( (string) $i ); ?></span>
                                <?php else : ?>
                                    <a class="button" href="<?php echo esc_url( add_query_arg( 'paged', $i ) ); ?>"><?php echo esc_html( (string) $i ); ?></a>
                                <?php endif; ?>
                            <?php endfor; ?>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }
}
