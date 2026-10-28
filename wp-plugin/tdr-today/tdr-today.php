<?php
/*
Plugin Name: TDR Today
Description: 今日のディズニー情報ハブ + 待ち時間ヒートマップ + DPA/PP発券終了トラッカー + 過去データ集計 + Queue-Times 5分粒度 polling + チケット価格表示。Shortcode [tdr_today_hub], [tdr_today_heatmap], [tdr_pass_today], [tdr_history], [tdr_pass_history].
Version: 1.5.13
Author: rin
*/
if(!defined('ABSPATH'))exit;

function tdrt_verified_from(){
  $v=get_option('tdrt_verified_waits_from','');
  if(!$v){$v=current_time('mysql');add_option('tdrt_verified_waits_from',$v,'','no');}
  return $v;
}
add_action('init','tdrt_verified_from');

function tdrt_table(){global $wpdb;return $wpdb->prefix.'tdr_wait_history';}
function tdrt_pass_table(){global $wpdb;return $wpdb->prefix.'tdr_pass_events';}

function tdrt_install(){
  global $wpdb;$tbl=tdrt_table();$cs=$wpdb->get_charset_collate();
  $sql="CREATE TABLE IF NOT EXISTS {$tbl} (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    park VARCHAR(8) NOT NULL,
    attr_id VARCHAR(64) NOT NULL,
    attr_name VARCHAR(160) NOT NULL,
    wait_min SMALLINT NULL,
    status VARCHAR(16) NULL,
    recorded_at DATETIME NOT NULL,
    slot_key BIGINT NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_slot (park, attr_id, slot_key),
    KEY idx_park_recorded (park, recorded_at)
  ) {$cs};";
  require_once ABSPATH.'wp-admin/includes/upgrade.php';
  dbDelta($sql);
  $col=$wpdb->get_row("SHOW COLUMNS FROM {$tbl} LIKE 'slot_key'");
  if($col && stripos($col->Type,'bigint')===false){
    $wpdb->query("ALTER TABLE {$tbl} MODIFY COLUMN slot_key BIGINT NOT NULL");
    $wpdb->query("TRUNCATE TABLE {$tbl}");
  }
  // DPA / プライオリティパス / スタンバイパス の発券開始・終了イベントログ
  $ptbl=tdrt_pass_table();
  $sql2="CREATE TABLE IF NOT EXISTS {$ptbl} (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    park VARCHAR(8) NOT NULL,
    attr_id VARCHAR(64) NOT NULL,
    attr_name VARCHAR(160) NOT NULL,
    pass_type VARCHAR(8) NOT NULL,
    event VARCHAR(16) NOT NULL,
    event_date DATE NOT NULL,
    event_time DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_event (park, attr_id, pass_type, event, event_date),
    KEY idx_park_date (park, event_date)
  ) {$cs};";
  dbDelta($sql2);
}
register_activation_hook(__FILE__,'tdrt_install');
add_action('plugins_loaded',function(){
  if(get_option('tdrt_v','0')!=='1.3.9'){tdrt_install();update_option('tdrt_v','1.3.9',false);}
});

// DWR plugin の名称が公式表記と微妙にズレているのを補正するマップ。
// 公式: https://www.tokyodisneyresort.jp/tdl/attraction.html / /tds/attraction.html
// 公式正式名称は curly double quote (U+201C / U+201D) を使う点に注意。
// キーは DWR が保持している name(誤・略称・英名・straight quote 等)、値は公式の正式名称。
function tdrt_fix_name($n){
  $alias=[
    "“it's a small world”"=>'イッツ・ア・スモールワールド',
    'The Enchanted Tiki Room: Stitch Presents “Aloha E Komo Mai!”'=>'魅惑のチキルーム：スティッチ・プレゼンツ“アロハ・エ・コモ・マイ！”'
  ];
  if(isset($alias[$n]))return $alias[$n];
  static $map = null;
  if($map === null){
    $map = [
      // ───────── TDL: 公式正式名称（identity） ─────────
      'オムニバス' => 'オムニバス',
      'ペニーアーケード' => 'ペニーアーケード',
      'ウエスタンリバー鉄道' => 'ウエスタンリバー鉄道',
      'カリブの海賊' => 'カリブの海賊',
      'ジャングルクルーズ：ワイルドライフ・エクスペディション' => 'ジャングルクルーズ：ワイルドライフ・エクスペディション',
      'スイスファミリー・ツリーハウス' => 'スイスファミリー・ツリーハウス',
      '魅惑のチキルーム：スティッチ・プレゼンツ“アロハ・エ・コモ・マイ！”' => '魅惑のチキルーム：スティッチ・プレゼンツ“アロハ・エ・コモ・マイ！”',
      'ウエスタンランド・シューティングギャラリー' => 'ウエスタンランド・シューティングギャラリー',
      'カントリーベア・シアター' => 'カントリーベア・シアター',
      '蒸気船マークトウェイン号' => '蒸気船マークトウェイン号',
      'トムソーヤ島いかだ' => 'トムソーヤ島いかだ',
      'ビッグサンダー・マウンテン' => 'ビッグサンダー・マウンテン',
      'スプラッシュ・マウンテン' => 'スプラッシュ・マウンテン',
      'ビーバーブラザーズのカヌー探険' => 'ビーバーブラザーズのカヌー探険',
      'アリスのティーパーティー' => 'アリスのティーパーティー',
      'イッツ・ア・スモールワールド' => 'イッツ・ア・スモールワールド',
      'キャッスルカルーセル' => 'キャッスルカルーセル',
      '白雪姫と七人のこびと' => '白雪姫と七人のこびと',
      'シンデレラのフェアリーテイル・ホール' => 'シンデレラのフェアリーテイル・ホール',
      '空飛ぶダンボ' => '空飛ぶダンボ',
      '美女と野獣“魔法のものがたり”' => '美女と野獣“魔法のものがたり”',
      'ピーターパン空の旅' => 'ピーターパン空の旅',
      'ピノキオの冒険旅行' => 'ピノキオの冒険旅行',
      'プーさんのハニーハント' => 'プーさんのハニーハント',
      'ホーンテッドマンション' => 'ホーンテッドマンション',
      'ミッキーのフィルハーマジック' => 'ミッキーのフィルハーマジック',
      'ガジェットのゴーコースター' => 'ガジェットのゴーコースター',
      'グーフィーのペイント＆プレイハウス' => 'グーフィーのペイント＆プレイハウス',
      'チップとデールのツリーハウス' => 'チップとデールのツリーハウス',
      'トゥーンパーク' => 'トゥーンパーク',
      'ドナルドのボート' => 'ドナルドのボート',
      'ミニーの家' => 'ミニーの家',
      'ロジャーラビットのカートゥーンスピン' => 'ロジャーラビットのカートゥーンスピン',
      'スター・ツアーズ：ザ・アドベンチャーズ・コンティニュー' => 'スター・ツアーズ：ザ・アドベンチャーズ・コンティニュー',
      'スティッチ・エンカウンター' => 'スティッチ・エンカウンター',
      'ベイマックスのハッピーライド' => 'ベイマックスのハッピーライド',
      'モンスターズ・インク“ライド＆ゴーシーク！”' => 'モンスターズ・インク“ライド＆ゴーシーク！”',
      // ───────── TDL: DWR/英名/別表記 → 公式 ─────────
      'プーさゕのハニーハント' => 'プーさんのハニーハント',
      "The Enchanted Tiki Room: Stitch Presents 'Aloha E Komo Mai!'" => '魅惑のチキルーム：スティッチ・プレゼンツ“アロハ・エ・コモ・マイ！”',
      '魅惑のチキルーム' => '魅惑のチキルーム：スティッチ・プレゼンツ“アロハ・エ・コモ・マイ！”',
      '魅惑のチキルーム：スティッチ・プレゼンツ"アロハ・エ・コモ・マイ！"' => '魅惑のチキルーム：スティッチ・プレゼンツ“アロハ・エ・コモ・マイ！”',
      "it's a small world" => 'イッツ・ア・スモールワールド',
      "It's a small world" => 'イッツ・ア・スモールワールド',
      'イッツ・ア・スモールワールド with Groot' => 'イッツ・ア・スモールワールド',
      "it's a small world with Groot" => 'イッツ・ア・スモールワールド',
      'スター・ツアーズ' => 'スター・ツアーズ：ザ・アドベンチャーズ・コンティニュー',
      'Star Tours: The Adventures Continue' => 'スター・ツアーズ：ザ・アドベンチャーズ・コンティニュー',
      'ジャングルクルーズ' => 'ジャングルクルーズ：ワイルドライフ・エクスペディション',
      'Jungle Cruise: Wildlife Expeditions' => 'ジャングルクルーズ：ワイルドライフ・エクスペディション',
      '美女と野獣 魔法のものがたり' => '美女と野獣“魔法のものがたり”',
      '美女と野獣"魔法のものがたり"' => '美女と野獣“魔法のものがたり”',
      'モンスターズ・インク' => 'モンスターズ・インク“ライド＆ゴーシーク！”',
      'モンスターズ・インク ライド&ゴーシーク!' => 'モンスターズ・インク“ライド＆ゴーシーク！”',
      'モンスターズ・インク ライド＆ゴーシーク！' => 'モンスターズ・インク“ライド＆ゴーシーク！”',
      'モンスターズ・インク"ライド＆ゴーシーク！"' => 'モンスターズ・インク“ライド＆ゴーシーク！”',
      'Monsters, Inc. Ride & Go Seek!' => 'モンスターズ・インク“ライド＆ゴーシーク！”',
      'ビーバーブラザーズのカヌー探検' => 'ビーバーブラザーズのカヌー探険',
      'カントリーベア' => 'カントリーベア・シアター',
      'シンデレラのフェアリーテイルホール' => 'シンデレラのフェアリーテイル・ホール',
      'Big Thunder Mountain' => 'ビッグサンダー・マウンテン',
      'Splash Mountain' => 'スプラッシュ・マウンテン',
      'Haunted Mansion' => 'ホーンテッドマンション',
      "Mickey's PhilharMagic" => 'ミッキーのフィルハーマジック',
      'Pooh\'s Hunny Hunt' => 'プーさんのハニーハント',
      'Stitch Encounter' => 'スティッチ・エンカウンター',
      "Baymax's Happy Ride" => 'ベイマックスのハッピーライド',
      "Pirates of the Caribbean" => 'カリブの海賊',
      "Western River Railroad" => 'ウエスタンリバー鉄道',

      // ───────── TDS: 公式正式名称（identity） ─────────
      'ヴェネツィアン・ゴンドラ' => 'ヴェネツィアン・ゴンドラ',
      'ソアリン：ファンタスティック・フライト' => 'ソアリン：ファンタスティック・フライト',
      'ディズニーシー・トランジットスチーマーライン' => 'ディズニーシー・トランジットスチーマーライン',
      'フォートレス・エクスプロレーション' => 'フォートレス・エクスプロレーション',
      'フォートレス・エクスプロレーション“ザ・レオナルドチャレンジ”' => 'フォートレス・エクスプロレーション“ザ・レオナルドチャレンジ”',
      'タートル・トーク' => 'タートル・トーク',
      'タワー・オブ・テラー' => 'タワー・オブ・テラー',
      'ディズニーシー・エレクトリックレールウェイ' => 'ディズニーシー・エレクトリックレールウェイ',
      'トイ・ストーリー・マニア！' => 'トイ・ストーリー・マニア！',
      'ビッグシティ・ヴィークル' => 'ビッグシティ・ヴィークル',
      'アクアトピア' => 'アクアトピア',
      'ニモ＆フレンズ・シーライダー' => 'ニモ＆フレンズ・シーライダー',
      'レイジングスピリッツ' => 'レイジングスピリッツ',
      'アナとエルサのフローズンジャーニー' => 'アナとエルサのフローズンジャーニー',
      'ピーターパンのネバーランドアドベンチャー' => 'ピーターパンのネバーランドアドベンチャー',
      'フェアリー・ティンカーベルのビジーバギー' => 'フェアリー・ティンカーベルのビジーバギー',
      'ラプンツェルのランタンフェスティバル' => 'ラプンツェルのランタンフェスティバル',
      'キャラバンカルーセル' => 'キャラバンカルーセル',
      'ジャスミンのフライングカーペット' => 'ジャスミンのフライングカーペット',
      'シンドバッド・ストーリーブック・ヴォヤッジ' => 'シンドバッド・ストーリーブック・ヴォヤッジ',
      'マジックランプシアター' => 'マジックランプシアター',
      'アリエルのプレイグラウンド' => 'アリエルのプレイグラウンド',
      'ジャンピン・ジェリーフィッシュ' => 'ジャンピン・ジェリーフィッシュ',
      'スカットルのスクーター' => 'スカットルのスクーター',
      'フランダーのフライングフィッシュコースター' => 'フランダーのフライングフィッシュコースター',
      'ブローフィッシュ・バルーンレース' => 'ブローフィッシュ・バルーンレース',
      'マーメイドラグーンシアター' => 'マーメイドラグーンシアター',
      'ワールプール' => 'ワールプール',
      '海底2万マイル' => '海底2万マイル',
      '海底２万マイル' => '海底2万マイル',
      'センター・オブ・ジ・アース' => 'センター・オブ・ジ・アース',
      // ───────── TDS: DWR/英名/別表記 → 公式 ─────────
      'ソアリン' => 'ソアリン：ファンタスティック・フライト',
      'ソアリン:ファンタスティック・フライト' => 'ソアリン：ファンタスティック・フライト',
      'Soaring: Fantastic Flight' => 'ソアリン：ファンタスティック・フライト',
      'トイ・ストーリー・マニア' => 'トイ・ストーリー・マニア！',
      'Toy Story Mania!' => 'トイ・ストーリー・マニア！',
      'ニモ&フレンズ・シーライダー' => 'ニモ＆フレンズ・シーライダー',
      'Nemo & Friends SeaRider' => 'ニモ＆フレンズ・シーライダー',
      // ® は公式トップ/listingでは省略されるが、DPA/詳細ページや式典表記で残るので canonical はキープ
      'インディ・ジョーンズ・アドベンチャー' => 'インディ・ジョーンズ®・アドベンチャー：クリスタルスカルの魔宮',
      'インディ・ジョーンズ・アドベンチャー：クリスタルスカルの魔宮' => 'インディ・ジョーンズ®・アドベンチャー：クリスタルスカルの魔宮',
      'インディ・ジョーンズ(R)・アドベンチャー：クリスタルスカルの魔宮' => 'インディ・ジョーンズ®・アドベンチャー：クリスタルスカルの魔宮',
      'ティンカーベルのビジーバギー' => 'フェアリー・ティンカーベルのビジーバギー',
      'フォートレス・エクスプロレーション"ザ・レオナルドチャレンジ"' => 'フォートレス・エクスプロレーション“ザ・レオナルドチャレンジ”',
      'フォートレス・エクスプロレーション ザ・レオナルドチャレンジ' => 'フォートレス・エクスプロレーション“ザ・レオナルドチャレンジ”',
    ];
  }
  $key = trim((string)$n);
  return $map[$key] ?? $n;
}

add_filter('cron_schedules',function($s){
  if(!isset($s['tdrt_15min']))$s['tdrt_15min']=['interval'=>900,'display'=>'TDRT 15min'];
  if(!isset($s['tdrt_5min']))$s['tdrt_5min']=['interval'=>300,'display'=>'TDRT 5min'];
  return $s;
});
add_action('init',function(){
  if(!wp_next_scheduled('tdrt_snapshot'))wp_schedule_event(time()+30,'tdrt_15min','tdrt_snapshot');
  // Queue-Times 5分間隔 poller (v1.3.8〜)。GHA throttle 非依存で待ち時間データを最新化する。
  if(!wp_next_scheduled('tdrt_qt_refresh'))wp_schedule_event(time()+15,'tdrt_5min','tdrt_qt_refresh');
});
add_action('tdrt_qt_refresh','tdrt_queuetimes_fetch_all');
register_deactivation_hook(__FILE__,function(){
  $t=wp_next_scheduled('tdrt_snapshot');if($t)wp_unschedule_event($t,'tdrt_snapshot');
  $t2=wp_next_scheduled('tdrt_qt_refresh');if($t2)wp_unschedule_event($t2,'tdrt_qt_refresh');
});

// ── Queue-Times 統合 (v1.3.8) ─────────────────────────────────────
// queue-times.com 公開APIから5分粒度で待ち時間を取得し tdrt_waits_<park> を更新。
// GHA cron が throttle されても影響を受けない（純粋な HTTP fetch）。
// DPA/PP/SBP バッジ情報は提供されないので、それらは引き続き fetcher.py 経由 (/realtime endpoint)。
// ID→日本語名マップで照合するため、英名表記揺れに強い。
function tdrt_queuetimes_id_map(){
  return [
    // ── TDL (park id 274) ──
    7985 => 'オムニバス',
    7986 => 'カリブの海賊',
    7987 => 'ジャングルクルーズ：ワイルドライフ・エクスペディション',
    7988 => 'ウエスタンリバー鉄道',
    7989 => 'スイスファミリー・ツリーハウス',
    7990 => '魅惑のチキルーム：スティッチ・プレゼンツ“アロハ・エ・コモ・マイ！”',
    7991 => 'ウエスタンランド・シューティングギャラリー',
    7992 => 'カントリーベア・シアター',
    7993 => '蒸気船マークトウェイン号',
    7994 => 'ビッグサンダー・マウンテン',
    7995 => 'トムソーヤ島いかだ',
    7996 => 'スプラッシュ・マウンテン',
    7997 => 'ビーバーブラザーズのカヌー探険',
    7998 => 'ピーターパン空の旅',
    7999 => '白雪姫と七人のこびと',
    8000 => 'シンデレラのフェアリーテイル・ホール',
    8001 => 'ミッキーのフィルハーマジック',
    8002 => 'ピノキオの冒険旅行',
    8003 => '空飛ぶダンボ',
    8004 => 'キャッスルカルーセル',
    8005 => 'ホーンテッドマンション',
    8006 => 'イッツ・ア・スモールワールド',
    8007 => 'アリスのティーパーティー',
    8008 => 'プーさんのハニーハント',
    8009 => 'ロジャーラビットのカートゥーンスピン',
    8010 => 'ミニーの家',
    8011 => 'チップとデールのツリーハウス',
    8012 => 'ガジェットのゴーコースター',
    8013 => 'ドナルドのボート',
    8014 => 'グーフィーのペイント＆プレイハウス',
    8015 => 'スター・ツアーズ：ザ・アドベンチャーズ・コンティニュー',
    8018 => 'モンスターズ・インク“ライド＆ゴーシーク！”',
    8019 => 'ペニーアーケード',
    8020 => 'トゥーンパーク',
    8021 => 'スティッチ・エンカウンター',
    8254 => 'ベイマックスのハッピーライド',
    8255 => '美女と野獣“魔法のものがたり”',
    // ── TDS (park id 275) ──
    8022 => 'アリエルのプレイグラウンド',
    8023 => 'トイ・ストーリー・マニア！',
    8024 => 'ソアリン：ファンタスティック・フライト',
    8025 => 'ジャスミンのフライングカーペット',
    8026 => 'マーメイドラグーンシアター',
    8027 => 'インディ・ジョーンズ®・アドベンチャー：クリスタルスカルの魔宮',
    8028 => 'センター・オブ・ジ・アース',
    8029 => '海底2万マイル',
    8030 => 'マジックランプシアター',
    // 8031-8033 (Steamer Line) と 8035-8036 (Electric Railway) は同一アトラクションの別駅 → 重複は dedupe
    8031 => 'ディズニーシー・トランジットスチーマーライン',
    8032 => 'ディズニーシー・トランジットスチーマーライン',
    8033 => 'ディズニーシー・トランジットスチーマーライン',
    8034 => 'ヴェネツィアン・ゴンドラ',
    8035 => 'ディズニーシー・エレクトリックレールウェイ',
    8036 => 'ディズニーシー・エレクトリックレールウェイ',
    8037 => 'ビッグシティ・ヴィークル',
    8038 => 'アクアトピア',
    8039 => 'シンドバッド・ストーリーブック・ヴォヤッジ',
    8040 => 'キャラバンカルーセル',
    8041 => 'フランダーのフライングフィッシュコースター',
    8042 => 'スカットルのスクーター',
    8043 => 'ジャンピン・ジェリーフィッシュ',
    8044 => 'ブローフィッシュ・バルーンレース',
    8045 => 'ワールプール',
    8046 => 'レイジングスピリッツ',
    8047 => 'タワー・オブ・テラー',
    8048 => 'フォートレス・エクスプロレーション',
    8049 => 'フォートレス・エクスプロレーション“ザ・レオナルドチャレンジ”',
    8050 => 'タートル・トーク',
    8051 => 'ニモ＆フレンズ・シーライダー',
    13559 => 'アナとエルサのフローズンジャーニー',
    13560 => 'ラプンツェルのランタンフェスティバル',
    13561 => 'ピーターパンのネバーランドアドベンチャー',
    13562 => 'フェアリー・ティンカーベルのビジーバギー',
  ];
}

