<?php
/*
Plugin Name: TDR Monitor Dashboard & Email Notify
Description: 新着ニュース用ダッシュボードウィジェットとメール通知。ソース別に最新順表示。
Version: 1.0.2
Author: rin
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Load all stored items (newest-stored first by option_id) keyed by id.
 */
function tdr_mon_mu_load_all_items( $limit = 200 ) {
    global $wpdb;
    $limit = absint( $limit );
    if ( $limit < 1 ) {
        $limit = 200;
    }
    $sql  = "SELECT option_id, option_name, option_value FROM {$wpdb->options} "
          . "WHERE option_name LIKE 'tdr_mon_item_%' "
          . "ORDER BY option_id DESC LIMIT " . $limit;
    $rows = $wpdb->get_results( $sql );
    $items = array();
    if ( ! $rows ) {
        return $items;
    }
    foreach ( $rows as $r ) {
        $val = maybe_unserialize( $r->option_value );
        if ( ! is_array( $val ) && is_string( $r->option_value ) ) {
            $dec = json_decode( $r->option_value, true );
            if ( is_array( $dec ) ) {
                $val = $dec;
            }
        }
        if ( ! is_array( $val ) ) {
            continue;
        }
        $id = substr( $r->option_name, strlen( 'tdr_mon_item_' ) );
        $val['_id']        = $id;
        $val['_option_id'] = (int) $r->option_id;
        if ( ! isset( $val['source'] ) ) {
            $val['source'] = '';
        }
        $items[ $id ] = $val;
    }
    return $items;
}

/**
 * Compute a "freshness" sort key per source so newest items float to the top.
 * Higher key = newer.
 */
function tdr_mon_mu_sort_key( $item ) {
    $src   = isset( $item['source'] ) ? $item['source'] : '';
    $title = isset( $item['title'] ) ? $item['title'] : '';
    $url   = isset( $item['url'] ) ? $item['url'] : '';
    if ( $src === 'prtimes' ) {
        // Extract the press-release number from URL: /p/000000149.000119340.html
        if ( preg_match( '#/p/(\d+)\.\d+\.html#', $url, $m ) ) {
            return (int) $m[1];
        }
    }
    if ( $src === 'stop_tdl' || $src === 'stop_tds' ) {
        // Title pattern: "Name｜YYYY/MM/DD - YYYY/MM/DD" (start date is what we sort by, newest start)
        if ( preg_match( '#(\d{4})/(\d{1,2})/(\d{1,2})#', $title, $m ) ) {
            return (int) sprintf( '%04d%02d%02d', $m[1], $m[2], $m[3] );
        }
    }
    // Fallback: option_id (recently stored = larger)
    return isset( $item['_option_id'] ) ? $item['_option_id'] : 0;
}

function tdr_mon_mu_render_dashboard() {
    $all = tdr_mon_mu_load_all_items( 200 );
    if ( empty( $all ) ) {
        echo '<p>まだアイテムがありません。fetcher の初回実行を待ってください。</p>';
        return;
    }
    // Group by source, sort each group by freshness key DESC
    $groups = array();
    foreach ( $all as $id => $it ) {
        $src = isset( $it['source'] ) && $it['source'] !== '' ? $it['source'] : 'other';
        $groups[ $src ][] = $it;
    }
    foreach ( $groups as $src => &$arr ) {
        usort( $arr, function ( $a, $b ) {
            $ka = tdr_mon_mu_sort_key( $a );
            $kb = tdr_mon_mu_sort_key( $b );
            if ( $ka === $kb ) {
                return 0;
            }
            return $ka < $kb ? 1 : -1;
        } );
    }
    unset( $arr );

    $labels = array(
        'prtimes'  => 'PR TIMES プレスリリース',
        'update'   => '公式 update（イベント告知）',
        'urgent'   => '公式 緊急情報',
        'stop_tdl' => 'TDL 休止情報',
        'stop_tds' => 'TDS 休止情報',
        'olc_tdr'  => 'OLC ニュース',
        'youtube'  => '公式 YouTube',
        'other'    => 'その他',
    );
    $order = array( 'prtimes', 'update', 'urgent', 'olc_tdr', 'stop_tdl', 'stop_tds', 'youtube', 'other' );
    $per_source_limit = 5;

    echo '<style>'
        . '.tdrmw-section{margin-bottom:14px;}'
        . '.tdrmw-section h4{margin:6px 0 4px;font-size:12px;color:#888;text-transform:uppercase;letter-spacing:.5px;border-bottom:1px solid #eee;padding-bottom:3px;}'
        . '.tdrmw-row{padding:7px 0;border-bottom:1px dashed #f0f0f0;}'
        . '.tdrmw-row:last-child{border-bottom:none;}'
        . '.tdrmw-title{font-weight:600;line-height:1.4;margin-bottom:4px;font-size:13px;}'
        . '.tdrmw-actions a{margin-right:5px;}'
        . '.tdrmw-actions{margin-top:3px;}'
        . '.button.tdrmw-tiny{padding:1px 8px;height:auto;font-size:11px;line-height:1.6;}'
        . '</style>';
    echo '<div style="max-height:560px;overflow:auto;">';

    foreach ( $order as $src ) {
        if ( empty( $groups[ $src ] ) ) {
            continue;
        }
        $rows = array_slice( $groups[ $src ], 0, $per_source_limit );
        $label = isset( $labels[ $src ] ) ? $labels[ $src ] : $src;
        $count_total = count( $groups[ $src ] );
        echo '<div class="tdrmw-section">';
        echo '<h4>' . esc_html( $label ) . ' <span style="color:#bbb;font-weight:400;">(' . count( $rows ) . '/' . $count_total . ')</span></h4>';
        foreach ( $rows as $it ) {
            $title  = isset( $it['title'] ) ? $it['title'] : '(no title)';
            $share  = home_url( '/?tdr_share=' . rawurlencode( $it['_id'] ) );
            $origin = isset( $it['url'] ) ? $it['url'] : '';
            echo '<div class="tdrmw-row">';
            echo '<div class="tdrmw-title">' . esc_html( $title ) . '</div>';
            echo '<div class="tdrmw-actions">';
            echo '<a href="' . esc_url( $share ) . '" target="_blank" class="button button-primary tdrmw-tiny">共有ページ</a>';
            if ( $origin ) {
                echo '<a href="' . esc_url( $origin ) . '" target="_blank" class="button tdrmw-tiny">元記事</a>';
            }
            echo '</div>';
            echo '</div>';
        }
        echo '</div>';
    }
    echo '</div>';
}

