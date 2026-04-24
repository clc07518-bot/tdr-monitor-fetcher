<?php
/*
Plugin Name: TDR Monitor Dashboard & Email Notify
Description: 新着ニュース用ダッシュボードウィジェットとメール通知。
Version: 1.0.1
Author: rin
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

function tdr_mon_mu_load_items( $limit = 30 ) {
    global $wpdb;
    $limit = absint( $limit );
    if ( $limit < 1 ) {
        $limit = 30;
    }
    $sql  = "SELECT option_name, option_value FROM {$wpdb->options} "
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
        $items[ $id ] = $val;
    }
    return $items;
}

function tdr_mon_mu_render_dashboard() {
    $items = tdr_mon_mu_load_items( 30 );
    if ( empty( $items ) ) {
        echo '<p>まだアイテムがありません。fetcher の初回実行を待ってください。</p>';
        return;
    }
    echo '<style>'
        . '.tdrmw-row{border-bottom:1px solid #eee;padding:10px 0;}'
        . '.tdrmw-row:last-child{border-bottom:none;}'
        . '.tdrmw-title{font-weight:600;line-height:1.4;margin-bottom:4px;}'
        . '.tdrmw-meta{font-size:11px;color:#777;margin-bottom:6px;}'
        . '.tdrmw-actions a{margin-right:6px;}'
        . '.tdrmw-src{display:inline-block;padding:1px 6px;background:#f0f0f1;border-radius:3px;margin-right:6px;}'
        . '</style>';
    echo '<div style="max-height:520px;overflow:auto;">';
    foreach ( $items as $id => $it ) {
        $title = isset( $it['title'] ) ? $it['title'] : '(no title)';
        $src   = isset( $it['source'] ) ? $it['source'] : '';
        $date  = '';
        $keys  = array( 'date', 'pubdate', 'published', 'ts' );
        foreach ( $keys as $k ) {
            if ( ! empty( $it[ $k ] ) ) {
                $date = $it[ $k ];
                break;
            }
        }
        $share  = home_url( '/?tdr_share=' . rawurlencode( $id ) );
        $origin = isset( $it['url'] ) ? $it['url'] : '';
        echo '<div class="tdrmw-row">';
        echo '<div class="tdrmw-title">' . esc_html( $title ) . '</div>';
        echo '<div class="tdrmw-meta"><span class="tdrmw-src">' . esc_html( $src ) . '</span>' . esc_html( $date ) . '</div>';
        echo '<div class="tdrmw-actions">';
        echo '<a href="' . esc_url( $share ) . '" target="_blank" class="button button-primary">共有ページ</a>';
        if ( $origin ) {
            echo ' <a href="' . esc_url( $origin ) . '" target="_blank" class="button">元記事</a>';
        }
        echo '</div>';
        echo '</div>';
    }
    echo '</div>';
}

function tdr_mon_mu_register_widget() {
    wp_add_dashboard_widget(
        'tdr_mon_dashboard_widget',
        'TDR Monitor — 新着ニュース（1-tap共有）',
        'tdr_mon_mu_render_dashboard'
    );
}
add_action( 'wp_dashboard_setup', 'tdr_mon_mu_register_widget' );

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
    $items = tdr_mon_mu_load_items( 50 );
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
    // First-run bootstrap: mark everything as seen, don't email.
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