function tdrt_queuetimes_fetch_all(){
  $tdl = tdrt_fetch_queuetimes('tdl');
  $tds = tdrt_fetch_queuetimes('tds');
  update_option('tdrt_qt_last', ['tdl'=>$tdl, 'tds'=>$tds, 'ts'=>time()], false);
  $greetings=[];foreach(['tdl','tds'] as $park)$greetings[$park]=tdrt_fetch_official_greetings($park);
  update_option('tdrt_greet_fetch_last',$greetings,false);
  tdrt_snapshot();
}

function tdrt_fetch_queuetimes($park){
  $park_id = ($park === 'tdl') ? 274 : 275;
  $url = "https://queue-times.com/parks/{$park_id}/queue_times.json";
  $r = wp_remote_get($url, ['timeout' => 10, 'user-agent' => 'TDR-Today-Plugin/1.3.8']);
  if(is_wp_error($r)) return ['error' => $r->get_error_message()];
  $code = wp_remote_retrieve_response_code($r);
  if($code !== 200) return ['error' => "HTTP {$code}"];
  $body = wp_remote_retrieve_body($r);
  $d = json_decode($body, true);
  if(!is_array($d)) return ['error' => 'json parse'];
  // rides は top-level または lands[].rides にある
  $rides = $d['rides'] ?? [];
  if(!empty($d['lands']) && is_array($d['lands'])){
    foreach($d['lands'] as $land){
      if(is_array($land) && !empty($land['rides'])){
        foreach($land['rides'] as $ride) $rides[] = $ride;
      }
    }
  }
  if(empty($rides)) return ['error' => 'no rides'];

  $id_map = tdrt_queuetimes_id_map();
  $rows = [];
  $by_name = []; // 重複 dedupe (Steamer Line など同名複数駅)
  $unmapped = [];
  foreach($rides as $ride){
    $rid = (int)($ride['id'] ?? 0);
    if(!isset($id_map[$rid])){
      $unmapped[] = ['id' => $rid, 'name' => $ride['name'] ?? ''];
      continue;
    }
    $observed = isset($ride['last_updated']) ? strtotime($ride['last_updated']) : false;
    if(!$observed || $observed > time()+60 || $observed < time()-1200)continue;
    $jp = $id_map[$rid];
    $is_open = !empty($ride['is_open']);
    $wait = isset($ride['wait_time']) ? (int)$ride['wait_time'] : null;
    if(!$is_open) $wait = null;  // 閉鎖中は wait を null に
    if(isset($by_name[$jp])){
      // 既に同名あり → wait の最大値を採用 (運行中の駅優先)
      $existing = &$by_name[$jp];
      if($existing['wait_min'] === null && $wait !== null) $existing['wait_min'] = $wait;
      elseif($wait !== null && $existing['wait_min'] !== null && $wait > $existing['wait_min']) $existing['wait_min'] = $wait;
      if($is_open) $existing['status'] = 'operating';
      unset($existing);
      continue;
    }
    $by_name[$jp] = [
      'name' => $jp,
      'wait_min' => $wait,
      'status' => $is_open ? 'operating' : 'closed',
      'observed_ts' => $observed,
    ];
  }
  $previous=get_option('tdrt_waits_'.$park,[]);
  $rows=tdrt_with_previous(array_values($by_name),is_array($previous)?($previous['rows']??[]):[],$park);
  if(empty($rows)) return ['error' => 'no mapped rides', 'unmapped_count' => count($unmapped)];

  // tdrt_waits_<park> に保存
  $payload = serialize(['rows' => $rows, 'ts' => time(), 'source' => 'queue-times']);
  $key = 'tdrt_waits_'.$park;
  global $wpdb;
  $existing_id = $wpdb->get_var($wpdb->prepare("SELECT option_id FROM {$wpdb->options} WHERE option_name = %s", $key));
  if($existing_id){
    $wpdb->update($wpdb->options, ['option_value'=>$payload, 'autoload'=>'no'], ['option_name'=>$key], ['%s','%s'], ['%s']);
  } else {
    $wpdb->insert($wpdb->options, ['option_name'=>$key, 'option_value'=>$payload, 'autoload'=>'no'], ['%s','%s','%s']);
  }
  wp_cache_delete($key, 'options');
  return [
    'park' => $park,
    'count' => count($rows),
    'unmapped' => count($unmapped),
    'unmapped_sample' => array_slice($unmapped, 0, 3),
  ];
}

// REST: /tdr-today/v1/qt-refresh — 外部cron (cron-job.org など) からの手動 trigger
// v1.3.9: GET/POST 共に X-TDR-Token 必須に変更（public repo 環境での DoS 加担防止）。
// cron-job.org 側で「Custom HTTP headers」に `X-TDR-Token: <token>` を設定すれば動く。
// または query 引数 ?token=<token> でも認証可（一部 cron サービス header 非対応のため）。
add_action('rest_api_init', function(){
  register_rest_route('tdr-today/v1', '/qt-refresh', [
    'methods' => ['GET','POST'],
    'permission_callback' => function($req){
      $expected = (string) get_option('tdr_mon_ingest_token', '');
      if($expected === '') return false;
      $token = (string) $req->get_header('x-tdr-token');
      if($token === '') $token = (string) $req->get_param('token');
      return $token !== '' && hash_equals($expected, $token);
    },
    'callback' => function($req){
      tdrt_queuetimes_fetch_all();
      return get_option('tdrt_qt_last', []);
    },
  ]);
});

function tdrt_slot($ts=null){
  if($ts===null)$ts=current_time('timestamp');
  $m=(int)date('i',$ts);$r=$m<30?0:30;
  return (int)date('YmdH',$ts)*100+$r;
}
function tdrt_aid($en,$jp){return substr(md5($en!==''?$en:$jp),0,16);}

function tdrt_snapshot($attractions_only=false){
  global $wpdb;$tbl=tdrt_table();$now=current_time('mysql');$slot=tdrt_slot();$n=0;
  // park='tdl'/'tds' は tdrt_wait_data_raw() の優先度判定（tdrt_waits → dwr_latest → dwr_prev）に乗せる。
  // park='tdl_g'/'tds_g' は キャラグリ専用 option。
  $sources = [
    'tdl'   => null, // tdrt_wait_data_raw('tdl')
    'tds'   => null, // tdrt_wait_data_raw('tds')
    'tdl_g' => 'tdrt_greet_tdl',
    'tds_g' => 'tdrt_greet_tds',
  ];
  foreach($sources as $park=>$opt_key){
    if($attractions_only && $opt_key!==null)continue;
    $base_park = (substr($park,-2)==='_g') ? substr($park,0,3) : $park;
    if(!tdrt_is_open($base_park))continue; // 閉園中は記録しない
    if($opt_key === null){
      $rows = tdrt_wait_data_raw($base_park);
    } else {
      $rows = tdrt_greet_data($base_park);
    }
    if(!is_array($rows))continue;
    foreach($rows as $r){
      $name=isset($r['name'])?(string)$r['name']:'';
      $en=isset($r['name_en'])?(string)$r['name_en']:'';
      if($name===''&&$en==='')continue;
      $name=tdrt_fix_name($name);
      $aid=$opt_key===null?tdrt_aid('', $name):tdrt_aid($en,$name);
      $recorded=$now; $record_slot=$slot;
      {
        $at=(int)($r['observed_ts']??($opt_key!==null?get_option('tdrt_greet_observed_'.$base_park,0):0));
        if(!$at || wp_date('Y-m-d',$at)!==wp_date('Y-m-d'))continue;
        $recorded=wp_date('Y-m-d H:i:s',$at);
        if($recorded<tdrt_verified_from())continue;
        $minute=(int)wp_date('i',$at)<30?'00':'30';
        $record_slot=(int)(wp_date('YmdH',$at).$minute);
      }
      if(tdrt_t2m(substr($recorded,11,5))>=tdrt_history_close($base_park,substr($recorded,0,10)))continue;
      $name=tdrt_fix_name($name); // 公式表記へ正規化してから保存
      $w=(isset($r['wait_min'])&&$r['wait_min']!==''&&$r['wait_min']!==null)?(int)$r['wait_min']:null;
      $st=$r['history_status']??($r['status']??'');
      if(tdrt_is_history_stopped($name,tdrt_history_stops($base_park,substr($recorded,0,10))))$st='all_day_closed';
      if($st!=='operating')$w=null;
      $cols='park,attr_id,attr_name,wait_min,status,recorded_at,slot_key';
      $ph=$w===null?'%s,%s,%s,NULL,%s,%s,%d':'%s,%s,%s,%d,%s,%s,%d';
      $args=$w===null?[$park,$aid,$name?:$en,$st,$recorded,$record_slot]:[$park,$aid,$name?:$en,$w,$st,$recorded,$record_slot];
      $wpdb->query($wpdb->prepare("INSERT INTO {$tbl} ({$cols}) VALUES ({$ph}) ON DUPLICATE KEY UPDATE wait_min=IF(VALUES(recorded_at)>=recorded_at,VALUES(wait_min),wait_min),status=IF(VALUES(recorded_at)>=recorded_at,VALUES(status),status),recorded_at=GREATEST(recorded_at,VALUES(recorded_at))",$args));
      $n++;
    }
  }
  update_option('tdrt_last_snap',time(),false);
  update_option('tdrt_last_count',$n,false);
}
add_action('tdrt_snapshot','tdrt_snapshot');

// Fetch Happy Entry CSV (plain HTTP) → returns ['tdl'=>'8:45','tds'=>'8:15'] for today, or null
function tdrt_fetch_happy_entry(){
  $cached = get_transient('tdrt_he');
  if($cached !== false) return $cached;
  $url = 'https://information.disneyhotels.jp/happyentry/csv_list/entry.csv';
  $r = wp_remote_get($url, [
    'timeout' => 10,
    'headers' => [
      'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
      'Referer' => 'https://information.disneyhotels.jp/happyentry/',
    ],
  ]);
  if(is_wp_error($r) || wp_remote_retrieve_response_code($r) !== 200){
    set_transient('tdrt_he', null, 5*MINUTE_IN_SECONDS);
    return null;
  }
  $body = wp_remote_retrieve_body($r);
  $today_ymd = wp_date('Ymd');
  foreach(preg_split('/\r?\n/', $body) as $line){
    $cols = explode(',', $line);
    if(count($cols) >= 3 && $cols[0] === $today_ymd){
      $out = ['tdl' => trim($cols[1]), 'tds' => trim($cols[2])];
      set_transient('tdrt_he', $out, 6*HOUR_IN_SECONDS);
      return $out;
    }
  }
  set_transient('tdrt_he', null, 30*MINUTE_IN_SECONDS);
  return null;
}

// Read calendar hours: prefer per-day option populated by fetcher.py (Akamai-protected → fetcher uses Playwright)
function tdrt_fetch_calendar(){
  $today_ymd = wp_date('Ymd');
  $stored = get_option('tdrt_park_hours_'.$today_ymd, null);
  if(is_array($stored)) return $stored;
  return null;
}

function tdrt_hours($park){
  $cal = tdrt_fetch_calendar();
  if(is_array($cal) && isset($cal[$park])) return $cal[$park];
  // Fallback: dwr_park_hours_json (if disney-wait-ranking populates it)
  $raw=get_option('dwr_park_hours_json','');
  if(empty($raw))return null;
  $d=is_array($raw)?$raw:json_decode($raw,true);
  if(!is_array($d))return null;
  $t=wp_date('Y-m-d');
  if(isset($d[$park][$t]))return $d[$park][$t];
  if(isset($d[strtoupper($park)][$t]))return $d[strtoupper($park)][$t];
  return null;
}

// Is park currently within operating hours? Falls back to 8:00-21:30 default.
// Compare in minutes-since-midnight: avoids the strcmp("10:45","9:00") < 0 bug
// where "1" < "9" lexicographically classifies post-9am times as before 9.
function tdrt_t2m($t){
  if(!preg_match('/^(\d{1,2}):(\d{2})$/', $t, $m)) return -1;
  return ((int)$m[1]) * 60 + ((int)$m[2]);
}
// DB DATETIME → HH:MM 表示用ヘルパー（v1.5.3）
// strtotime() は PHP の date_default_timezone (通常 UTC) で解釈するため、
// JST で書き込まれた DB 値を渡すと wp_date('H:i') 経由で +9h ズレる。
// このヘルパーは「DB値は WP timezone (JST) で書かれている」と明示してパースする。
function tdrt_db_hi($mysql_dt){
  if(!$mysql_dt || $mysql_dt === '0000-00-00 00:00:00') return '—';
  $dt = date_create_from_format('Y-m-d H:i:s', $mysql_dt, wp_timezone());
  return $dt ? $dt->format('H:i') : '—';
}

function tdrt_is_open($park){
  $now = tdrt_t2m(current_time('H:i'));
  $h = tdrt_hours($park);
  if($h && isset($h['open']) && isset($h['close'])){
    $o = tdrt_t2m($h['open']);
    $cl = tdrt_t2m($h['close']);
    return $o >= 0 && $cl >= 0 && $now >= $o && $now < $cl;
  }
  return $now >= 480 && $now < 1260; // 08:00 to 21:00 when official hours are unavailable
}

function tdrt_items_by_src($srcs,$lim=100){
  global $wpdb;
  if(!is_array($srcs))$srcs=[$srcs];
  // Long-running closures must not fall out of the newest 200 general news items.
  $stop_sources=!array_diff($srcs,['stop_tdl','stop_tds']);
  $official_items=[];
  if($stop_sources){
    $legacy_sources=[];
    foreach($srcs as $source){
      $park=substr($source,5);
      $snapshot=get_option('tdrt_closures_'.$park,[]);
      if(is_array($snapshot) && !empty($snapshot['checked_at']) && !empty($snapshot['items']) && is_array($snapshot['items'])){
        foreach($snapshot['items'] as $item)$official_items[]=$item;
      }else{$legacy_sources[]=$source;}
    }
    if(!$legacy_sources)return array_slice($official_items,0,$lim);
    $srcs=$legacy_sources;
  }
  $where='';
  if($stop_sources){
    $parts=[];foreach($srcs as $src)$parts[]=$wpdb->prepare('option_value LIKE %s','%'. $wpdb->esc_like('"'.$src.'"') .'%');
    $where=' AND ('.implode(' OR ',$parts).')';
  }
  $limit=$stop_sources?2000:200;
  $rows=$wpdb->get_results("SELECT option_id,option_name,option_value FROM {$wpdb->options} WHERE option_name LIKE 'tdr_mon_item_%' {$where} ORDER BY option_id DESC LIMIT {$limit}");
  $out=$official_items;$seen_stops=[];
  foreach($rows as $r){
    $v=maybe_unserialize($r->option_value);
    if(!is_array($v)&&is_string($r->option_value)){$d=json_decode($r->option_value,true);if(is_array($d))$v=$d;}
    if(!is_array($v))continue;
    if(!in_array($v['source']??'',$srcs,true))continue;
    // Official closure end verified 2026-10-13: /tds/attraction/detail/222/.
    // Correct only the legacy open-ended record; retain later official periods.
    if(($v['source']??'')==='stop_tds' && preg_match('#^インディ・ジョーンズ・アドベンチャー：クリスタルスカルの魔宮｜2025/8/18\s*-\s*未定$#u',$v['title']??'')){
      $v['title']='インディ・ジョーンズ・アドベンチャー：クリスタルスカルの魔宮｜2025/8/18 - 2026/11/29';
      $v['pubdate']='2025/8/18 - 2026/11/29';
    }
    if($stop_sources){
      $period=tdrt_parse_stop_period($v['title']??'');
      if($period){
        $key=($v['source']??'').'|'.tdrt_stop_key(explode('｜',$v['title'])[0]).'|'.$period['start'];
        if(isset($seen_stops[$key]))continue;
        $seen_stops[$key]=true;
      }
    }
    $v['_id']=substr($r->option_name,strlen('tdr_mon_item_'));
    $out[]=$v;
    if(count($out)>=$lim)break;
  }
  return $out;
}

// Live park pages must not retain an old closure list in page/browser caches.
add_action('template_redirect',function(){
  if(is_page(['today-tdl','today-tds'])){
    if(!defined('DONOTCACHEPAGE'))define('DONOTCACHEPAGE',true);
    nocache_headers();
  }
},0);

// Official closure snapshots replace historical news records, never merge with them.
function tdrt_closure_health($park){
  $v=get_option('tdrt_closures_'.$park,[]);
  $checked=is_array($v)?(int)($v['checked_at']??0):0;
  $count=is_array($v['items']??null)?count($v['items']):0;
  return ['park'=>$park,'checked_at'=>$checked,'checked_at_jst'=>$checked?wp_date('Y-m-d H:i:s',$checked):null,
    'age_seconds'=>$checked?max(0,time()-$checked):null,'count'=>$count,
    'healthy'=>$checked>0 && $count>0 && time()-$checked<=DAY_IN_SECONDS];
}

function tdrt_validate_closure_items($park,$items){
  if(!in_array($park,['tdl','tds'],true) || !is_array($items) || !$items || count($items)>300)
    return new WP_Error('invalid_closures','A complete, non-empty official closure list is required.',['status'=>400]);
  $out=[];$seen=[];
  foreach($items as $item){
    if(!is_array($item))return new WP_Error('invalid_closure','Invalid closure item.',['status'=>400]);
    $title=sanitize_text_field($item['title']??'');
    $url=esc_url_raw($item['url']??'');
    $period=tdrt_parse_stop_period($title);
    if(!$period || !preg_match('#｜\d{4}/\d{1,2}/\d{1,2}(?:\s*-\s*(?:\d{4}/\d{1,2}/\d{1,2}|未定))?$#u',$title) || wp_parse_url($url,PHP_URL_HOST)!=='www.tokyodisneyresort.jp' || strpos(wp_parse_url($url,PHP_URL_PATH)??'','/'.$park.'/')!==0)
      return new WP_Error('invalid_closure','Official park URL and valid period are required.',['status'=>400]);
    foreach([$period['start'],$period['end']] as $date){
      if($date===null)continue;
      [$y,$m,$d]=array_map('intval',explode('-',$date));
      if(!checkdate($m,$d,$y))return new WP_Error('invalid_closure_date','Invalid date.',['status'=>400]);
    }
    if($period['end']!==null && $period['end']<$period['start'])
      return new WP_Error('invalid_closure_date','End date precedes start date.',['status'=>400]);
    $name=explode('｜',$title)[0];
    $key=$name.'|'.$period['start'];
    if(isset($seen[$key]))return new WP_Error('duplicate_closure','Duplicate facility and start date.',['status'=>400]);
    $seen[$key]=true;
    $out[]=['_id'=>substr(sha1($park.'|'.$key),0,16),'source'=>'stop_'.$park,'title'=>$title,'url'=>$url,
      'pubdate'=>sanitize_text_field($item['pubdate']??''),'category'=>'休止','image_url'=>esc_url_raw($item['image_url']??'')];
  }
  return $out;
}