function tdr_mon_mu_register_widget() {
    wp_add_dashboard_widget(
        'tdr_mon_dashboard_widget',
        'TDR Monitor — 新着ニュース（ソース別 / 最新順）',
        'tdr_mon_mu_render_dashboard'
    );
}
add_action( 'wp_dashboard_setup', 'tdr_mon_mu_register_widget' );

/* ---- Email notify (unchanged from 1.0.1) ---- */

function tdr_mon_mu_cron_schedules( $s ) {
    if ( ! isset( $s['tdr_mon_mu_3min'] ) ) {
        $s['tdr_mon_mu_3min'] = array(
            'interval' => 180,
            'display'  => 'TDR Monitor 3min',
        );
    }
    return $s;
}
add_filter( 'cron_schedules', 'tdr_mon_mu_cron_schedules' );

function tdr_mon_mu_schedule_cron() {
    if ( ! wp_next_scheduled( 'tdr_mon_mu_email_tick' ) ) {
        wp_schedule_event( time() + 60, 'tdr_mon_mu_3min', 'tdr_mon_mu_email_tick' );
    }
}
add_action( 'init', 'tdr_mon_mu_schedule_cron' );

function tdr_mon_mu_unschedule_cron() {
    $ts = wp_next_scheduled( 'tdr_mon_mu_email_tick' );
    if ( $ts ) {
        wp_unschedule_event( $ts, 'tdr_mon_mu_email_tick' );
    }
}
register_deactivation_hook( __FILE__, 'tdr_mon_mu_unschedule_cron' );

function tdr_mon_mu_email_tick() {
    $items = tdr_mon_mu_load_all_items( 200 );
    if ( empty( $items ) ) {
        return;
    }
    $seen = get_option( 'tdr_mon_mu_emailed', array() );
    if ( ! is_array( $seen ) ) {
        $seen = array();
    }
    $new = array();
    foreach ( $items as $id => $it ) {
        if ( ! isset( $seen[ $id ] ) ) {
            $new[ $id ] = $it;
        }
    }
    if ( empty( $new ) ) {
        return;
    }
    if ( empty( $seen ) ) {
        foreach ( $new as $id => $it ) {
            $seen[ $id ] = time();
        }
        update_option( 'tdr_mon_mu_emailed', $seen, false );
        return;
    }
    $to   = get_option( 'tdr_mon_mu_email_to', get_option( 'admin_email' ) );
    $subj = sprintf( '[TDR Monitor] 新着 %d 件', count( $new ) );
    $body = "TDR Monitor が新着ニュースを検出しました。\n\n";
    foreach ( $new as $id => $it ) {
        $title  = isset( $it['title'] ) ? $it['title'] : '(no title)';
        $src    = isset( $it['source'] ) ? $it['source'] : '';
        $origin = isset( $it['url'] ) ? $it['url'] : '';
        $body  .= '[' . $src . '] ' . $title . "\n";
        $body  .= '  共有: ' . home_url( '/?tdr_share=' . rawurlencode( $id ) ) . "\n";
        if ( $origin ) {
            $body .= '  元: ' . $origin . "\n";
        }
        $body .= "\n";
    }
    $body .= '-- ダッシュボード: ' . admin_url() . "\n";
    wp_mail( $to, $subj, $body );
    foreach ( $new as $id => $it ) {
        $seen[ $id ] = time();
    }
    if ( count( $seen ) > 500 ) {
        $seen = array_slice( $seen, -500, null, true );
    }
    update_option( 'tdr_mon_mu_emailed', $seen, false );
}
add_action( 'tdr_mon_mu_email_tick', 'tdr_mon_mu_email_tick' );