add_action('rest_api_init',function(){
  register_rest_route('tdr-today/v1','/closures',[
    'methods'=>'POST',
    'permission_callback'=>function($req){
      if(current_user_can('manage_options'))return true;
      $expected=(string)get_option('tdr_mon_ingest_token','');
      $provided=(string)$req->get_header('x-tdr-token');
      return $expected!=='' && $provided!=='' && hash_equals($expected,$provided);
    },
    'callback'=>function($req){
      $data=$req->get_json_params();$park=$data['park']??'';
      $items=tdrt_validate_closure_items($park,$data['items']??null);
      if(is_wp_error($items))return $items;
      $snapshot=['checked_at'=>time(),'items'=>$items];
      update_option('tdrt_closures_'.$park,$snapshot,false);
      $readback=get_option('tdrt_closures_'.$park,[]);
      if($readback!==$snapshot)return new WP_Error('closure_save_failed','Closure readback differs.',['status'=>500]);
      return ['ok'=>true,'park'=>$park,'count'=>count($items),'checked_at'=>$snapshot['checked_at']];
    },
  ]);
  register_rest_route('tdr-today/v1','/closure-data',[
    'methods'=>'GET','permission_callback'=>function(){return current_user_can('manage_options');},
    'callback'=>function($req){
      $park=(string)$req->get_param('park');
      if(!in_array($park,['tdl','tds'],true))return new WP_Error('invalid_park','Invalid park.',['status'=>400]);
      return get_option('tdrt_closures_'.$park,[]);
    },
  ]);
  register_rest_route('tdr-today/v1','/closure-health',[
    'methods'=>'GET','permission_callback'=>function(){return current_user_can('manage_options');},
    'callback'=>function(){return ['parks'=>array_map('tdrt_closure_health',['tdl','tds'])];},
  ]);
});

add_action('admin_notices',function(){
  if(!current_user_can('manage_options'))return;
  $bad=[];
  foreach(['tdl','tds'] as $park){
    $h=tdrt_closure_health($park);
    if(!$h['healthy'])$bad[]=strtoupper($park).'（最終成功: '.($h['checked_at_jst']?:'未取得').'）';
  }
  if($bad)echo '<div class="notice notice-error"><p>休止施設の自動更新を確認してください。24時間以上成功記録がありません: '.esc_html(implode('、',$bad)).'。公式ニュースに差分がなくても対応が必要です。</p></div>';
});

// Parse "...｜2026/5/22" / "...｜2026/3/24 - 2026/5/22" / "...｜2020/7/1 - 未定"
function tdrt_parse_stop_period($title){
  if(preg_match('#｜(\d{4})/(\d{1,2})/(\d{1,2})\s*-\s*(\d{4})/(\d{1,2})/(\d{1,2})#u',$title,$m)){
    return ['start'=>sprintf('%04d-%02d-%02d',(int)$m[1],(int)$m[2],(int)$m[3]),'end'=>sprintf('%04d-%02d-%02d',(int)$m[4],(int)$m[5],(int)$m[6])];
  }
  if(preg_match('#｜(\d{4})/(\d{1,2})/(\d{1,2})\s*-\s*未定#u',$title,$m)){
    return ['start'=>sprintf('%04d-%02d-%02d',(int)$m[1],(int)$m[2],(int)$m[3]),'end'=>null];
  }
  if(preg_match('#｜(\d{4})/(\d{1,2})/(\d{1,2})#u',$title,$m)){
    $d=sprintf('%04d-%02d-%02d',(int)$m[1],(int)$m[2],(int)$m[3]);
    return ['start'=>$d,'end'=>$d];
  }
  return null;
}
function tdrt_is_stopped_today($title){
  $p=tdrt_parse_stop_period($title);
  if(!$p)return false;
  $today=wp_date('Y-m-d');
  if($p['start']>$today)return false;
  if($p['end']!==null && $p['end']<$today)return false;
  return true;
}
function tdrt_categorize_stop($title){
  if(strpos($title,'【環境演出】')!==false)return 'show';
  if(strpos($title,'パレード')!==false)return 'parade';
  if(preg_match('/ハーモニー|ジュビリー|マジカルミュージック|エレクトリカルパレード|フィルハーマジック|ビリーヴ|パーティグラ|ダイヤモンド|スカイ・フル・オブ・カラーズ|Reach for the Stars|Stars/u',$title))return 'show';
  // ショップ判定 (v1.5.4: ペドラーズ・アウトポスト等を attraction と誤分類しないように)
  if(preg_match('/アウトポスト|フォトアーカイヴ|フォトスタジオ|・カンパニー\b|・ストア\b|・ショップ\b|・デパート|・トレーディング|エンポーリアム|マクダックス|フィガロ|ヴェネツィアン・グラス|ガリーニ|マーチャント・オブ・ヴェニス|ヴァレンティーナ|バルーンファルティーニ|ペドラーズ|スリーピー・ホエール|シェリフ・ウッディ|ジーニー・オアシス|プラザ・パビリオン・バナー|ウォッシュ・アンド・ファイル|パイレーツ・トレジャー|ヴィラ・ドナルド|ペコス・グッズ|キャプテン・フックス|フィッシャーマンズ・トレジャー|ガラスのうつわ|アバウト・タイム|ホームストア|タワー・オブ・テラー・メモラビリア|エンポリウム/u',$title))return 'shop';
  // レストラン判定
  if(preg_match('/レストラン|リストランテ|カフェ|・ダイニング|・キッチン|ラウンジ|・デリ|ベーカリー|・グリル|ジェラート|ザンビーニ|マゼランズ|セバスチャン|ヒューイ|ホライズンベイ|ヴォルケイニア|テディ・ルーズベルト|ノーチラスギャレー|S\.S\.コロンビア|ドックサイドダイナー|スウィートハート・カフェ|プラズマレイズ|キャンプ・ウッドチャック・キッチン|ザ・ガゼーボ|プラザレストラン|クリスタルパレス|ポリネシアンテラス|ハングリーベア|ペコス・ビル|アイスクリームコーン|ポップコーン・スタンド|チャイナボイジャー|ニューヨーク・デリ|・スタンド|グッドタイム・カフェ|ガリレオ|ミゲルズ|ザンドルフ|アラビアンコースト・スパイス|オアシス|スターライト・ジュース|プラザ・パビリオン・レストラン|トルバドール・タバン|ボイラールーム|キャプテンフックス・ギャレー/u',$title))return 'restaurant';
  return 'attraction';
}
function tdrt_filter_stopped_today($items){
  $out=['attraction'=>[],'show'=>[],'parade'=>[],'shop'=>[],'restaurant'=>[]];
  foreach($items as $s){
    $title=$s['title']??'';
    if(!tdrt_is_stopped_today($title))continue;
    $cat=tdrt_categorize_stop($title);
    $out[$cat][]=$s;
  }
  return $out;
}

// v1.5.4: 現在休止中のアトラクション名のセット（パス発券・待ち時間で参照）
function tdrt_get_stopped_attraction_names(){
  static $cache = null;
  if($cache !== null) return $cache;
  $stops_raw = tdrt_items_by_src(['stop_tdl','stop_tds']);
  $stops_today = tdrt_filter_stopped_today($stops_raw);
  $names = [];
  foreach(['attraction','show','parade'] as $cat){
    if(!isset($stops_today[$cat])) continue;
    foreach($stops_today[$cat] as $s){
      $title = $s['title'] ?? '';
      $name = explode('｜', $title)[0];
      $name = trim(tdrt_fix_name($name));
      if($name !== '') $names[$name] = true;
    }
  }
  $cache = $names;
  return $cache;
}

function tdrt_get_shows($park){
  $today_ymd = wp_date('Ymd');
  $stored = get_option('tdrt_shows_'.$today_ymd, null);
  if(!is_array($stored)) return [];
  return isset($stored[$park]) && is_array($stored[$park]) ? $stored[$park] : [];
}

// Today's official 1-day passport price (per park, per ticket category).
// Stored as: ['tdl'=>['adult'=>9900,'junior'=>8400,'child'=>5900], 'tds'=>[...]]
// Populated by fetcher.py via /wp-json/tdr-today/v1/ticket-price.
function tdrt_get_ticket_price($park){
  $today_ymd = wp_date('Ymd');
  $stored = get_option('tdrt_ticket_price_'.$today_ymd, null);
  if(!is_array($stored)) return null;
  return isset($stored[$park]) && is_array($stored[$park]) ? $stored[$park] : null;
}

// 日本の祝日リスト（2026-2027年）。年次でメンテナンス。
function tdrt_jp_holidays(){
  return [
    // 2026
    '2026-01-01','2026-01-12','2026-02-11','2026-02-23','2026-03-20',
    '2026-04-29','2026-05-03','2026-05-04','2026-05-05','2026-05-06',
    '2026-07-20','2026-08-11','2026-09-21','2026-09-22','2026-09-23',
    '2026-10-12','2026-11-03','2026-11-23',
    // 2027
    '2027-01-01','2027-01-11','2027-02-11','2027-02-23','2027-03-21',
    '2027-03-22','2027-04-29','2027-05-03','2027-05-04','2027-05-05',
    '2027-07-19','2027-08-11','2027-09-20','2027-09-23','2027-10-11',
    '2027-11-03','2027-11-23',
  ];
}
function tdrt_is_holiday($date_ymd){
  // $date_ymd: 'Y-m-d' format
  return in_array($date_ymd, tdrt_jp_holidays(), true);
}
function tdrt_is_weekday($date_ymd){
  // 平日 = Mon-Fri AND not 祝日
  $w = (int) wp_date('N', strtotime($date_ymd)); // 1=Mon ... 7=Sun
  if($w >= 6) return false;
  if(tdrt_is_holiday($date_ymd)) return false;
  return true;
}
function tdrt_is_offday($date_ymd){
  // 休日 = 土日 OR 祝日
  $w = (int) wp_date('N', strtotime($date_ymd));
  if($w >= 6) return true;
  if(tdrt_is_holiday($date_ymd)) return true;
  return false;
}

// 本日販売中の全チケット種別を返す。
// 戻り値: 配列。各要素は ['name','badge','prices'=>['adult','junior','child'],'is_fixed','condition','link','available']
// 価格変動チケットは prices['adult'] 等を null にして変動表示に切り替える。
// Only official, date-specific sale records may enter today's ticket list.
// No price-only fallback or hardcoded list of ticket names (including limited offers).
function tdrt_store_ticket_sales($park,$snapshot){
  $date=wp_date('Ymd');
  if(!in_array($park,['tdl','tds'],true) || !is_array($snapshot)
    || (string)($snapshot['date'] ?? '')!==$date || ($snapshot['park'] ?? '')!==$park
    || ($snapshot['source'] ?? '')!=='https://www.tokyodisneyresort.jp/ticket/index.html'
    || !is_array($snapshot['items'] ?? null) || empty($snapshot['seen'])) return false;
  $checked=(int)($snapshot['checked_at'] ?? 0);
  if($checked>time()+60 || $checked<time()-1800) return false;
  $rows=[];
  foreach($snapshot['items'] as $ticket){
    if(!is_array($ticket) || !in_array($ticket['sale_status'] ?? '',['販売中','残りわずか'],true)) return false;
    $name=sanitize_text_field($ticket['name'] ?? ''); $prices=[];
    foreach(['adult','junior','child'] as $cat){
      if(isset($ticket['prices'][$cat]) && is_numeric($ticket['prices'][$cat])){
        $amount=(int)$ticket['prices'][$cat];
        if($amount>0 && $amount<=100000) $prices[$cat]=$amount;
      }
    }
    if($name==='' || !$prices) return false;
    $condition='購入条件・入園時間は公式サイトをご確認ください';
    if(strpos($name,'アフター5')!==false) $condition='17時から入園（対象日・購入条件は公式をご確認ください）';
    elseif(strpos($name,'アフター3')!==false) $condition='15時から入園（対象日・購入条件は公式をご確認ください）';
    elseif(strpos($name,'障がい')!==false) $condition='対象の証明書をお持ちの方および同伴者1名';
    $rows[]=['name'=>$name,'badge'=>sanitize_text_field($ticket['badge'] ?? '当日券'),'prices'=>$prices,
      'is_fixed'=>false,'condition'=>$condition,'link'=>'https://www.tokyodisneyresort.jp/ticket/index.html?park='.$park,
      'available'=>true,'sale_status'=>$ticket['sale_status']];
  }
  $value=['date'=>$date,'checked_at'=>$checked,'items'=>$rows,'unknown'=>(int)($snapshot['unknown'] ?? 0)];
  // Use the existing host-compatible SQL persistence path, then invalidate object cache.
  $key='tdrt_ticket_sales_v2_'.$date.'_'.$park;
  global $wpdb;
  $old=$wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",$key));
  $old_value=$old ? maybe_unserialize($old) : null;
  if(is_array($old_value) && (int)($old_value['checked_at'] ?? 0)>$checked) return true;
  if($old!==null) $wpdb->update($wpdb->options,['option_value'=>serialize($value),'autoload'=>'no'],['option_name'=>$key]);
  else $wpdb->insert($wpdb->options,['option_name'=>$key,'option_value'=>serialize($value),'autoload'=>'no']);
  wp_cache_delete($key,'options');
  return get_option($key,null)===$value;
}

function tdrt_ticket_sales_snapshot(){
  $date=wp_date('Ymd'); $snapshot=['date'=>$date,'checked_at'=>0,'parks'=>[]];
  foreach(['tdl','tds'] as $park){
    $value=get_option('tdrt_ticket_sales_v2_'.$date.'_'.$park,null);
    // Keep today's verified observation, but never call an old observation current.
    $checked=is_array($value)?(int)($value['checked_at']??0):0;
    if(!is_array($value) || ($value['date'] ?? '')!==$date || !$checked || $checked>time()+60 || wp_date('Ymd',$checked)!==$date) continue;
    $value['fresh']=time()-$checked<=1800;
    $snapshot['parks'][$park]=$value;
  }
  return $snapshot;
}

function tdrt_render_ticket_sales($park){
  $pc = ($park==='tds') ? '#176b87' : '#a13e57';
  ob_start();
?>
<?php
// v1.5.5: 今日のページなので、公式モーダルから取得した「今日の確定価格」のみ表示。
// 当日の公式販売マークが確認できた券種のみ。旧価格データから推定しない。
$all_tickets = tdrt_get_all_ticket_types($park);
$ticket_today = array_values(array_filter($all_tickets, function($t){ return !empty($t['available']) && !empty($t['prices']); }));
$ticket_snapshot = tdrt_ticket_sales_snapshot();
$ticket_verified = isset($ticket_snapshot['parks'][$park]);
$ticket_fresh = $ticket_verified && !empty($ticket_snapshot['parks'][$park]['fresh']);
?><div data-tdrt-date="<?php echo esc_attr(wp_date('Ymd')); ?>">
<h3>💴 <?php echo $ticket_verified && !$ticket_fresh?'本日確認できたチケット':'今日販売のチケット'; ?></h3>
<?php if($ticket_verified && !$ticket_fresh): ?><p role="status" style="color:#6d4900;background:#fff7e0;padding:10px">更新が遅れています。以下は<?php echo esc_html(wp_date('H:i',$ticket_snapshot['parks'][$park]['checked_at'])); ?>の公式確認記録です。現在の販売状況は購入前に公式サイトでご確認ください。</p><?php endif; ?>
<?php if($ticket_today): ?>
<div style="font-size:13px;background:#fff;margin:8px 0">
<?php
$cat_labels = ['adult'=>'大人','junior'=>'中人','child'=>'小人'];
$cat_subs   = ['adult'=>'18才以上','junior'=>'中学・高校生','child'=>'4才〜小学生'];
foreach($ticket_today as $t):
  $prices = $t['prices'] ?? null;
?>
<div style="border:1px solid #e0e0e0;border-radius:8px;padding:10px 12px;margin-bottom:10px;background:#fafafa">
  <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:6px">
    <span style="font-size:10px;background:<?php echo $pc;?>;color:#fff;padding:2px 8px;border-radius:10px;font-weight:600"><?php echo esc_html($t['badge']);?></span>
    <strong style="font-size:14px;color:#222"><?php echo esc_html($t['name']);?></strong>
  </div>
  <p style="margin:4px 0;color:#255d48;font-weight:700"><?php echo $ticket_fresh?'':'最終確認時：'; echo esc_html($t['sale_status']);?></p>
  <table style="width:100%;border-collapse:collapse;font-size:12px;margin:6px 0">
    <thead><tr style="background:#f0f0f1">
      <?php foreach($cat_labels as $k=>$lbl): ?><th style="padding:6px;border:1px solid #e0e0e0;text-align:center"><?php echo esc_html($lbl);?><br><small style="color:#888;font-weight:400"><?php echo esc_html($cat_subs[$k]);?></small></th><?php endforeach; ?>
    </tr></thead>
    <tbody><tr>
      <?php foreach(['adult','junior','child'] as $k):
        if($prices && isset($prices[$k])): ?>
          <td style="padding:8px;border:1px solid #e0e0e0;text-align:center;font-weight:700;color:<?php echo $pc;?>;font-size:15px">¥<?php echo number_format((int)$prices[$k]);?></td>
        <?php else: ?>
          <td style="padding:8px;border:1px solid #e0e0e0;text-align:center;color:#aaa">—</td>
        <?php endif;
      endforeach; ?>
    </tr></tbody>
  </table>
  <div style="font-size:11px;color:#888;margin-top:4px">
    <?php echo esc_html($t['condition']);?>
    <?php if(!empty($t['link'])): ?> · <a href="<?php echo esc_url($t['link']);?>" target="_blank" rel="noopener" style="color:<?php echo $pc;?>">詳細 →</a><?php endif; ?>
  </div>
</div>
<?php endforeach; ?>
</div>
<?php elseif($ticket_verified): ?>
<p>この確認時点で販売中のチケットはありませんでした。</p>
<?php else: ?>
<p>現在の販売状況を確認できません。<a href="https://www.tokyodisneyresort.jp/ticket/index.html">公式のチケット販売状況</a>をご確認ください。</p>
<?php endif; ?>
<?php if($ticket_verified): ?>
<p style="font-size:12px;color:#555;margin:4px 0 12px">公式確認：<?php echo esc_html(wp_date('n/j H:i',$ticket_snapshot['parks'][$park]['checked_at']));?>時点。販売中・残りわずかの券種のみ掲載しています。売り切れ・販売対象外の券種は表示しません。販売状況は変動するため、購入直前に公式サイトをご確認ください。</p>
<?php if(!empty($ticket_snapshot['parks'][$park]['unknown'])): ?><p style="font-size:12px;color:#555">一部券種の販売状況は確認できていません。詳細は公式サイトをご確認ください。</p><?php endif; ?>
<?php endif; ?>
</div>

<?php
  return ob_get_clean();
}

function tdrt_get_all_ticket_types($park){
  $snapshot=tdrt_ticket_sales_snapshot();
  if(!is_array($snapshot) || ($snapshot['date'] ?? '')!==wp_date('Ymd')) return [];
  return $snapshot['parks'][$park]['items'] ?? [];
}

// Timestamped sources only. Never substitute unversioned DWR last/previous values.
function tdrt_fresh_waits($payload,$now){
  if(!is_array($payload) || !in_array($payload['source']??'', ['queue-times','official-fetcher'],true))return [];
  $ts=(int)($payload['ts']??0);
  if($ts<$now-1200 || $ts>$now+60 || !is_array($payload['rows']??null))return [];
  $out=[];
  foreach($payload['rows'] as $r){
    $at=(int)($r['observed_ts']??$ts);
    if($at<$now-1200 || $at>$now+60)continue;
    $r['observed_ts']=$at;
    $w=$r['wait_min']??null;
    if(($r['status']??'')!=='operating' || !is_numeric($w) || $w<0 || $w>480)$r['wait_min']=null;
    $out[]=$r;
  }
  return $out;
}
function tdrt_wait_data_raw($park){
  return tdrt_fresh_waits(get_option('tdrt_waits_'.$park,null),time());
}
function tdrt_wait_data($park){
  $rows = tdrt_wait_data_raw($park);
  if(!is_array($rows)) return [];
  foreach($rows as &$r){
    if(isset($r['name'])) $r['name'] = tdrt_fix_name($r['name']);
    $r['observed_ts']=(int)($r['observed_ts']??0);
  }
  unset($r);
  return $rows;
}

function tdrt_top_waits($park,$lim=10){
  if(!tdrt_is_open($park))return [];
  $r=tdrt_wait_data($park);if(!is_array($r))return [];
  usort($r,function($a,$b){
    $aw=isset($a['wait_min'])?(int)$a['wait_min']:-1;$bw=isset($b['wait_min'])?(int)$b['wait_min']:-1;
    return $aw===$bw?0:($aw<$bw?1:-1);
  });
  return array_slice($r,0,$lim);
}

// キャラクターグリーティング: fetcher.py が `tdrt_greet_tdl` / `tdrt_greet_tds` に書き込む
function tdrt_greet_data($park){
  $at=(int)get_option('tdrt_greet_observed_'.$park,0);
  if(!$at || $at<time()-1200 || $at>time()+60 || wp_date('Y-m-d',$at)!==wp_date('Y-m-d'))return [];
  $rows = get_option('tdrt_greet_'.$park, []);
  if(!is_array($rows)) return [];
  foreach($rows as &$r){
    if(isset($r['name'])) $r['name'] = tdrt_fix_name($r['name']);
    $r['observed_ts']=(int)($r['observed_ts']??$at);
  }
  unset($r);
  return $rows;
}
function tdrt_top_greets($park, $lim=10){
  if(!tdrt_is_open($park))return [];
  $r = tdrt_greet_data($park);
  if(!is_array($r))return [];
  usort($r, function($a,$b){
    $aw=isset($a['wait_min'])?(int)$a['wait_min']:-1;$bw=isset($b['wait_min'])?(int)$b['wait_min']:-1;
    return $aw===$bw?0:($aw<$bw?1:-1);
  });
  return array_slice($r, 0, $lim);
}

function tdrt_grade($park){
  if(!tdrt_is_open($park))return null;
  $r=tdrt_wait_data($park);if(!is_array($r)||empty($r))return null;
  $t=[];foreach($r as $x)if(isset($x['wait_min'])&&$x['wait_min']!=='')$t[]=(int)$x['wait_min'];
  if(empty($t))return null;
  sort($t);$top=array_slice($t,-10);$avg=array_sum($top)/count($top);
  if($avg>=100)return ['grade'=>'A','label'=>'激混み','avg'=>round($avg)];
  if($avg>=70)return ['grade'=>'B','label'=>'混雑','avg'=>round($avg)];
  if($avg>=45)return ['grade'=>'C','label'=>'やや混雑','avg'=>round($avg)];
  if($avg>=25)return ['grade'=>'D','label'=>'空いてる','avg'=>round($avg)];
  return ['grade'=>'E','label'=>'快適','avg'=>round($avg)];
}

// 天気: ウェザーニュース浦安(12227)を第一ソース、失敗時Open-Meteoフォールバック。
// 返り値の正規形: ['name','tmax','tmin','pop','emoji','source']
function tdrt_weather(){
  $c=get_transient('tdrt_w2');if($c!==false)return $c;
  $w=tdrt_weather_wn();
  if(!$w)$w=tdrt_weather_om();
  if($w)set_transient('tdrt_w2',$w,HOUR_IN_SECONDS);
  return $w;
}
// ウェザーニュース浦安の「今日の天気」ブロックをパース
function tdrt_weather_wn(){
  $u='https://weathernews.jp/onebox/tenki/chiba/12227/';
  $r=wp_remote_get($u,['timeout'=>8,'headers'=>['User-Agent'=>'Mozilla/5.0']]);
  if(is_wp_error($r))return null;
  $h=wp_remote_retrieve_body($r);if(!$h)return null;
  $i=mb_strpos($h,'今日の天気');$j=mb_strpos($h,'明日の天気');
  if($i===false||$j===false||$j<=$i)return null;
  $blk=mb_substr($h,$i,$j-$i);
  $txt=preg_replace('/\\s+/u',' ',preg_replace('/<[^>]+>/u',' ',$blk));
  if(!preg_match('/\\)\\s*([ぁ-んァ-ヶ一-龠々ー]+(?:時々|のち|一時)?[ぁ-んァ-ヶ一-龠々ー]*)/u',$txt,$mn))return null;
  if(!preg_match('/最高\\s*(\\d+)\\s*℃/u',$txt,$mx))return null;
  if(!preg_match('/最低\\s*(\\d+)\\s*℃/u',$txt,$mi))return null;
  preg_match_all('/(\\d+)\\s*%/u',$txt,$pm);
  $pop=0;if(!empty($pm[1])){foreach($pm[1] as $p){$pop=max($pop,(int)$p);}}
  return ['name'=>$mn[1],'tmax'=>(int)$mx[1],'tmin'=>(int)$mi[1],'pop'=>$pop,'emoji'=>tdrt_wn_emoji($mn[1]),'source'=>'wn'];
}
function tdrt_wn_emoji($n){
  if(mb_strpos($n,'雷')!==false)return'⛈';
  if(mb_strpos($n,'雪')!==false)return'❄️';
  if(mb_strpos($n,'雨')!==false)return mb_strpos($n,'晴')!==false?'🌦':'🌧';
  if(mb_strpos($n,'霧')!==false)return'🌫';
  if($n==='晴れ'||$n==='快晴')return'☀️';
  $sun=mb_strpos($n,'晴')!==false;$cloud=(mb_strpos($n,'くもり')!==false||mb_strpos($n,'曇')!==false);
  if($sun&&$cloud)return'🌤';
  if($cloud)return'☁️';
  if($sun)return'🌤';
  return'☁️';
}
// Open-Meteo フォールバック（正規形にマップ）
function tdrt_weather_om(){
  $u='https://api.open-meteo.com/v1/forecast?latitude=35.6329&longitude=139.8804&daily=weathercode,temperature_2m_max,temperature_2m_min,precipitation_probability_max&timezone=Asia%2FTokyo&forecast_days=1';
  $r=wp_remote_get($u,['timeout'=>8]);if(is_wp_error($r))return null;
  $d=json_decode(wp_remote_retrieve_body($r),true);
  if(!is_array($d)||!isset($d['daily']['weathercode'][0]))return null;
  $wc=(int)$d['daily']['weathercode'][0];
  return ['name'=>tdrt_wl($wc),'tmax'=>round($d['daily']['temperature_2m_max'][0]),'tmin'=>round($d['daily']['temperature_2m_min'][0]),'pop'=>(int)$d['daily']['precipitation_probability_max'][0],'emoji'=>tdrt_we($wc),'source'=>'om'];
}
function tdrt_we($c){if($c===0)return'☀️';if($c<=3)return'🌤';if($c===45||$c===48)return'🌫';if($c<=67)return'🌧';if($c<=77)return'❄️';if($c<=82)return'🌧';if($c>=95)return'⛈';return'☁️';}
function tdrt_wl($c){if($c===0)return'快晴';if($c<=3)return'曇り';if($c<=48)return'霧';if($c<=67)return'雨';if($c<=77)return'雪';if($c<=82)return'にわか雨';return'雷雨';}

// "8:15" + 15 = "8:30"
function tdrt_add_minutes($time_str, $mins){
  if(!preg_match('/^(\d{1,2}):(\d{2})$/', $time_str, $m)) return $time_str;
  $total = (int)$m[1] * 60 + (int)$m[2] + (int)$mins;
  $hh = floor($total / 60);
  $mm = $total % 60;
  return sprintf('%d:%02d', $hh, $mm);
}

function tdrt_hub($a){
  $a=shortcode_atts(['park'=>'tdl'],$a,'tdr_today_hub');
  $park=strtolower($a['park'])==='tds'?'tds':'tdl';
  $pl=$park==='tdl'?'東京ディズニーランド':'東京ディズニーシー';
  $pc=$park==='tdl'?'#1d4f91':'#0a8aa6';
  $h=tdrt_hours($park);$he=tdrt_fetch_happy_entry();$he_time=$he[$park]??null;$open_eff=$he_time?tdrt_add_minutes($he_time,15):($h['open']??null);$close_eff=$h['close']??null;$w=tdrt_weather();$g=tdrt_grade($park);
  $tw=tdrt_top_waits($park);
  $tg=tdrt_top_greets($park);
  $is_open=tdrt_is_open($park);
  $stops_raw=tdrt_items_by_src($park==='tdl'?'stop_tdl':'stop_tds',100);
  $stops_today=tdrt_filter_stopped_today($stops_raw);
  $stops_total=count($stops_today['attraction'])+count($stops_today['show'])+count($stops_today['parade'])+count($stops_today['shop'] ?? [])+count($stops_today['restaurant'] ?? []);
  $today=wp_date('Y年n月j日 (D)');
  ob_start();?>
<style>.tdt{font-family:-apple-system,sans-serif;max-width:100%}
.tdt .t{display:flex;gap:4px;margin-bottom:12px}.tdt .t a{flex:1;text-align:center;padding:12px;border-radius:8px 8px 0 0;background:#f0f0f1;color:#666;text-decoration:none;font-weight:600;font-size:14px}
.tdt .ta{background:<?php echo $pc;?>;color:#fff}
.tdt .d{text-align:center;font-size:13px;color:#666;margin:8px 0}
.tdt .g{display:grid;grid-template-columns:repeat(2,1fr);gap:10px;margin:12px 0}@media(min-width:600px){.tdt .g{grid-template-columns:repeat(4,1fr)}}
.tdt .c{background:#fff;border:1px solid #e0e0e0;border-radius:10px;padding:14px 12px;text-align:center}
.tdt .cl{font-size:11px;color:#888;margin-bottom:6px}.tdt .cv{font-size:20px;font-weight:700;color:<?php echo $pc;?>}.tdt .cs{font-size:11px;color:#888;margin-top:4px}
.tdt .gA{color:#d0334d!important}.tdt .gB{color:#e66524!important}.tdt .gC{color:#d9a440!important}.tdt .gD{color:#59a85d!important}.tdt .gE{color:#2980b9!important}
.tdt h3{font-size:16px;margin:24px 0 10px;padding-left:10px;border-left:4px solid <?php echo $pc;?>}
.tdt .l{list-style:none;padding:0;margin:0;border-top:1px solid #eee}.tdt .l li{display:flex;padding:10px 0;border-bottom:1px solid #eee;align-items:center;gap:10px}
.tdt .r{font-weight:700;min-width:28px;color:#999;font-size:13px}.tdt .rt{color:#e6a420}
.tdt .n{flex:1;font-size:13px;line-height:1.4}.tdt .w{font-weight:700;font-size:16px;min-width:60px;text-align:right;color:<?php echo $pc;?>}.tdt .w small{font-size:11px;font-weight:400;color:#888}
.tdt .ws{color:#999;font-size:12px;font-weight:400}
.tdt .src{display:inline-block;padding:1px 6px;background:#f0f0f1;border-radius:3px;font-size:10px;color:#666;margin-right:6px}
.tdt .cta{display:flex;gap:8px;flex-wrap:wrap;margin:16px 0}.tdt .cta a{flex:1;min-width:140px;padding:12px;text-align:center;background:#f5f5f5;border-radius:8px;text-decoration:none;color:#333;font-size:13px;font-weight:600;border:1px solid #ddd}.tdt .cta .cp{background:<?php echo $pc;?>;color:#fff;border-color:<?php echo $pc;?>}</style>
<div class="tdt">
<div class="t">
<a class="<?php echo $park==='tdl'?'ta':'';?>" href="<?php echo home_url('/today-tdl/');?>">🏰 ランド</a>
<a class="<?php echo $park==='tds'?'ta':'';?>" href="<?php echo home_url('/today-tds/');?>">⚓ シー</a>
</div>
<div class="d"><?php echo esc_html($today);?> · <?php echo esc_html($pl);?><?php if(!$is_open):?> · <span style="color:#999">🌙 営業時間外</span><?php endif;?></div>
<div class="g">
<div class="c"><div class="cl">運営時間</div><?php if($open_eff):?><div class="cv" style="font-size:16px"><?php echo esc_html($open_eff);?>〜<?php echo esc_html($close_eff??'');?></div><?php else:?><div class="cv">—</div><?php endif;?><?php if($he_time):?><div class="cs" style="margin-top:6px">🌅 <strong>HE <?php echo esc_html($he_time);?></strong></div><?php endif;?></div>
<div class="c"><div class="cl">天気</div><?php if($w&&isset($w['name'])):?><div class="cv"><?php echo $w['emoji'];?> <?php echo esc_html($w['name']);?></div><div class="cs"><?php echo esc_html("{$w['tmin']}℃/{$w['tmax']}℃　降水{$w['pop']}%");?></div><?php else:?><div class="cv">—</div><?php endif;?></div>
<div class="c"><div class="cl">混雑度</div><?php if($g):?><div class="cv g<?php echo $g['grade'];?>"><?php echo $g['grade'];?></div><div class="cs"><?php echo esc_html($g['label']);?> 平均<?php echo $g['avg'];?>分</div><?php elseif(!$is_open):?><div class="cv" style="font-size:14px;color:#999">🌙</div><div class="cs">営業時間外</div><?php else:?><div class="cv">—</div><?php endif;?></div>
<div class="c"><div class="cl">休止施設</div><div class="cv"><?php echo $stops_total;?>件</div><div class="cs">下のリスト参照</div></div>
</div>
<h3>🔥 待ち時間 TOP 10</h3>
<?php if($tw):?><ul class="l"><?php foreach($tw as $i=>$r):$nm=$r['name']??'';$wm=isset($r['wait_min'])?(int)$r['wait_min']:-1;$st=$r['status']??'';?>
<li><div class="r <?php echo $i<3?'rt':'';?>"><?php echo $i+1;?></div><div class="n"><?php echo esc_html($nm);?></div>
<?php if($st&&$st!=='operating'):?><div class="w ws">休止</div><?php elseif($wm>=0):?><div class="w"><?php echo $wm;?><small>分</small></div><?php else:?><div class="w ws">—</div><?php endif;?></li>
<?php endforeach;?></ul>
<p style="text-align:right;margin-top:8px"><a href="<?php echo home_url('/'.$park.'-wait-ranking/');?>" style="font-size:12px">全アトラクションを見る →</a></p>
<?php elseif(!$is_open):?><p style="color:#888;text-align:center;padding:16px;background:#f8f8f8;border-radius:8px">🌙 現在パークは営業時間外です。<br>明日の開園後にリアルタイム待ち時間を表示します。</p>
<?php else:?><p style="color:#aaa;text-align:center">データ取得中…</p><?php endif;?>
<?php if($tg): ?>
<h3>🤝 キャラクターグリーティング 待ち時間</h3>
<ul class="l">
<?php foreach($tg as $i=>$r): $nm=$r['name']??''; $loc=$r['location']??''; $wm=isset($r['wait_min'])?(int)$r['wait_min']:-1; $st=$r['status']??''; ?>
<li>
  <div class="r <?php echo $i<3?'rt':'';?>"><?php echo $i+1;?></div>
  <div class="n"><?php echo esc_html($nm);?><?php if($loc):?><br><span style="font-size:11px;color:#888"><?php echo esc_html($loc);?></span><?php endif;?></div>
  <?php if($st&&$st!=='operating'):?><div class="w ws">中止</div>
  <?php elseif($wm>=0):?><div class="w"><?php echo $wm;?><small>分</small></div>
  <?php else:?><div class="w ws">—</div><?php endif;?>
</li>
<?php endforeach; ?>
</ul>
<p style="text-align:right;margin-top:8px"><a href="<?php echo home_url('/today-'.$park.'/#greet-history');?>" style="font-size:12px">過去の待ち時間推移を見る ↓</a></p>
<?php elseif($is_open): ?>
<h3>🤝 キャラクターグリーティング 待ち時間</h3>
<p style="color:#aaa;text-align:center;padding:12px;background:#f8f8f8;border-radius:8px;font-size:12px">現在のグリーティング待ち時間は確認できていません。公式アプリでご確認ください。</p>
<?php endif; ?>

<h3>🚫 今日の休止施設 (<?php echo $stops_total;?>件)</h3>
<?php $closure_health=tdrt_closure_health($park); ?>
<p class="tdrt-closure-check" style="font-size:0.85em;color:#46556b;">公式休止情報の最終確認: <?php echo esc_html($closure_health['checked_at_jst']?:'未取得'); ?><?php if(!$closure_health['healthy']): ?>（更新確認が必要です。<a href="https://www.tokyodisneyresort.jp/<?php echo esc_attr($park); ?>/monthly/stop.html">公式の休止一覧</a>をご確認ください）<?php endif; ?></p>
<?php
// TDL は ショー＋パレードを「ショー・パレード」として合体表示。TDS は ショーのみ。
$shops_merged = array_merge($stops_today['shop'] ?? [], $stops_today['restaurant'] ?? []);
if($park==='tdl'){
  $sp_merged = array_merge($stops_today['show'], $stops_today['parade']);
  $cats = [
    'attraction'=>['🎢','アトラクション',$stops_today['attraction']],
    'show_parade'=>['🎭','ショー・パレード',$sp_merged],
    'shop_restaurant'=>['🛍️','ショップ・レストラン',$shops_merged],
  ];
} else {
  $cats = [
    'attraction'=>['🎢','アトラクション',$stops_today['attraction']],
    'show'=>['🎭','ショー',$stops_today['show']],
    'shop_restaurant'=>['🛍️','ショップ・レストラン',$shops_merged],
  ];
}
foreach($cats as $key=>$lbl): if(empty($lbl[2]))continue;?>
<div style="margin-bottom:14px"><div style="font-size:13px;font-weight:600;color:#666;margin-bottom:4px"><?php echo $lbl[0].' '.$lbl[1];?> (<?php echo count($lbl[2]);?>)</div>
<ul class="l"><?php foreach($lbl[2] as $s):?><li><div class="n"><?php echo esc_html($s['title']??'');?></div></li><?php endforeach;?></ul></div>
<?php endforeach; if($stops_total===0):?><p style="color:#aaa;text-align:center">今日休止中の施設はありません</p><?php endif;?>

<?php $shows=tdrt_get_shows($park); if($shows): ?>
<h3>🎭 <?php echo $park==='tdl'?'ショー・パレード':'ショー';?> スケジュール</h3>
<ul class="l">
<?php
usort($shows, function($a,$b){
  $cat_order=function($c){return $c==='parade'?0:1;};
  $oa=$cat_order($a['category']??'show'); $ob=$cat_order($b['category']??'show');
  if($oa!==$ob) return $oa-$ob;
  $ta=$a['times'][0]??'99:99'; $tb=$b['times'][0]??'99:99';
  return strcmp($ta,$tb);
});
foreach($shows as $sh):
  $icon = ($sh['category']??'show')==='parade' ? '🎉' : '🎭';
  $times = $sh['times']??[];
?>
<li>
  <div style="font-size:14px;line-height:1.3;flex:1">
    <span style="margin-right:6px"><?php echo $icon;?></span><?php echo esc_html($sh['name']??'');?>
  </div>
  <div style="font-weight:700;font-size:13px;color:<?php echo $pc;?>;text-align:right;min-width:100px">
    <?php echo esc_html(implode(' / ', $times));?>
  </div>
</li>
<?php endforeach; ?>
</ul>
<?php endif; ?>

<?php $ticket_live_id='tdrt-ticket-sales-'.$park; ?>
<section id="<?php echo esc_attr($ticket_live_id); ?>" data-ticket-sales>
<?php echo tdrt_render_ticket_sales($park); ?>
</section>
<script>
(function(){
  const box=document.getElementById(<?php echo wp_json_encode($ticket_live_id); ?>);
  if(!box) return;
  const endpoint=<?php echo wp_json_encode(rest_url('tdr-today/v1/ticket-sales').'?park='.$park); ?>;
  let busy=false;
  async function refresh(){
    if(busy || document.hidden) return;
    busy=true;
    try{
      const response=await fetch(endpoint+'&_cb='+Date.now(),{cache:'no-store',credentials:'same-origin'});
      if(!response.ok) throw new Error('ticket availability unavailable');
      const data=await response.json();
      if(typeof data.html!=='string') throw new Error('ticket response invalid');
      box.innerHTML=data.html;
    }catch(error){
      const day=new Intl.DateTimeFormat('sv-SE',{timeZone:'Asia/Tokyo',year:'numeric',month:'2-digit',day:'2-digit'}).format().replace(/-/g,'');
      if(box.querySelector('[data-tdrt-date]')?.dataset.tdrtDate!==day)box.innerHTML='<h3>💴 今日販売のチケット</h3>';
      if(!box.querySelector('[data-refresh-error]'))box.insertAdjacentHTML('beforeend','<p data-refresh-error role="status">最新情報への更新に失敗しました。表示中の確認時刻を参照し、現在の販売状況は公式サイトでご確認ください。</p>');
    }finally{ busy=false; }
  }
  refresh();
  setInterval(refresh,60000);
  document.addEventListener('visibilitychange',function(){if(!document.hidden)refresh();});
})();
</script>

<?php
// 🎫 DPA/プライオリティパス発券状況 を hub 内に統合表示。
// 営業時間外は has_dpa/has_pp フラグが「対応アトラクションかどうか」の static
// マーカーとして残ってしまい、本来発券していない時間帯でも「発券中」と誤表示
// するため、open かつ event_time が今日のものだけ表示する。
echo do_shortcode('[tdr_pass_today park="'.$park.'"]');
?>

<div class="cta">
<a class="cp" href="<?php echo home_url('/calendar/');?>">📅 混雑予想</a>
<a href="<?php echo home_url('/'.$park.'-wait-ranking/');?>">⏱ 待ち時間</a>
<a href="<?php echo home_url('/dpa_soldout_time/');?>">🎫 DPA</a>
</div>
</div>
<?php return ob_get_clean();
}
add_shortcode('tdr_today_hub','tdrt_hub');

// History uses dated park hours when available; never show the default after 21:00.
function tdrt_history_close($park,$day){
  $saved=get_option('tdrt_park_hours_'.str_replace('-','',$day),[]);
  $h=$saved[$park]??null;
  if(!$h && $day===wp_date('Y-m-d'))$h=tdrt_hours($park);
  $close=is_array($h)?tdrt_t2m($h['close']??''):-1;
  return $close>0?$close:1260;
}
// Status is inferred only from successful observations, never from a failed request.
function tdrt_stop_key($name){
  return preg_replace('/[\s　・：:「」『』!！?？®™]/u','',tdrt_fix_name($name));
}
function tdrt_history_stops($park,$day){
  static $cache=[];$key=$park.'|'.$day;
  if(isset($cache[$key]))return $cache[$key];
  $names=[];
  foreach(tdrt_items_by_src('stop_'.$park) as $item){
    $title=$item['title']??'';$period=tdrt_parse_stop_period($title);
    if(!$period || $period['start']>$day || ($period['end']!==null && $period['end']<$day))continue;
    $name=tdrt_stop_key(trim(explode('｜',$title)[0]));
    if($name!=='')$names[$name]=true;
  }
  return $cache[$key]=$names;
}
function tdrt_is_history_stopped($name,$stops){
  $key=tdrt_stop_key($name);
  if(isset($stops[$key]))return true;
  // A facility-wide closure also applies to its named character variants.
  foreach($stops as $base=>$unused){
    if(strpos($key,$base.'（')===0 || strpos($key,$base.'(')===0)return true;
  }
  return false;
}
function tdrt_history_state($name,$wait,$status,$at,$previous,$close,$stops,$max_gap=1200){
  if(tdrt_is_history_stopped($name,$stops) || $status==='all_day_closed')return 'all_day_closed';
  if($status==='operating' && is_numeric($wait) && $wait>=0 && $wait<=480)return 'operating';
  if(!in_array($status,['closed','suspended','ended'],true))return 'unknown';
  if($status==='ended')return 'ended';
  $pt=(int)($previous['observed_ts']??0);
  if($pt && $at>=$pt && $at-$pt<=$max_gap && wp_date('Y-m-d',$at)===wp_date('Y-m-d',$pt)){
    $ps=$previous['history_status']??($previous['status']??'');
    if($ps==='ended')return 'ended';
    $pw=$previous['wait_min']??null;
    // Strictly later than closing, with the actual observation time (not the slot label).
    $minute=(int)wp_date('H',$at)*60+(int)wp_date('i',$at)+(int)wp_date('s',$at)/60;
    if($at>$pt && $ps==='operating' && is_numeric($pw) && $pw>=0 && $pw<=480 && $minute+$pw>$close)return 'ended';
  }
  return 'suspended';
}
function tdrt_history_state_label($status){
  return ['all_day_closed'=>'終日運営中止','ended'=>'案内終了','suspended'=>'一時運営中止'][$status]??'未取得';
}

function tdrt_with_previous($rows,$previous,$park=null){
  $map=[];foreach((array)$previous as $r){if(isset($r['name']))$map[$r['name']]=$r;}
  foreach($rows as &$r){
    $p=$map[$r['name']]??null;
    if($park!==null){
      $at=(int)($r['observed_ts']??0);$day=wp_date('Y-m-d',$at);
      $r['history_status']=tdrt_history_state($r['name'],$r['wait_min']??null,$r['status']??'',$at,$p,tdrt_history_close($park,$day),tdrt_history_stops($park,$day));
    }
    if(!$p)continue;
    $at=(int)($r['observed_ts']??0);$pt=(int)($p['observed_ts']??0);
    if($at===$pt){foreach(['previous_wait_min','previous_observed_ts'] as $key){if(isset($p[$key]))$r[$key]=$p[$key];}}
    elseif($at>$pt && $at-$pt<=1200 && wp_date('Y-m-d',$at)===wp_date('Y-m-d',$pt) && ($p['status']??'')==='operating' && is_numeric($p['wait_min']??null)){
      $r['previous_wait_min']=(int)$p['wait_min'];$r['previous_observed_ts']=$pt;
    }
  }unset($r);return $rows;
}
function tdrt_wait_trend($r,$now){
  $at=(int)($r['observed_ts']??0);$pt=(int)($r['previous_observed_ts']??0);
  if(($r['status']??'')!=='operating'||!is_numeric($r['wait_min']??null)||!is_numeric($r['previous_wait_min']??null)||!$pt||$at<=$pt||$at-$pt>1200||$now-$at>1200||$at>$now||wp_date('Y-m-d',$at)!==wp_date('Y-m-d',$pt))return 0;
  $delta=(int)$r['wait_min']-(int)$r['previous_wait_min'];return abs($delta)>=20?$delta:0;
}
// Collect the official public greeting feed within the existing five-minute job.
function tdrt_fetch_official_greetings($park){
  $res=wp_remote_get('https://www.tokyodisneyresort.jp/_/realtime/'.$park.'_greeting.json?'.time(),['timeout'=>8,'headers'=>['Accept'=>'application/json'],'user-agent'=>'TDR-Today/1.5.10']);
  if(is_wp_error($res))return ['ok'=>false,'error'=>$res->get_error_message()];
  if(wp_remote_retrieve_response_code($res)!==200)return ['ok'=>false,'http'=>wp_remote_retrieve_response_code($res)];
  $data=json_decode(wp_remote_retrieve_body($res),true);if(!is_array($data))return ['ok'=>false,'error'=>'invalid_json'];
  $rows=[];$today=wp_date('Ymd');$now=time();
  foreach($data as $area){if(!is_array($area))continue;foreach(($area['Facility']??[]) as $f){
    $g=$f['greeting']??[];$name=$g['FacilityName']??'';if(!$name)continue;
    $valid_day=false;$operating=false;
    foreach(($g['operatinghours']??[]) as $hours){
      if(($hours['OperatingHoursFromDate']??'')!==$today)continue;
      $valid_day=true;
      if(($hours['OperatingStatusCD']??'')==='001')$operating=true;
    }
    if(!$valid_day)continue;
    $w=$g['StandbyTime']??null;
    $w=$operating && is_numeric($w) && $w>=0 && $w<=480?(int)$w:null;
    // observed_ts is collection time: the endpoint confirms this day's current state.
    $rows[]=['name'=>tdrt_fix_name($name),'location'=>$area['AreaJName']??'','status'=>$operating?'operating':'closed','wait_min'=>$w,'observed_ts'=>$now];
  }}
  if(!$rows)return ['ok'=>false,'error'=>'no_current_day_rows'];
  $rows=tdrt_with_previous($rows,get_option('tdrt_greet_'.$park,[]),$park);
  update_option('tdrt_greet_'.$park,$rows,false);update_option('tdrt_greet_observed_'.$park,$now,false);
  return ['ok'=>true,'count'=>count($rows),'at'=>wp_date('Y-m-d H:i:s',$now)];
}

function tdrt_heatmap($a){
  global $wpdb;
  $a=shortcode_atts(['park'=>'tdl','type'=>'attr'],$a,'tdr_today_heatmap');
  $base=strtolower($a['park'])==='tds'?'tds':'tdl';
  $greet=$a['type']==='greet'; $park=$greet?$base.'_g':$base;
  $today=wp_date('Y-m-d');$tbl=tdrt_table();
  $requested=isset($_GET['tw_date']) && is_string($_GET['tw_date'])?sanitize_text_field(wp_unslash($_GET['tw_date'])):$today;
  $day=preg_match('/^\d{4}-\d{2}-\d{2}$/',$requested) && $requested<=$today && $requested>=substr(tdrt_verified_from(),0,10)?$requested:$today;
  $is_today=$day===$today;$close=tdrt_history_close($base,$day);
  $days=$wpdb->get_col($wpdb->prepare("SELECT DISTINCT DATE(recorded_at) FROM {$tbl} WHERE park IN (%s,%s) AND recorded_at>=%s AND wait_min IS NOT NULL ORDER BY recorded_at DESC LIMIT 90",$base,$base.'_g',tdrt_verified_from()));
  $days=array_values(array_unique(array_merge([$today],$days,[$day])));rsort($days);
  $source_rows=$greet?tdrt_greet_data($base):tdrt_wait_data_raw($base);$trends=[];
  if($is_today && tdrt_is_open($base)){foreach($source_rows as $r){$trends[tdrt_fix_name($r['name']??'')]=tdrt_wait_trend($r,time());}}

  $from=max($day.' 00:00:00',tdrt_verified_from());
  $rows=$wpdb->get_results($wpdb->prepare("SELECT attr_name,wait_min,status,slot_key,recorded_at FROM {$tbl} WHERE park=%s AND recorded_at>=%s AND recorded_at<=%s ORDER BY recorded_at ASC,id ASC",$park,$from,$is_today?current_time('mysql'):$day.' 23:59:59'));
  // Merge the fresh provider snapshot so this view agrees with the current TOP 10.
  if($is_today){
    foreach($source_rows as $r){
      $at=(int)($r['observed_ts']??0);
      if(!$at || wp_date('Y-m-d',$at)!==$today || $at>time())continue;
      $dt=wp_date('Y-m-d H:i:s',$at);
      if($dt<$from)continue;
      $rows[]=(object)['attr_name'=>$r['name']??'', 'wait_min'=>$r['wait_min']??null,'status'=>$r['history_status']??($r['status']??''), 'recorded_at'=>$dt, 'slot_key'=>wp_date('YmdH',$at).((int)wp_date('i',$at)<30?'00':'30')];
    }
  }
  // Sort the merged current snapshot with history before comparing consecutive observations.
  usort($rows,function($a,$b){return strcmp($a->recorded_at,$b->recorded_at);});
  $m=[];$slots=[];$latest='';$previous_cells=[];$stops=tdrt_history_stops($base,$day);
  foreach($rows as $r){
    $name=tdrt_fix_name($r->attr_name);$slot=(string)$r->slot_key;
    if(substr($slot,0,8)!==str_replace('-','',$day))continue;
    $minutes=(int)substr($slot,8,2)*60+(int)substr($slot,10,2);
    if($minutes>=$close || tdrt_t2m(substr($r->recorded_at,11,5))>=$close)continue;
    $w=$r->wait_min===null?null:(int)$r->wait_min;
    $dt=date_create_from_format('Y-m-d H:i:s',$r->recorded_at,wp_timezone());
    $at=$dt?$dt->getTimestamp():0;
    // Older history has one observation per half hour; never bridge missing slots.
    $status=tdrt_history_state($name,$w,$r->status,$at,$previous_cells[$name]??null,$close,$stops,3600);
    if($status!=='operating' || $w<0 || $w>480)$w=null;
    $previous_cells[$name]=['observed_ts'=>$at,'wait_min'=>$w,'status'=>$status];
    if(isset($m[$name][$slot]) && $m[$name][$slot]['at']>$r->recorded_at)continue;
    $m[$name][$slot]=['w'=>$w,'s'=>$status,'at'=>$r->recorded_at];
    $slots[$slot]=1;$latest=max($latest,$r->recorded_at);
  }
  ksort($slots);$keys=array_keys($slots);
  // Empty pre-opening snapshots are not visitor wait-time history.
  $first_numeric=null;
  foreach($keys as $k){foreach($m as $cells){if(isset($cells[$k]) && $cells[$k]['w']!==null){$first_numeric=$k;break 2;}}}
  $keys=$first_numeric===null?[]:array_values(array_filter($keys,function($k)use($first_numeric){return $k>=$first_numeric;}));
  // Fill gaps between recorded slots so missing half hours cannot disappear.
  if($keys){$first=(int)substr($keys[0],8,2)*60+(int)substr($keys[0],10,2);$last_key=end($keys);$last=(int)substr($last_key,8,2)*60+(int)substr($last_key,10,2);$keys=[];for($minute=$first;$minute<=$last && $minute<$close;$minute+=30)$keys[]=str_replace('-','',$day).sprintf('%02d%02d',intdiv($minute,60),$minute%60);}
  $means=[];
  foreach($m as $name=>$cells){
    ksort($m[$name]);$values=[];
    foreach($keys as $k){if(isset($cells[$k]) && $cells[$k]['w']!==null)$values[]=$cells[$k]['w'];}
    $means[$name]=['n'=>count($values),'avg'=>$values?round(array_sum($values)/count($values)):null];
  }
  $names=array_keys($m);
  usort($names,function($a,$b)use($m){$aa=end($m[$a]);$bb=end($m[$b]);return ($bb['w']??-1)<=>($aa['w']??-1) ?: strcmp($a,$b);});
  $id=wp_unique_id('tdr-wait-');
  $class=function($w){return $w===null?'tw-missing':($w<20?'low':($w<50?'mild':($w<80?'busy':($w<120?'high':($w<180?'very-high':'peak')))));};
  ob_start();?>
<style>
.tdr-waits{--tw-bg:#fff;--tw-fg:#25364a;--tw-line:#dce4ed;--tw-soft:#f1f5f9;color:var(--tw-fg);margin:20px 0;font-size:14px;background:var(--tw-bg);padding:14px;border-radius:12px}
.tdr-waits *{box-sizing:border-box}.tdr-waits p{color:var(--tw-fg)!important;font-size:13px;line-height:1.7;margin:8px 0}
.tdr-waits .tw-top{display:flex;flex-wrap:wrap;gap:8px;align-items:center;font-weight:700}.tdr-waits .tw-date{font-size:18px}.tdr-waits .tw-status{background:var(--tw-soft);padding:4px 10px;border-radius:20px;font-size:12px}
.tdr-waits .tw-tools{display:flex;flex-wrap:wrap;gap:12px;margin:16px 0 8px}.tdr-waits label{font-size:12px;font-weight:700;flex:1;min-width:130px}.tdr-waits input,.tdr-waits select{display:block;width:100%;border:1px solid var(--tw-line);border-radius:8px;background:var(--tw-bg);color:var(--tw-fg);padding:10px;font-size:16px;min-height:44px;margin-top:4px}
.tdr-waits .tw-scroll{overflow:auto;max-height:620px;border:1px solid var(--tw-line);border-radius:10px;overscroll-behavior-x:contain;background:var(--tw-bg)}
.tdr-waits table{border-collapse:separate;border-spacing:0;width:100%;margin:0;font-size:14px;table-layout:auto;color:var(--tw-fg)}
.tdr-waits th,.tdr-waits td{border:0;border-right:1px solid var(--tw-line);border-bottom:1px solid var(--tw-line);padding:10px 6px;text-align:center;min-width:62px;white-space:nowrap;vertical-align:middle}
.tdr-waits thead th{position:sticky;top:0;background:#203d60;color:#fff;z-index:2;font-size:12px;font-weight:600}
.tdr-waits th:first-child{position:sticky;left:0;min-width:160px;width:190px;max-width:220px;white-space:normal;text-align:left;background:var(--tw-bg);color:var(--tw-fg);z-index:1;line-height:1.5;padding:10px;font-size:13px}
.tdr-waits thead th:first-child{z-index:3;background:#203d60;color:#fff}.tdr-waits td small{display:block;font-size:10px;font-weight:400;margin-top:3px}.tdr-waits .tw-latest{font-weight:700}
.tdr-waits .tw-legend{display:flex;flex-wrap:wrap;gap:6px;margin:12px 0;font-size:11px}.tdr-waits .tw-legend span{padding:4px 7px;border-radius:4px;border:1px solid #d2dae4}
.tdr-waits .low{background:#f7fbff;color:#23344a}.tdr-waits .mild{background:#dcedff;color:#153e66}.tdr-waits .busy{background:#fff3b0;color:#514000}.tdr-waits .high{background:#ffdbab;color:#6b3600}.tdr-waits .very-high{background:#ffc1bb;color:#712218}.tdr-waits .peak{background:#a12531;color:#fff}.tdr-waits .tw-missing{background:var(--tw-soft);color:var(--tw-fg);font-size:11px}.tdr-waits .tw-mean{background:#eaf4ee;color:#215847;font-weight:700;min-width:75px}.tdr-waits .tw-range{display:block;font-size:10px;font-weight:400}.tdr-waits .tw-average-note{padding:10px;background:#eaf4ee;border-radius:8px}
.tdr-waits [hidden]{display:none!important}.tdr-waits .tw-empty{border:1px solid var(--tw-line);border-radius:10px;padding:20px;background:var(--tw-soft)}.tdr-waits a{color:#1765aa;text-decoration:underline}
.tdr-waits :focus-visible{outline:3px solid #2187c9;outline-offset:3px}
@media(max-width:600px){.tdr-waits th:first-child{min-width:126px;width:126px;max-width:126px;font-size:12px}.tdr-waits td,.tdr-waits th{min-width:54px;padding:9px 4px}.tdr-waits .tw-scroll{max-height:540px}}

.tdr-waits .tw-row-mean{display:block;margin-top:5px;font-size:12px;color:#215847;font-weight:700}.tdr-waits .tw-row-mean.tw-avg-low{color:#226443}.tdr-waits .tw-row-mean.tw-avg-mild{color:#175d94}.tdr-waits .tw-row-mean.tw-avg-busy{color:#806000}.tdr-waits .tw-row-mean.tw-avg-high{color:#9a4800}.tdr-waits .tw-row-mean.tw-avg-very-high{color:#b32624}.tdr-waits .tw-row-mean.tw-avg-peak{color:#850e44}.tdr-waits .tw-row-mean.tw-avg-tw-missing{color:#596675}
.tdr-waits td .tw-value{display:block;font-size:26px;font-weight:700;line-height:1.25;font-variant-numeric:tabular-nums}.tdr-waits td .tw-state{display:inline-block;max-width:4em;white-space:normal;word-break:keep-all;font-size:12px;line-height:1.5}
@media(max-width:600px){.tdr-waits td .tw-value{font-size:23px}.tdr-waits td .tw-state{font-size:11px}}.tdr-waits .tw-trend{display:block;margin-top:5px;font-size:13px;font-weight:800}.tdr-waits .tw-trend span{display:block;font-size:10px}.tdr-waits .tw-rise{color:#b32624}.tdr-waits .tw-fall{color:#075bbb}.tdr-waits .tw-date-form{display:flex;align-items:flex-end;gap:8px;margin:12px 0}.tdr-waits .tw-date-form label{flex:1;max-width:260px;font-size:12px}.tdr-waits button{border:1px solid #ccd8d0;border-radius:8px;background:#edf5ef;color:#215847;padding:10px;min-height:44px;font-weight:700;cursor:pointer}.tdr-waits .tw-scroll-nav{display:flex;justify-content:space-between;gap:8px;margin:8px 0}.tdr-waits .tw-tools{align-items:flex-end}
</style>
<section class="tdr-waits" id="<?php echo esc_attr($id);?>" aria-label="待ち時間の推移">
<div class="tw-top"><span class="tw-date"><?php echo esc_html(str_replace('-','/',$day));?></span><span class="tw-status"><?php echo !$is_today?'過去の記録':(tdrt_is_open($base)?'本日の記録':'営業時間外・本日の記録');?></span></div>
<form class="tw-date-form" method="get" action="#wait-history"><label>過去の日付を見る<select name="tw_date" aria-label="待ち時間の記録日"><?php foreach($days as $date):?><option value="<?php echo esc_attr($date);?>" <?php selected($date,$day);?>><?php echo esc_html(str_replace('-','/',$date).($date===$today?'（今日）':''));?></option><?php endforeach;?></select></label><button type="submit">表示する</button></form>
<?php if(!$rows || !$keys): ?>
<div class="tw-empty"><strong>この日の待ち時間の記録はまだありません</strong><p>数値を取得できた時間帯から表示します。未取得だった過去の時間帯は、さかのぼって補えません。</p></div>
<?php else: ?>
<p>データ日時：<?php echo esc_html(str_replace('-','/',$day).' '.tdrt_db_hi($latest));?> ／ 日本時間・単位：分。</p>
<?php $latest_dt=date_create_from_format('Y-m-d H:i:s',$latest,wp_timezone());if($is_today && tdrt_is_open($base) && $latest_dt && time()-$latest_dt->getTimestamp()>1200):?><p class="tw-average-note">最新データの取得が遅れています。下表は本日取得できた時点の記録です。</p><?php endif;?>
<div class="tw-tools" hidden><label>施設名で絞り込む<input type="search" placeholder="<?php echo $greet?'例：ミッキー':($base==='tds'?'例：ソアリン':'例：プーさん');?>" autocomplete="off"></label><label>表示する時間帯<select><option value="all">1日全体</option><option value="recent">最後の4時間帯</option><option value="morning">午前（12時まで）</option><option value="day">昼（12〜17時）</option><option value="night">夕方・夜（17時以降）</option></select></label></div>
<p class="tw-count" aria-live="polite"></p>
<p>時間帯の各欄は、その30分間で最後に取得した値です。横にスワイプして朝から閉園まで比較できます。閉園後の欄は表示しません。</p>
<p class="tw-average-note">施設名の下の平均は、選んだ日の30分ごとの記録値の単純平均（四捨五入）です。休止・未取得は除き、0分は含めます。時間帯を絞っても平均は変わりません。</p>
<div class="tw-scroll-nav"><button type="button" data-direction="-1">← 早い時間</button><button type="button" data-direction="1">遅い時間 →</button></div>
<div class="tw-scroll" tabindex="0" role="region" aria-label="施設別の待ち時間表・横スクロール可"><table>
<caption style="position:absolute;width:1px;height:1px;overflow:hidden;clip-path:inset(50%)">本日の待ち時間。列は30分の時間帯、行は施設。施設名の下に平均を表示。</caption>
<thead><tr><th scope="col"><?php echo $greet?'グリーティング':'アトラクション';?></th><?php foreach($keys as $k):$h=(int)substr((string)$k,8,2);$mi=(int)substr((string)$k,10,2);?><th scope="col" data-slot="<?php echo esc_attr($k);?>"><?php printf('%02d:%02d',$h,$mi);?><small class="tw-range"><?php printf('〜%02d:%02d',$h,$mi+29);?></small></th><?php endforeach;?></tr></thead>
<tbody><?php foreach($names as $name):$mean=$means[$name];?><tr data-name="<?php echo esc_attr($name);?>"><th scope="row"><?php echo esc_html($name);?><small class="tw-row-mean tw-avg-<?php echo esc_attr($class($mean['avg']));?>">平均 <?php echo $mean['avg']===null?'—':esc_html($mean['avg']).'分';?></small><?php $delta=$trends[$name]??0;if($delta):?><small class="tw-trend <?php echo $delta>0?'tw-rise':'tw-fall';?>"><?php echo $delta>0?'急増中！':'狙い目！';?> <span>前回比<?php echo $delta>0?'+':'';echo (int)$delta;?>分</span></small><?php endif;?></th><?php foreach($keys as $k):$c=$m[$name][$k]??null;$w=$c['w']??null;$text=$w===null?tdrt_history_state_label($c['s']??'unknown'):(string)$w;?><td data-slot="<?php echo esc_attr($k);?>" class="<?php echo esc_attr($class($w));?>"><span class="<?php echo $w===null?'tw-state':'tw-value';?>"><?php echo str_replace('運営中止','<wbr>運営中止',esc_html($text));?></span></td><?php endforeach;?></tr><?php endforeach;?></tbody></table></div>
<p class="tw-noresults" hidden>該当する施設・時間帯の記録がありません。</p>
<div class="tw-legend" aria-label="色の目安"><span class="low">0〜19分</span><span class="mild">20〜49分</span><span class="busy">50〜79分</span><span class="high">80〜119分</span><span class="very-high">120〜179分</span><span class="peak">180分以上</span></div>
<p>「急増中！」は前回比20分以上の増加、「狙い目！」は20分以上の減少が目安です。直近20分以内の連続した営業中の記録だけを比較し、休止明けや古いデータには表示しません。</p>
<p>「終日運営中止」は公式の休止期間に該当する施設、「一時運営中止」は運営中ではない記録です。「案内終了」は、待ち時間の案内がなくなり、取得時刻に直前の待ち時間を足すと閉園時刻を超える場合の推定です。取得失敗や判断できる記録がない欄は「未取得」とし、0分と区別しています。表示は取得時点の待ち時間で、実際に並んだ時間ではありません。</p>
<?php endif;?>
<p>出典：<?php if(!$greet):?><a href="https://queue-times.com/" target="_blank" rel="noopener noreferrer">Queue-Times.com</a>・<?php endif;?><a href="https://www.tokyodisneyresort.jp/<?php echo esc_attr($base);?>/realtime/" target="_blank" rel="noopener noreferrer">東京ディズニーリゾート公式</a>。現在の案内は公式アプリでご確認ください。</p>
<p>取得時刻を確認できる保存済みの記録を表示しています。</p>
</section>
<script>
(function(){const root=document.getElementById(<?php echo wp_json_encode($id);?>);const tools=root.querySelector('.tw-tools');if(!tools)return;tools.hidden=false;
const input=tools.querySelector('input'),select=tools.querySelector('select'),heads=[...root.querySelectorAll('thead [data-slot]')],rows=[...root.querySelectorAll('tbody tr')];
const norm=s=>s.normalize('NFKC').toLocaleLowerCase();
function update(){const mode=select.value,q=norm(input.value.trim());const slots=heads.map(h=>h.dataset.slot);const chosen=new Set(slots.filter((s,i)=>{const h=Number(s.slice(8,10));return mode==='all'||(mode==='recent'&&i>=slots.length-4)||(mode==='morning'&&h<12)||(mode==='day'&&h>=12&&h<17)||(mode==='night'&&h>=17)}));
root.querySelectorAll('[data-slot]').forEach(c=>c.hidden=!chosen.has(c.dataset.slot));let n=0;rows.forEach(r=>{r.hidden=!norm(r.dataset.name).includes(q);if(!r.hidden)n++});root.querySelector('.tw-count').textContent=n+'施設 ／ '+chosen.size+'時間帯';root.querySelector('.tw-scroll').hidden=!n||!chosen.size;root.querySelector('.tw-noresults').hidden=!!n&&!!chosen.size;}
root.querySelectorAll('[data-direction]').forEach(b=>b.addEventListener('click',()=>root.querySelector('.tw-scroll').scrollBy({left:Number(b.dataset.direction)*240,behavior:'smooth'})));
input.addEventListener('input',update);select.addEventListener('change',update);update();})();
</script>
<?php return ob_get_clean();
}
add_shortcode('tdr_today_heatmap','tdrt_heatmap');

// ---------- DPA / プライオリティパス 発券タイムライン ----------
function tdrt_render_pass_today($a){
  global $wpdb;
  $a = shortcode_atts(['park'=>'tdl'], $a, 'tdr_pass_today');
  $park = strtolower($a['park'])==='tds'?'tds':'tdl';
  $pl = $park==='tdl'?'東京ディズニーランド':'東京ディズニーシー';
  $pc = $park==='tdl'?'#1d4f91':'#0a8aa6';
  $today = wp_date('Y-m-d');
  $last=(int)get_option('tdrt_realtime_last_'.$park,get_option('tdrt_realtime_last',0));
  $is_fresh=$last>0 && time()-$last<=1200;
  // Yesterday is never current; delayed same-day observations remain explicit history.
  if(!$last || $last>time()+60 || wp_date('Y-m-d',$last)!==$today){
    return '<div class="tpt"><h3>🎫 DPA・スタンバイパス</h3><p>現在の発行状況は確認できていません。東京ディズニーリゾート公式アプリでご確認ください。</p><p style="font-size:12px">40周年記念プライオリティパスは2026年8月31日で終了しました。<a href="https://www.tokyodisneyresort.jp/tdr/news/update/tdr/202605" target="_blank" rel="noopener noreferrer">公式のお知らせ</a></p></div>';
  }

  $ptbl = tdrt_pass_table();
  // 今日のイベントを取得
  $rows = $wpdb->get_results($wpdb->prepare(
    "SELECT attr_id,attr_name,pass_type,event,event_time FROM {$ptbl} "
    ."WHERE park=%s AND event_date=%s ORDER BY event_time ASC",
    $park, $today));
  // attr_id -> [type -> [start_time, end_time]]
  $by_attr = [];
  foreach($rows as $r){
    if($r->pass_type==='pp' && $today>='2026-09-01')continue;
    $aid = $r->attr_id;
    $by_attr[$aid]['name'] = tdrt_fix_name($r->attr_name);
    $by_attr[$aid][$r->pass_type][$r->event] = $r->event_time;
  }
  // 現在の状態（営業時間外は古い値を「発券中」と誤認させないため空に倒す）
  $is_open = tdrt_is_open($park);
  $current = $is_open ? get_option('tdrt_realtime_'.$park, []) : [];
  if(!is_array($current)) $current = [];
  $cur_idx = [];
  foreach($current as $c){ if(isset($c['name'])) $cur_idx[$c['name']] = $c; }
  // v1.5.4: 休止中のアトラクションを発券状況テーブルから除外
  $stopped_names = tdrt_get_stopped_attraction_names();

  $type_label = ['dpa'=>'DPA', 'pp'=>'PP', 'sbp'=>'SP'];
  $type_color = ['dpa'=>'#d63384', 'pp'=>'#0d6efd', 'sbp'=>'#198754'];

  ob_start();?>
<style>
.tpt{font-family:-apple-system,sans-serif}
.tpt h3{font-size:16px;margin:18px 0 10px;padding-left:10px;border-left:4px solid <?php echo $pc;?>}
.tpt table{width:100%;border-collapse:collapse;font-size:12px;background:#fff}
.tpt th,.tpt td{border:1px solid #e0e0e0;padding:6px 8px;text-align:left;vertical-align:middle}
.tpt thead th{background:#2a2a3a;color:#fff;font-weight:600;font-size:11px;text-align:center}
.tpt .pass{white-space:nowrap;display:inline-block;padding:2px 6px;border-radius:3px;color:#fff;font-size:10px;font-weight:700;margin-right:4px}
.tpt .ended{opacity:0.5;text-decoration:line-through}
.tpt .live{background:#fff8b3}
.tpt tbody td:nth-child(3),.tpt tbody td:nth-child(4){white-space:nowrap}
.tpt .end-time{font-weight:700;color:#d0334d}
.tpt .start-time{color:#666;font-size:11px}
.tpt .tpt-empty{color:#aaa;text-align:center;padding:20px}
.tpt .legend{font-size:11px;color:#666;margin:6px 0 12px}
.tpt .legend .pass{margin-right:4px}
</style>
<div class="tpt">
<h3>🎫 <?php echo esc_html($pl);?> パス発券状況</h3>
<div class="legend">
  <span class="pass" style="background:#d63384">DPA</span>ディズニー・プレミアアクセス（有料）

  <span class="pass" style="background:#198754">SP</span>スタンバイパス
</div>
<?php if(!$is_open): ?>
<p style="color:#666;font-size:12px;text-align:center;padding:8px 12px;background:#f8f8f8;border-radius:6px;margin:8px 0">🌙 現在パークは営業時間外です。下記は本日の最終発券時刻（過去履歴）を表示しています。</p>
<?php endif; ?>
<?php if(empty($by_attr)): ?>
<p class="tpt-empty">本日の発行記録はまだありません。現在の案内は公式アプリでご確認ください。</p>
<?php else: ?>
<table>
<thead><tr><th>アトラクション</th><th>種別</th><th>発行中を確認</th><th>終了を確認</th><th>確認時の状況</th></tr></thead>
<tbody>
<?php
// ソート: 発券中(まだ終わってない)を上に、終わった順を時間降順で
$sorted = [];
foreach($by_attr as $aid=>$data){
  $name = $data['name'];
  // v1.5.4: 休止中のアトラクションを除外
  if(isset($stopped_names[$name])) continue;
  foreach(['dpa','sbp'] as $tp){
    if(!isset($data[$tp])) continue;
    $start = $data[$tp]['start'] ?? null;
    $end   = $data[$tp]['end']   ?? null;
    $cur   = $cur_idx[$name]['has_'.$tp] ?? false;
    $sorted[] = ['aid'=>$aid,'name'=>$name,'type'=>$tp,'start'=>$start,'end'=>$end,'live'=>$cur];
  }
}
usort($sorted, function($a,$b){
  // 発券中(live)を上、終了済(end)はend時刻降順、startだけは時刻降順
  if($a['live'] !== $b['live']) return $b['live']?1:-1;
  $at = $a['end'] ?? $a['start'] ?? '';
  $bt = $b['end'] ?? $b['start'] ?? '';
  return strcmp($bt, $at);
});
foreach($sorted as $row):
  $tp = $row['type'];
  $start_t = tdrt_db_hi($row['start']);
  $end_t   = $row['end'] ? tdrt_db_hi($row['end']) : '—';
  $live    = $row['live'];
?>
<tr class="<?php echo $live?'live':'ended';?>">
<td><?php echo esc_html($row['name']);?></td>
<td><span class="pass" style="background:<?php echo $type_color[$tp];?>"><?php echo $type_label[$tp];?></span></td>
<td class="start-time"><?php echo esc_html($start_t);?></td>
<td class="<?php echo (!$live && $row['end'])?'end-time':'';?>"><?php echo esc_html($end_t);?></td>
<td><?php echo $live?($is_fresh?'🟢 確認時は発行中':'確認時は発行中'):($row['end']?'終了を確認':'発行終了は未確認');?></td>
</tr>
<?php endforeach;?>
</tbody>
</table>
<?php endif;?>
<?php if(!$is_fresh): ?><p role="status" style="color:#6d4900;background:#fff7e0;padding:10px">更新が遅れています。表は本日の最終確認時点の記録で、現在の発行状況ではありません。公式アプリでご確認ください。</p><?php endif; ?>
<p style="font-size:12px">時刻は定期取得で発行状況を確認した時刻です。実際の発行開始・終了時刻と異なる場合があります。対象はアトラクションの記録です。</p>
<?php if($last):?>
<p style="font-size:11px;color:#888;margin-top:6px;text-align:right">最終更新: <?php echo esc_html(wp_date('n月j日 H:i', $last));?></p>
<?php endif;?>
</div>
<?php return ob_get_clean();
}
function tdrt_pass_today($a){
  $park=strtolower($a['park']??'tdl')==='tds'?'tds':'tdl';
  $id='tdrt-pass-status-'.$park;
  $endpoint=rest_url('tdr-today/v1/pass-status').'?park='.$park;
  $html='<section id="'.esc_attr($id).'" data-pass-status data-pass-date="'.esc_attr(wp_date('Ymd')).'">'.tdrt_render_pass_today(['park'=>$park]).'</section>';
  ob_start(); ?>
<script>
(function(){const box=document.getElementById(<?php echo wp_json_encode($id); ?>);if(!box)return;
const endpoint=<?php echo wp_json_encode($endpoint); ?>;let busy=false;
async function refresh(){if(busy||document.hidden)return;busy=true;
try{const r=await fetch(endpoint+'&_cb='+Date.now(),{cache:'no-store',credentials:'same-origin'});if(!r.ok)throw Error();const d=await r.json();if(typeof d.html!=='string')throw Error();box.innerHTML=d.html;box.dataset.passDate=d.date;}
catch(e){const day=new Intl.DateTimeFormat('sv-SE',{timeZone:'Asia/Tokyo',year:'numeric',month:'2-digit',day:'2-digit'}).format().replace(/-/g,'');if(box.dataset.passDate!==day)box.innerHTML='<h3>🎫 DPA・スタンバイパス</h3>';if(!box.querySelector('[data-refresh-error]'))box.insertAdjacentHTML('beforeend','<p data-refresh-error role="status">最新情報への更新に失敗しました。確認時刻を参照し、現在の発行状況は公式アプリでご確認ください。</p>');}
finally{busy=false;}}
refresh();setInterval(refresh,60000);document.addEventListener('visibilitychange',()=>{if(!document.hidden)refresh();});})();
</script>
<?php return $html.ob_get_clean();
}
add_shortcode('tdr_pass_today','tdrt_pass_today');

// ---------- 過去同曜日の平均待ち時間（ヒートマップ） ----------
// [tdr_history park="tdl" weekday="today|mon|tue|wed|thu|fri|sat|sun|1-7" days="90" type="attr|greet"]
function tdrt_history($a){
  global $wpdb;
  $a = shortcode_atts(['park'=>'tdl','weekday'=>'today','days'=>'90','type'=>'attr'], $a, 'tdr_history');
  $base = strtolower($a['park'])==='tds'?'tds':'tdl';
  $park = ($a['type']==='greet') ? $base.'_g' : $base;
  $days = max(7, min(365, intval($a['days'])));
  // weekday: 'today' / 'mon'-'sun' / 数値1-7（1=日, MySQL DAYOFWEEK 仕様）
  $wd_map = ['sun'=>1,'mon'=>2,'tue'=>3,'wed'=>4,'thu'=>5,'fri'=>6,'sat'=>7];
  $wd_label_full = ['','日曜','月曜','火曜','水曜','木曜','金曜','土曜'];
  $w_in = strtolower((string)$a['weekday']);
  if($w_in==='today'){ $dow = ((int)wp_date('w')) + 1; }
  else if(isset($wd_map[$w_in])){ $dow = $wd_map[$w_in]; }
  else { $dow = max(1, min(7, intval($a['weekday']))); }

  $tbl = tdrt_table();
  $rows = $wpdb->get_results($wpdb->prepare(
    "SELECT attr_id, attr_name, MOD(slot_key, 10000) AS hhmm,
            ROUND(AVG(wait_min)) AS avg_w, COUNT(*) AS cnt
     FROM {$tbl}
     WHERE park=%s
       AND DAYOFWEEK(recorded_at) = %d
       AND recorded_at >= DATE_SUB(CURDATE(), INTERVAL %d DAY)
       AND wait_min IS NOT NULL
     GROUP BY attr_id, attr_name, MOD(slot_key, 10000)
     HAVING cnt >= 2
     ORDER BY MOD(slot_key, 10000) ASC",
    $park, $dow, $days));
  if(!$rows) return '<p style="text-align:center;color:#aaa;padding:20px">過去'.esc_html($days).'日間の'.esc_html($wd_label_full[$dow]).'のデータがまだ十分に蓄積されていません。<br><small>毎日収集中のため、数週間後に有意なグラフが表示されます。</small></p>';

  $m=[];$nm=[];$sl=[];$tot=[];
  foreach($rows as $r){
    $hh=(int)$r->hhmm;
    $m[$r->attr_id][$hh]=(int)$r->avg_w;
    $nm[$r->attr_id]=tdrt_fix_name($r->attr_name);
    $sl[$hh]=1;
    $tot[$r->attr_id]=($tot[$r->attr_id]??0)+(int)$r->avg_w;
  }
  $ks=array_keys($sl);sort($ks);arsort($tot);
  $ord=array_keys($tot);
  $cls=function($w){if($w===null)return 'h0';if($w>=180)return 'h6';if($w>=120)return 'h5';if($w>=80)return 'h4';if($w>=50)return 'h3';if($w>=20)return 'h2';return 'h1';};
  ob_start();?>
<style>.thh{overflow-x:auto;margin:16px 0;-webkit-overflow-scrolling:touch}
.thh table{border-collapse:collapse;font-size:11px;background:#fff}
.thh th,.thh td{border:1px solid #ddd;text-align:center;padding:4px 6px;min-width:36px;white-space:nowrap}
.thh thead th{background:#2a2a3a;color:#fff;writing-mode:vertical-rl;text-orientation:mixed;padding:8px 4px;font-weight:600;max-width:32px;height:120px;line-height:1.15}
.thh thead th:first-child{writing-mode:initial;text-orientation:initial;max-width:none;min-width:50px;height:auto}
.thh tbody th{background:#f5f5f5;text-align:right;padding-right:6px;font-weight:500;position:sticky;left:0}
.thh .h0{background:#fafafa;color:#ccc}.thh .h1{background:#fff}.thh .h2{background:#e0f2ff}.thh .h3{background:#fff8b3}.thh .h4{background:#ffd699}.thh .h5{background:#ff9999}.thh .h6{background:#cc4444;color:#fff;font-weight:600}
.thhl{font-size:11px;color:#666;margin:6px 0}.thhl span{display:inline-block;padding:2px 8px;border-radius:3px;margin-right:4px;border:1px solid #ddd}</style>
<p style="font-size:13px;color:#444;margin:6px 0">📊 過去<?php echo esc_html($days);?>日間の<strong><?php echo esc_html($wd_label_full[$dow]);?></strong>の平均待ち時間（分）</p>
<div class="thhl"><span class="h1">~20分</span><span class="h2">~50分</span><span class="h3">~80分</span><span class="h4">~120分</span><span class="h5">~180分</span><span class="h6">180+</span></div>
<div class="thh"><table><thead><tr><th>時刻</th><?php foreach($ord as $aid):?><th><?php echo esc_html($nm[$aid]);?></th><?php endforeach;?></tr></thead><tbody>
<?php foreach($ks as $hhmm):$hh=(int)floor($hhmm/100);$mm=$hhmm%100;?>
<tr><th><?php printf('%02d:%02d',$hh,$mm);?></th>
<?php foreach($ord as $aid):$w=$m[$aid][$hhmm]??null;$c=$cls($w);$d=$w===null?'-':$w;?>
<td class="<?php echo $c;?>"><?php echo esc_html($d);?></td>
<?php endforeach;?></tr>
<?php endforeach;?>
</tbody></table></div>
<p style="font-size:11px;color:#888;margin-top:6px">※ 各時刻2件以上のサンプルがある時刻のみ表示。<small>過去<?php echo esc_html($days);?>日間平均</small></p>
<?php return ob_get_clean();
}
add_shortcode('tdr_history','tdrt_history');

// ---------- DPA/PP/SP 発券終了時刻 曜日別集計表 ----------
// [tdr_pass_history park="tdl" type="dpa|pp|sbp" days="60"]
function tdrt_pass_history($a){
  global $wpdb;
  $a = shortcode_atts(['park'=>'tdl','days'=>'60','type'=>'dpa'], $a, 'tdr_pass_history');
  $park = strtolower($a['park'])==='tds'?'tds':'tdl';
  $type = in_array($a['type'], ['dpa','pp','sbp'], true) ? $a['type'] : 'dpa';
  $days = max(14, min(365, intval($a['days'])));
  $type_label = ['dpa'=>'ディズニー・プレミアアクセス（DPA）','pp'=>'プライオリティパス（PP）','sbp'=>'スタンバイパス'];
  $type_color = ['dpa'=>'#d63384','pp'=>'#0d6efd','sbp'=>'#198754'];

  $ptbl = tdrt_pass_table();
  $rows = $wpdb->get_results($wpdb->prepare(
    "SELECT attr_id, attr_name, DAYOFWEEK(event_date) AS dow, event_time, event_date
     FROM {$ptbl}
     WHERE park=%s AND pass_type=%s AND event='end'
       AND event_date >= DATE_SUB(CURDATE(), INTERVAL %d DAY)",
    $park, $type, $days));
  if(!$rows) return '<p style="text-align:center;color:#aaa;padding:20px">過去'.esc_html($days).'日間の<strong>'.esc_html($type_label[$type]).'</strong>発券データがまだ蓄積されていません。<br><small>5〜30分間隔で自動収集中。</small></p>';

  // attr_id => dow(1-7) => [秒]
  $by = []; $nm = [];
  foreach($rows as $r){
    $aid = $r->attr_id;
    $nm[$aid] = tdrt_fix_name($r->attr_name);
    $t = strtotime($r->event_time);
    $d = strtotime($r->event_date.' 00:00:00');
    if($t && $d){
      $by[$aid][(int)$r->dow][] = $t - $d;
    }
  }
  // 並び順: 平日(月-金)平均終了時刻が早い順 = 完売が激しい人気アトラクション順
  $rank = [];
  foreach($by as $aid => $w){
    $secs=[];
    foreach([2,3,4,5,6] as $d){ if(!empty($w[$d])) $secs = array_merge($secs, $w[$d]); }
    $rank[$aid] = $secs ? array_sum($secs)/count($secs) : PHP_INT_MAX;
  }
  asort($rank);
  $ord = array_keys($rank);

  $wd_label = ['','日','月','火','水','木','金','土']; // index 1=Sun
  $tt = function($secs){
    if($secs === null) return '—';
    $h = (int)floor($secs/3600); $m = (int)floor(($secs%3600)/60);
    return sprintf('%02d:%02d', $h, $m);
  };
  $cls_t = function($secs){
    if($secs === null) return 'pn';
    $h = $secs/3600;
    if($h <  9.5) return 'p6';
    if($h < 10.5) return 'p5';
    if($h < 12)   return 'p4';
    if($h < 14)   return 'p3';
    if($h < 17)   return 'p2';
    return 'p1';
  };

  ob_start();?>
<style>
.tph{margin:16px 0}
.tph .head{padding:8px 12px;background:linear-gradient(90deg,<?php echo $type_color[$type];?>,<?php echo $type_color[$type];?>cc);color:#fff;font-size:13px;border-radius:4px 4px 0 0}
.tph table{width:100%;border-collapse:collapse;font-size:12px;background:#fff;table-layout:fixed}
.tph th,.tph td{border:1px solid #e0e0e0;padding:6px 4px;text-align:center}
.tph thead th{background:#2a2a3a;color:#fff;font-size:11px;font-weight:600}
.tph thead th:first-child{text-align:left;padding-left:8px}
.tph tbody th{background:#f5f5f5;text-align:left;font-weight:500;padding:6px 8px;font-size:11.5px}
.tph .pn{background:#fafafa;color:#bbb}
.tph .p1{background:#d6f5d6}
.tph .p2{background:#e8f7c9}
.tph .p3{background:#fff8b3}
.tph .p4{background:#ffd699}
.tph .p5{background:#ff9999}
.tph .p6{background:#cc4444;color:#fff;font-weight:700}
.tph .legend{font-size:11px;color:#666;margin:6px 0}
.tph .legend span{display:inline-block;padding:2px 8px;border-radius:3px;margin:0 2px;border:1px solid #ddd;font-size:10.5px}
.tph .wrap{overflow-x:auto;-webkit-overflow-scrolling:touch}
.tph .note{font-size:11px;color:#888;margin-top:6px}
</style>
<div class="tph">
<div class="head">🎫 <?php echo esc_html($type_label[$type]);?> 発券終了時刻 曜日別平均（過去<?php echo esc_html($days);?>日間）</div>
<div class="legend">完売時刻：<span class="p6">~9:30</span><span class="p5">~10:30</span><span class="p4">~12:00</span><span class="p3">~14:00</span><span class="p2">~17:00</span><span class="p1">17時〜/残あり</span></div>
<div class="wrap"><table>
<thead><tr><th>アトラクション</th>
<?php foreach([2,3,4,5,6,7,1] as $d):?><th><?php echo $wd_label[$d];?></th><?php endforeach;?>
</tr></thead>
<tbody>
<?php foreach($ord as $aid):?>
<tr><th><?php echo esc_html($nm[$aid]);?></th>
<?php foreach([2,3,4,5,6,7,1] as $d):
  $arr = $by[$aid][$d] ?? [];
  $avg = (count($arr) >= 2) ? array_sum($arr)/count($arr) : null;
  $c = $cls_t($avg);
?><td class="<?php echo $c;?>"><?php echo esc_html($tt($avg));?></td><?php endforeach;?>
</tr>
<?php endforeach;?>
</tbody>
</table></div>
<p class="note">※ 各曜日2件以上のサンプルがある場合のみ平均値を表示。データ蓄積に応じて精度向上。</p>
</div>
<?php return ob_get_clean();
}
add_shortcode('tdr_pass_history','tdrt_pass_history');

// REST: /tdr-today/v1/realtime — accepts realtime badge state + computes pass-event transitions.
// Body: {tdl: [{name, has_pp:bool, has_dpa:bool, has_sbp:bool, status:str}], tds: [...]}
// 前回スナップショット (option `tdrt_realtime_<park>`) と比較し、バッジが新たに付いた/外れた瞬間を
// `wp_tdr_pass_events` テーブルへ INSERT IGNORE。同じ日に同じ event は重複しない（UNIQUE KEY）。
add_action('rest_api_init', function(){
  register_rest_route('tdr-today/v1', '/realtime', [
    'methods' => 'POST',
    'permission_callback' => function($req){
      $token = (string) $req->get_header('x-tdr-token');
      $expected = (string) get_option('tdr_mon_ingest_token', '');
      return $token !== '' && $expected !== '' && hash_equals($expected, $token);
    },
    'callback' => function($req){
      global $wpdb;
      $body = $req->get_json_params();
      if(!is_array($body)) return new WP_Error('bad_json', 'expected JSON', ['status'=>400]);
      $now_dt = current_time('mysql');
      $today = wp_date('Y-m-d');
      $ptbl = tdrt_pass_table();
      $events_logged = 0;
      $stored = [];
      foreach(['tdl','tds'] as $park){
        if(!isset($body[$park]) || !is_array($body[$park])) continue;
        $rows = [];
        foreach($body[$park] as $row){
          if(!is_array($row) || empty($row['name'])) continue;
          $rows[] = [
            'name'    => (string)$row['name'],
            'has_pp'  => !empty($row['has_pp']),
            'has_dpa' => !empty($row['has_dpa']),
            'has_sbp' => !empty($row['has_sbp']),
            'status'  => isset($row['status']) ? (string)$row['status'] : 'operating',
            'wait_min'=> (isset($row['wait_min']) && $row['wait_min']!==''&&$row['wait_min']!==null) ? (int)$row['wait_min'] : null,
          ];
        }
        $opt_key = 'tdrt_realtime_'.$park;
        $prev = get_option($opt_key, []);
        if(!is_array($prev)) $prev = [];

        // v1.5.2: 営業時間外のフラッキー値（公式 realtime API が has_dpa=true を
        // 散発的に返すケース）で「start」イベントが誤発火するのを防ぐ。
        //   1. park_open でない場合は event INSERT を完全 skip
        //   2. 当日初の営業時間内 POST 時に、prev の has_* フラグを false にリセット
        //      し、かつ当日の event_time < 開園時刻 の事前イベントを削除（cleanup）
        $park_open = tdrt_is_open($park);
        $open_date_key = 'tdrt_open_date_'.$park;
        $last_open_date = (string) get_option($open_date_key, '');
        $is_first_open_today = ($park_open && $last_open_date !== $today);

        if($is_first_open_today){
          // prev の has_* フラグをリセット（営業時間外に保存された flaky データを無効化）
          foreach($prev as $k=>$pp){
            if(is_array($pp)){
              $prev[$k]['has_pp']  = false;
              $prev[$k]['has_dpa'] = false;
              $prev[$k]['has_sbp'] = false;
            }
          }
          // 当日の開園前イベント（営業時間外に誤って INSERT されたもの）を削除
          $hours = tdrt_hours($park);
          if($hours && !empty($hours['open'])){
            $open_dt = $today.' '.$hours['open'].':00';
            $wpdb->query($wpdb->prepare(
              "DELETE FROM {$ptbl} WHERE park=%s AND event_date=%s AND event_time < %s",
              $park, $today, $open_dt
            ));
          }
        }

        // Index prev by name for diff
        $prev_idx = [];
        foreach($prev as $p){ if(isset($p['name'])) $prev_idx[$p['name']] = $p; }
        // Diff & event INSERT — 営業時間内のみ
        if($park_open){
          foreach($rows as $r){
            $nm = $r['name'];
            $aid = substr(md5($nm), 0, 16);
            $p = $prev_idx[$nm] ?? null;
            $pairs = [
              'pp'  => 'has_pp',
              'dpa' => 'has_dpa',
              'sbp' => 'has_sbp',
            ];
            foreach($pairs as $type=>$flag){
              $now_on  = (bool)$r[$flag];
              $prev_on = $p ? (bool)($p[$flag] ?? false) : null;
              // 初観測: prev_onがnullで now_on=true なら "start"
              if($prev_on === null && $now_on){
                $event = 'start';
              } elseif($prev_on === false && $now_on === true){
                $event = 'start';
              } elseif($prev_on === true && $now_on === false){
                $event = 'end';
              } else {
                continue;
              }
              $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO {$ptbl} (park,attr_id,attr_name,pass_type,event,event_date,event_time) "
                ."VALUES (%s,%s,%s,%s,%s,%s,%s)",
                $park,$aid,$nm,$type,$event,$today,$now_dt
              ));
              if($wpdb->rows_affected > 0) $events_logged++;
            }
          }
          // 営業時間内 POST を確認できたのでマーカー更新
          update_option($open_date_key, $today, false);
        }
        // Save current snapshot (badge state)
        $serialized = serialize($rows);
        $existing = $wpdb->get_var($wpdb->prepare("SELECT option_id FROM {$wpdb->options} WHERE option_name = %s", $opt_key));
        if($existing){
          $wpdb->update($wpdb->options, ['option_value'=>$serialized,'autoload'=>'no'], ['option_name'=>$opt_key], ['%s','%s'], ['%s']);
        } else {
          $wpdb->insert($wpdb->options, ['option_name'=>$opt_key,'option_value'=>$serialized,'autoload'=>'no'], ['%s','%s','%s']);
        }
        wp_cache_delete($opt_key, 'options');
        // Also save in DWR-compatible shape so tdrt_wait_data() / tdrt_snapshot() can use it
        // when DWR plugin's pseudo-cron is not running. Includes timestamp for freshness check.
        $waits_compat = [];
        foreach($rows as $r){
          if(empty($r['name'])) continue;
          $waits_compat[] = [
            'name'     => $r['name'],
            'wait_min' => $r['wait_min'] ?? null,
            'status'   => $r['status'] ?? 'operating',
          ];
        }
        $waits_payload = serialize(['rows'=>$waits_compat, 'ts'=>time(), 'source'=>'official-fetcher']);
        $waits_key = 'tdrt_waits_'.$park;
        $wexisting = $wpdb->get_var($wpdb->prepare("SELECT option_id FROM {$wpdb->options} WHERE option_name = %s", $waits_key));
        if($wexisting){
          $wpdb->update($wpdb->options, ['option_value'=>$waits_payload,'autoload'=>'no'], ['option_name'=>$waits_key], ['%s','%s'], ['%s']);
        } else {
          $wpdb->insert($wpdb->options, ['option_name'=>$waits_key,'option_value'=>$waits_payload,'autoload'=>'no'], ['%s','%s','%s']);
        }
        wp_cache_delete($waits_key, 'options');
        $stored[$park] = count($rows);
        update_option('tdrt_realtime_last_'.$park,time(),false);
      }
      if($stored)update_option('tdrt_realtime_last', time(), false);
      return ['ok'=>true, 'stored'=>$stored, 'events_logged'=>$events_logged, 'now'=>time()];
    },
  ]);
});

// REST: /tdr-today/v1/xstate — durable storage for fetcher.py の X 自動投稿 state.
// GHA actions/cache が runs を跨いで .x_state.json を保てない場合の代替経路。
// GET: 現在の state JSON を返す（無ければ {}）
// POST: 受け取った JSON をそのまま option `tdrt_x_state` に保存
add_action('rest_api_init', function(){
  $auth = function($req){
    $token = (string) $req->get_header('x-tdr-token');
    $expected = (string) get_option('tdr_mon_ingest_token', '');
    return $token !== '' && $expected !== '' && hash_equals($expected, $token);
  };
  register_rest_route('tdr-today/v1', '/xstate', [
    [
      'methods' => 'GET',
      'permission_callback' => $auth,
      'callback' => function($req){
        $raw = get_option('tdrt_x_state', '');
        if($raw === '' || $raw === null) return ['state' => new stdClass(), 'empty' => true];
        $d = json_decode($raw, true);
        if(!is_array($d)) return ['state' => new stdClass(), 'empty' => true, 'corrupt' => true];
        return ['state' => $d, 'empty' => false, 'updated_at' => (int) get_option('tdrt_x_state_at', 0)];
      },
    ],
    [
      'methods' => 'POST',
      'permission_callback' => $auth,
      'callback' => function($req){
        $body = $req->get_json_params();
        if(!is_array($body)) return new WP_Error('bad_json', 'expected JSON object', ['status'=>400]);
        // Validate shape lightly: should have at least one of seeded/posted_ids/by_date
        $payload = wp_json_encode($body);
        if($payload === false || strlen($payload) > 200000){
          return new WP_Error('too_big', 'state payload >200KB', ['status'=>413]);
        }
        update_option('tdrt_x_state', $payload, false);
        update_option('tdrt_x_state_at', time(), false);
        return ['ok' => true, 'bytes' => strlen($payload), 'now' => time()];
      },
    ],
  ]);
});

// REST: /tdr-today/v1/snapshot — manually trigger snapshot from external cron (cron-job.org / GHA)
// 擬似 WP-Cron に依存しない確実な15分間隔記録のため。
add_action('rest_api_init', function(){
  register_rest_route('tdr-today/v1', '/snapshot', [
    'methods' => 'POST',
    'permission_callback' => function($req){
      $token = (string) $req->get_header('x-tdr-token');
      $expected = (string) get_option('tdr_mon_ingest_token', '');
      return $token !== '' && $expected !== '' && hash_equals($expected, $token);
    },
    'callback' => function($req){
      tdrt_snapshot();
      return [
        'ok' => true,
        'last_snap' => (int) get_option('tdrt_last_snap', 0),
        'last_count' => (int) get_option('tdrt_last_count', 0),
        'now' => time(),
      ];
    },
  ]);
});

// REST: /tdr-today/v1/greetings — accepts {tdl:[{name,wait_min,status,location}], tds:[...]}
// fetcher.py が /tdl/realtime/greeting.html / /tds/realtime/greeting.html を Playwright で取得して POST する。
// 保存先は option `tdrt_greet_tdl` / `tdrt_greet_tds`。tdrt_snapshot() がここから 15分毎にヒストリへ転記。
add_action('rest_api_init', function(){
  register_rest_route('tdr-today/v1', '/greetings', [
    'methods' => 'POST',
    'permission_callback' => function($req){
      $token = (string) $req->get_header('x-tdr-token');
      $expected = (string) get_option('tdr_mon_ingest_token', '');
      return $token !== '' && $expected !== '' && hash_equals($expected, $token);
    },
    'callback' => function($req){
      $body = $req->get_json_params();
      if(!is_array($body)) return new WP_Error('bad_json', 'expected JSON', ['status'=>400]);
      $stored = [];
      foreach(['tdl','tds'] as $park){
        if(!isset($body[$park]) || !is_array($body[$park])) continue;
        $clean = [];
        foreach($body[$park] as $row){
          if(!is_array($row) || empty($row['name'])) continue;
          $clean[] = [
            'name'     => (string)$row['name'],
            'name_en'  => isset($row['name_en']) ? (string)$row['name_en'] : '',
            'wait_min' => (isset($row['wait_min']) && $row['wait_min']!=='' && $row['wait_min']!==null) ? (int)$row['wait_min'] : null,
            'status'   => isset($row['status']) ? (string)$row['status'] : 'operating',
            'location' => isset($row['location']) ? (string)$row['location'] : '',
            'character'=> isset($row['character']) ? (string)$row['character'] : '',
          ];
        }
        $opt_key = 'tdrt_greet_'.$park;
        $serialized = serialize($clean);
        global $wpdb;
        $existing = $wpdb->get_var($wpdb->prepare("SELECT option_id FROM {$wpdb->options} WHERE option_name = %s", $opt_key));
        if($existing){
          $wpdb->update($wpdb->options, ['option_value'=>$serialized,'autoload'=>'no'], ['option_name'=>$opt_key], ['%s','%s'], ['%s']);
        } else {
          $wpdb->insert($wpdb->options, ['option_name'=>$opt_key,'option_value'=>$serialized,'autoload'=>'no'], ['%s','%s','%s']);
        }
        wp_cache_delete($opt_key, 'options');
        update_option('tdrt_greet_observed_'.$park,time(),false);
        $stored[$park] = count($clean);
      }
      return ['ok'=>true, 'stored'=>$stored, 'now'=>time()];
    },
  ]);
});

// REST: /tdr-today/v1/shows — accepts {tdl:[{name,times,category}], tds:[...], date:Ymd}
add_action('rest_api_init', function(){
  register_rest_route('tdr-today/v1', '/shows', [
    'methods' => 'POST',
    'permission_callback' => function($req){
      $token = (string) $req->get_header('x-tdr-token');
      $expected = (string) get_option('tdr_mon_ingest_token', '');
      return $token !== '' && $expected !== '' && hash_equals($expected, $token);
    },
    'callback' => function($req){
      $body = $req->get_json_params();
      if(!is_array($body)) return new WP_Error('bad_json', 'expected JSON', ['status'=>400]);
      $date = isset($body['date']) ? preg_replace('/[^0-9]/', '', (string)$body['date']) : wp_date('Ymd');
      if(strlen($date) !== 8) return new WP_Error('bad_date', 'date must be Ymd', ['status'=>400]);
      $out = [];
      foreach(['tdl','tds'] as $park){
        if(isset($body[$park]) && is_array($body[$park])){
          $clean = [];
          foreach($body[$park] as $row){
            if(!is_array($row) || empty($row['name'])) continue;
            $clean[] = [
              'name' => (string)$row['name'],
              'times' => isset($row['times']) && is_array($row['times']) ? array_values(array_map('strval', $row['times'])) : [],
              'category' => isset($row['category']) ? (string)$row['category'] : 'show',
            ];
          }
          $out[$park] = $clean;
        }
      }
      $opt_key = 'tdrt_shows_'.$date;
      $serialized = serialize($out);
      global $wpdb;
      $existing = $wpdb->get_var($wpdb->prepare("SELECT option_id FROM {$wpdb->options} WHERE option_name = %s", $opt_key));
      if($existing){
        $wpdb->update($wpdb->options, ['option_value'=>$serialized,'autoload'=>'no'], ['option_name'=>$opt_key], ['%s','%s'], ['%s']);
      } else {
        $wpdb->insert($wpdb->options, ['option_name'=>$opt_key,'option_value'=>$serialized,'autoload'=>'no'], ['%s','%s','%s']);
      }
      wp_cache_delete($opt_key, 'options');
      $cutoff = wp_date('Ymd', time() - 7*DAY_IN_SECONDS);
      $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'tdrt_shows_%' AND SUBSTRING(option_name, 12, 8) < %s", $cutoff));
      return ['ok' => true, 'date' => $date, 'counts' => array_map('count', $out)];
    },
  ]);
});

// REST: /tdr-today/v1/park-hours — accepts {tdl:{open,close},tds:{open,close},date:Ymd}
// Used by fetcher.py to populate today's official park hours (since calendar.html is Akamai-protected).
add_action('rest_api_init', function(){
  register_rest_route('tdr-today/v1', '/park-hours', [
    'methods' => 'POST',
    'permission_callback' => function($req){
      $token = (string) $req->get_header('x-tdr-token');
      $expected = (string) get_option('tdr_mon_ingest_token', '');
      return $token !== '' && $expected !== '' && hash_equals($expected, $token);
    },
    'callback' => function($req){
      $body = $req->get_json_params();
      if(!is_array($body)) return new WP_Error('bad_json', 'expected JSON', ['status'=>400]);
      $date = isset($body['date']) ? preg_replace('/[^0-9]/', '', (string)$body['date']) : wp_date('Ymd');
      if(strlen($date) !== 8) return new WP_Error('bad_date', 'date must be Ymd', ['status'=>400]);
      $out = [];
      foreach(['tdl','tds'] as $park){
        if(isset($body[$park]['open']) && isset($body[$park]['close'])){
          $out[$park] = [
            'open' => (string) $body[$park]['open'],
            'close' => (string) $body[$park]['close'],
          ];
        }
      }
      if(empty($out)) return new WP_Error('empty', 'no park hours provided', ['status'=>400]);
      // Direct SQL upsert: update_option silently fails on this host (object cache quirk)
      $opt_key = 'tdrt_park_hours_'.$date;
      $serialized = serialize($out);
      global $wpdb;
      $existing = $wpdb->get_var($wpdb->prepare("SELECT option_id FROM {$wpdb->options} WHERE option_name = %s", $opt_key));
      if($existing){
        $wpdb->update($wpdb->options, ['option_value'=>$serialized,'autoload'=>'no'], ['option_name'=>$opt_key], ['%s','%s'], ['%s']);
      } else {
        $wpdb->insert($wpdb->options, ['option_name'=>$opt_key,'option_value'=>$serialized,'autoload'=>'no'], ['%s','%s','%s']);
      }
      wp_cache_delete($opt_key, 'options');
      // Auto-clean: delete park hours options older than 7 days. Prefix "tdrt_park_hours_" is 16 chars → YMD starts at MySQL position 17.
      $cutoff = wp_date('Ymd', time() - 7*DAY_IN_SECONDS);
      $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'tdrt_park_hours_%' AND SUBSTRING(option_name, 17, 8) < %s", $cutoff));
      delete_transient('tdrt_cal');
      return ['ok' => true, 'date' => $date, 'stored' => $out];
    },
  ]);
});

// REST: /tdr-today/v1/ticket-price — accepts {date:Ymd, tdl:{adult,junior,child,note}, tds:{...}}
// Used by fetcher.py to populate today's official 1-day passport prices per park.
add_action('rest_api_init', function(){
  register_rest_route('tdr-today/v1', '/ticket-price', [
    'methods' => 'POST',
    'permission_callback' => function($req){
      $token = (string) $req->get_header('x-tdr-token');
      $expected = (string) get_option('tdr_mon_ingest_token', '');
      return $token !== '' && $expected !== '' && hash_equals($expected, $token);
    },
    'callback' => function($req){
      $body = $req->get_json_params();
      if(!is_array($body)) return new WP_Error('bad_json', 'expected JSON', ['status'=>400]);
      $date = isset($body['date']) ? preg_replace('/[^0-9]/', '', (string)$body['date']) : wp_date('Ymd');
      if(strlen($date) !== 8) return new WP_Error('bad_date', 'date must be Ymd', ['status'=>400]);
      $out = [];
      foreach(['tdl','tds'] as $park){
        if(isset($body[$park]) && is_array($body[$park])){
          $row = [];
          foreach(['adult','junior','child'] as $k){
            if(isset($body[$park][$k]) && is_numeric($body[$park][$k])) $row[$k] = (int)$body[$park][$k];
          }
          if(isset($body[$park]['note'])) $row['note'] = (string)$body[$park]['note'];
          // v1.5.5: 全券種の今日価格 (general/shougai/hopper/fanfunful/shutoken/weeknight/early_evening)
          if(isset($body[$park]['types']) && is_array($body[$park]['types'])){
            $types = [];
            foreach($body[$park]['types'] as $type_key => $prices){
              if(!is_string($type_key) || !is_array($prices)) continue;
              $sanitized_key = preg_replace('/[^a-z_]/', '', strtolower($type_key));
              if($sanitized_key === '') continue;
              $type_row = [];
              foreach(['adult','junior','child'] as $cat){
                if(isset($prices[$cat]) && is_numeric($prices[$cat])) $type_row[$cat] = (int)$prices[$cat];
              }
              if(!empty($type_row)) $types[$sanitized_key] = $type_row;
            }
            if(!empty($types)) $row['types'] = $types;
          }
          if(isset($body[$park]['sales_snapshot'])){
            $row['sales_saved']=tdrt_store_ticket_sales($park,$body[$park]['sales_snapshot']);
          }
          if(!empty($row)) $out[$park] = $row;
        }
      }
      if(empty($out)) return new WP_Error('empty', 'no ticket prices provided', ['status'=>400]);
      $opt_key = 'tdrt_ticket_price_'.$date;
      $serialized = serialize($out);
      global $wpdb;
      $existing = $wpdb->get_var($wpdb->prepare("SELECT option_id FROM {$wpdb->options} WHERE option_name = %s", $opt_key));
      if($existing){
        $wpdb->update($wpdb->options, ['option_value'=>$serialized,'autoload'=>'no'], ['option_name'=>$opt_key], ['%s','%s'], ['%s']);
      } else {
        $wpdb->insert($wpdb->options, ['option_name'=>$opt_key,'option_value'=>$serialized,'autoload'=>'no'], ['%s','%s','%s']);
      }
      wp_cache_delete($opt_key, 'options');
      $cutoff = wp_date('Ymd', time() - 7*DAY_IN_SECONDS);
      $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE 'tdrt_ticket_price_%' AND SUBSTRING(option_name, 19, 8) < %s", $cutoff));
      return ['ok' => true, 'date' => $date, 'stored' => $out];
    },
  ]);
});

// REST: /tdr-today/v1/debug-state — admin only. Reveals current option keys for diagnosing
// missing displays (e.g. shows / park hours / ticket prices not appearing on /today-tdl/).
add_action('rest_api_init', function(){
  register_rest_route('tdr-today/v1', '/debug-state', [
    'methods' => 'GET',
    'permission_callback' => function(){ return current_user_can('manage_options'); },
    'callback' => function(){
      $today_ymd = wp_date('Ymd');
      global $wpdb;
      $patterns = ['tdrt_shows_%', 'tdrt_park_hours_%', 'tdrt_ticket_price_%'];
      $found = [];
      foreach($patterns as $pat){
        $rows = $wpdb->get_results($wpdb->prepare(
          "SELECT option_name, LENGTH(option_value) AS len FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_name DESC LIMIT 10",
          $pat));
        $found[$pat] = array_map(fn($r) => ['name'=>$r->option_name, 'bytes'=>(int)$r->len], $rows);
      }
      // pass_events for today (TDL/TDS) — for diagnosing 発券開始 anomalies
      $today_dash = wp_date('Y-m-d');
      $ptbl = tdrt_pass_table();
      $pass_today = $wpdb->get_results($wpdb->prepare(
        "SELECT park, attr_name, pass_type, event, event_date, event_time FROM {$ptbl} "
        ."WHERE event_date IN (%s, DATE_SUB(%s, INTERVAL 1 DAY)) ORDER BY event_time DESC LIMIT 60",
        $today_dash, $today_dash));
      $realtime_last = (int) get_option('tdrt_realtime_last', 0);
      $tdl_snap = get_option('tdrt_realtime_tdl', []);
      $tds_snap = get_option('tdrt_realtime_tds', []);
      $tdl_snap_count = is_array($tdl_snap) ? count($tdl_snap) : 0;
      $tds_snap_count = is_array($tds_snap) ? count($tds_snap) : 0;
      $tdl_snap_sample = is_array($tdl_snap) ? array_slice($tdl_snap, 0, 3) : [];
      return [
        'wait_sources' => array_map(function($p){$v=get_option('tdrt_waits_'.$p,[]);return ['source'=>$v['source']??null,'fetched_at'=>isset($v['ts'])?wp_date('Y-m-d H:i:s',(int)$v['ts']):null,'fresh_count'=>count(tdrt_fresh_waits($v,time()))];},['tdl','tds']),
        'verified_waits_from' => tdrt_verified_from(),
        'greeting_fetch' => get_option('tdrt_greet_fetch_last',[]),
        'wait_history_counts' => $wpdb->get_results("SELECT park,COUNT(*) AS n,MIN(recorded_at) AS first_at,MAX(recorded_at) AS last_at FROM ".tdrt_table()." GROUP BY park",ARRAY_A),
        'today' => $today_ymd,
        'now' => wp_date('Y-m-d H:i:s'),
        'wp_tz' => wp_timezone_string(),
        'current_time_mysql' => current_time('mysql'),
        'current_time_mysql_gmt' => current_time('mysql', 1),
        'realtime_last_ts' => $realtime_last,
        'realtime_last_dt' => $realtime_last ? wp_date('Y-m-d H:i:s', $realtime_last) : null,
        'snap_counts' => ['tdl'=>$tdl_snap_count, 'tds'=>$tds_snap_count],
        'tdl_snap_sample' => $tdl_snap_sample,
        'pass_events_recent' => $pass_today,
        'options' => $found,
        'shows_today' => get_option('tdrt_shows_'.$today_ymd, []),
        'hours_today' => get_option('tdrt_park_hours_'.$today_ymd, []),
        'ticket_today' => get_option('tdrt_ticket_price_'.$today_ymd, []),
      ];
    },
  ]);
});

// ===== 301リダイレクト（ページ統合） =====
// 旧ホテル空室比較ページ → お得情報ハブ（2026-07-08統合。GSC6ヶ月クリック0のため役割をハブへ集約）
add_action('template_redirect', function(){
  $path = trim((string)parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/');
  $map = [
    'disney-hotel-vacancy-compare-2' => 'https://yasushi-duffy.com/disney-otoku-sale/',
    'disney-hotel-vacancy-compare'   => 'https://yasushi-duffy.com/disney-otoku-sale/',
  ];
  if (isset($map[$path])) { wp_redirect($map[$path], 301); exit; }
});

// Public, read-only live availability; separate from page/cache and legacy price ingest.
add_action('rest_api_init',function(){
  register_rest_route('tdr-today/v1','/ticket-sales',[
    'methods'=>'GET','permission_callback'=>'__return_true',
    'callback'=>function($request){
      $park=(string)$request->get_param('park');
      if(!in_array($park,['tdl','tds'],true)) return new WP_Error('bad_park','invalid park',['status'=>400]);
      $html=tdrt_render_ticket_sales($park);
      $snapshot=tdrt_ticket_sales_snapshot();
      $response=new WP_REST_Response(['date'=>wp_date('Ymd'),'verified'=>isset($snapshot['parks'][$park])&&!empty($snapshot['parks'][$park]['fresh']),
        'has_snapshot'=>isset($snapshot['parks'][$park]),'fresh'=>!empty($snapshot['parks'][$park]['fresh']),
        'checked_at'=>$snapshot['parks'][$park]['checked_at'] ?? null,'html'=>$html],200);
      $response->header('Cache-Control','no-store, no-cache, must-revalidate, max-age=0');
      return $response;
    }
  ]);
});

add_action('rest_api_init',function(){
  register_rest_route('tdr-today/v1','/ticket-sales',[
    'methods'=>'POST','permission_callback'=>function(){return current_user_can('manage_options');},
    'callback'=>function($request){
      $body=$request->get_json_params(); $park=(string)($body['park'] ?? '');
      if(!tdrt_store_ticket_sales($park,$body)) return new WP_Error('invalid_sales','invalid or stale sale snapshot',['status'=>400]);
      return ['ok'=>true,'park'=>$park,'count'=>count($body['items']),'checked_at'=>$body['checked_at']];
    }
  ]);
});


// Read-only, cache-independent pass observations. No ingest credentials are exposed.
add_action('rest_api_init',function(){
  register_rest_route('tdr-today/v1','/pass-status',[
    'methods'=>'GET','permission_callback'=>'__return_true',
    'callback'=>function($request){
      $park=(string)$request->get_param('park');
      if(!in_array($park,['tdl','tds'],true))return new WP_Error('bad_park','invalid park',['status'=>400]);
      $last=(int)get_option('tdrt_realtime_last_'.$park,get_option('tdrt_realtime_last',0));
      $today=$last && $last<=time()+60 && wp_date('Ymd',$last)===wp_date('Ymd');
      $r=new WP_REST_Response(['date'=>wp_date('Ymd'),'checked_at'=>$today?$last:null,'fresh'=>$today&&time()-$last<=1200,'html'=>tdrt_render_pass_today(['park'=>$park])],200);
      $r->header('Cache-Control','no-store, no-cache, must-revalidate, max-age=0');return $r;
    }
  ]);
});
