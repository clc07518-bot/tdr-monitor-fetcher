<?php
/*
Plugin Name: TDR Today
Description: 今日のディズニー情報ハブ + 待ち時間ヒートマップ + DPA/PP発券終了トラッカー + 過去データ集計。Shortcode [tdr_today_hub], [tdr_today_heatmap], [tdr_pass_today], [tdr_history], [tdr_pass_history].
Version: 1.3.3
Author: rin
*/
if(!defined('ABSPATH'))exit;

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
  if(get_option('tdrt_v','0')!=='1.3.3'){tdrt_install();update_option('tdrt_v','1.3.3',false);}
});

// DWR plugin の名称が公式表記と微妙にズレているのを補正するマップ。
// 公式: https://www.tokyodisneyresort.jp/tdl/attraction.html / /tds/attraction.html
// 公式正式名称は curly double quote (U+201C / U+201D) を使う点に注意。
// キーは DWR が保持している name(誤・略称・英名・straight quote 等)、値は公式の正式名称。
function tdrt_fix_name($n){
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
  return $s;
});
add_action('init',function(){
  if(!wp_next_scheduled('tdrt_snapshot'))wp_schedule_event(time()+30,'tdrt_15min','tdrt_snapshot');
});
register_deactivation_hook(__FILE__,function(){
  $t=wp_next_scheduled('tdrt_snapshot');if($t)wp_unschedule_event($t,'tdrt_snapshot');
});

function tdrt_slot($ts=null){
  if($ts===null)$ts=current_time('timestamp');
  $m=(int)date('i',$ts);$r=$m<30?0:30;
  return (int)date('YmdH',$ts)*100+$r;
}
function tdrt_aid($en,$jp){return substr(md5($en!==''?$en:$jp),0,16);}

function tdrt_snapshot(){
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
    $base_park = (substr($park,-2)==='_g') ? substr($park,0,3) : $park;
    if(!tdrt_is_open($base_park))continue; // 閉園中は記録しない
    if($opt_key === null){
      $rows = tdrt_wait_data_raw($base_park);
    } else {
      $rows = get_option($opt_key,[]);
    }
    if(!is_array($rows))continue;
    foreach($rows as $r){
      $name=isset($r['name'])?(string)$r['name']:'';
      $en=isset($r['name_en'])?(string)$r['name_en']:'';
      if($name===''&&$en==='')continue;
      $aid=tdrt_aid($en,$name);
      $name=tdrt_fix_name($name); // 公式表記へ正規化してから保存
      $w=(isset($r['wait_min'])&&$r['wait_min']!==''&&$r['wait_min']!==null)?(int)$r['wait_min']:null;
      $st=isset($r['status'])?(string)$r['status']:'';
      $cols='park,attr_id,attr_name,wait_min,status,recorded_at,slot_key';
      $ph=$w===null?'%s,%s,%s,NULL,%s,%s,%d':'%s,%s,%s,%d,%s,%s,%d';
      $args=$w===null?[$park,$aid,$name?:$en,$st,$now,$slot]:[$park,$aid,$name?:$en,$w,$st,$now,$slot];
      $wpdb->query($wpdb->prepare("REPLACE INTO {$tbl} ({$cols}) VALUES ({$ph})",$args));
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
function tdrt_is_open($park){
  $now = tdrt_t2m(current_time('H:i'));
  $h = tdrt_hours($park);
  if($h && isset($h['open']) && isset($h['close'])){
    $o = tdrt_t2m($h['open']);
    $cl = tdrt_t2m($h['close']);
    return $o >= 0 && $cl >= 0 && $now >= $o && $now < $cl;
  }
  return $now >= 480 && $now < 1290; // 08:00 to 21:30
}

function tdrt_items_by_src($srcs,$lim=100){
  global $wpdb;
  if(!is_array($srcs))$srcs=[$srcs];
  $rows=$wpdb->get_results("SELECT option_id,option_name,option_value FROM {$wpdb->options} WHERE option_name LIKE 'tdr_mon_item_%' ORDER BY option_id DESC LIMIT 200");
  $out=[];
  foreach($rows as $r){
    $v=maybe_unserialize($r->option_value);
    if(!is_array($v)&&is_string($r->option_value)){$d=json_decode($r->option_value,true);if(is_array($d))$v=$d;}
    if(!is_array($v))continue;
    if(!in_array($v['source']??'',$srcs,true))continue;
    $v['_id']=substr($r->option_name,strlen('tdr_mon_item_'));
    $out[]=$v;
    if(count($out)>=$lim)break;
  }
  return $out;
}

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
  return 'attraction';
}
function tdrt_filter_stopped_today($items){
  $out=['attraction'=>[],'show'=>[],'parade'=>[]];
  foreach($items as $s){
    $title=$s['title']??'';
    if(!tdrt_is_stopped_today($title))continue;
    $cat=tdrt_categorize_stop($title);
    $out[$cat][]=$s;
  }
  return $out;
}

function tdrt_get_shows($park){
  $today_ymd = wp_date('Ymd');
  $stored = get_option('tdrt_shows_'.$today_ymd, null);
  if(!is_array($stored)) return [];
  return isset($stored[$park]) && is_array($stored[$park]) ? $stored[$park] : [];
}

// 優先順位:
//   1. tdrt_waits_<park> (我々が /realtime endpoint で取得・60分以内のもの) ← DWR が止まっても動く
//   2. dwr_latest_<park> (DWR plugin の出力)
//   3. dwr_prev_<park>   (DWR の前回値)
function tdrt_wait_data_raw($park){
  $own = get_option('tdrt_waits_'.$park, null);
  if(is_array($own) && isset($own['rows']) && isset($own['ts'])){
    if((int)$own['ts'] > (time() - 3600) && is_array($own['rows']) && !empty($own['rows'])){
      foreach($own['rows'] as $r){
        if(($r['status'] ?? '') === 'operating' && isset($r['wait_min']) && $r['wait_min'] !== '' && $r['wait_min'] !== null){
          return $own['rows'];
        }
      }
    }
  }
  $latest = get_option('dwr_latest_'.$park, []);
  if(is_array($latest) && !empty($latest)){
    foreach($latest as $r){
      if(($r['status'] ?? '') === 'operating' && isset($r['wait_min']) && $r['wait_min'] !== '' && $r['wait_min'] !== null){
        return $latest;
      }
    }
  }
  $prev = get_option('dwr_prev_'.$park, []);
  return is_array($prev) ? $prev : [];
}
function tdrt_wait_data($park){
  $rows = tdrt_wait_data_raw($park);
  if(!is_array($rows)) return [];
  foreach($rows as &$r){
    if(isset($r['name'])) $r['name'] = tdrt_fix_name($r['name']);
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
  $rows = get_option('tdrt_greet_'.$park, []);
  if(!is_array($rows)) return [];
  foreach($rows as &$r){
    if(isset($r['name'])) $r['name'] = tdrt_fix_name($r['name']);
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

function tdrt_weather(){
  $c=get_transient('tdrt_w');if($c!==false)return $c;
  $u='https://api.open-meteo.com/v1/forecast?latitude=35.6329&longitude=139.8804&daily=weathercode,temperature_2m_max,temperature_2m_min,precipitation_probability_max&timezone=Asia%2FTokyo&forecast_days=1';
  $r=wp_remote_get($u,['timeout'=>8]);if(is_wp_error($r))return null;
  $d=json_decode(wp_remote_retrieve_body($r),true);if(!is_array($d))return null;
  set_transient('tdrt_w',$d,HOUR_IN_SECONDS);return $d;
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
  $stops_total=count($stops_today['attraction'])+count($stops_today['show'])+count($stops_today['parade']);
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
<div class="c"><div class="cl">天気</div><?php if($w&&isset($w['daily']['weathercode'][0])):$wc=(int)$w['daily']['weathercode'][0];$tx=round($w['daily']['temperature_2m_max'][0]);$tn=round($w['daily']['temperature_2m_min'][0]);$pp=(int)$w['daily']['precipitation_probability_max'][0];?><div class="cv"><?php echo tdrt_we($wc);?> <?php echo esc_html(tdrt_wl($wc));?></div><div class="cs"><?php echo "{$tn}℃/{$tx}℃　降水{$pp}%";?></div><?php else:?><div class="cv">—</div><?php endif;?></div>
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
<p style="color:#aaa;text-align:center;padding:12px;background:#f8f8f8;border-radius:8px;font-size:12px">本日のグリーティング情報を取得中…（10分前後で反映されます）</p>
<?php endif; ?>

<h3>🚫 今日の休止施設 (<?php echo $stops_total;?>件)</h3>
<?php
// TDL は ショー＋パレードを「ショー・パレード」として合体表示。TDS は ショーのみ。
if($park==='tdl'){
  $sp_merged = array_merge($stops_today['show'], $stops_today['parade']);
  $cats = ['attraction'=>['🎢','アトラクション',$stops_today['attraction']], 'show_parade'=>['🎭','ショー・パレード',$sp_merged]];
} else {
  $cats = ['attraction'=>['🎢','アトラクション',$stops_today['attraction']], 'show'=>['🎭','ショー',$stops_today['show']]];
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

<div class="cta">
<a class="cp" href="<?php echo home_url('/calendar/');?>">📅 混雑予想</a>
<a href="<?php echo home_url('/'.$park.'-wait-ranking/');?>">⏱ 待ち時間</a>
<a href="<?php echo home_url('/dpa_soldout_time/');?>">🎫 DPA</a>
</div>
</div>
<?php return ob_get_clean();
}
add_shortcode('tdr_today_hub','tdrt_hub');

function tdrt_heatmap($a){
  global $wpdb;
  $a=shortcode_atts(['park'=>'tdl','type'=>'attr'],$a,'tdr_today_heatmap');
  $base=strtolower($a['park'])==='tds'?'tds':'tdl';
  // type='greet' なら park='tdl_g'/'tds_g'、'attr' (default) なら park='tdl'/'tds'
  $park = ($a['type']==='greet') ? $base.'_g' : $base;
  $tbl=tdrt_table();$today=wp_date('Y-m-d');
  $rows=$wpdb->get_results($wpdb->prepare("SELECT attr_id,attr_name,wait_min,status,slot_key FROM {$tbl} WHERE park=%s AND DATE(recorded_at)=%s ORDER BY slot_key ASC",$park,$today));
  if(!$rows)return '<p style="text-align:center;color:#aaa;padding:20px">本日のデータはまだありません。15分ごとに自動収集中。</p>';
  $m=[];$nm=[];$sl=[];$tot=[];
  foreach($rows as $r){$m[$r->attr_id][(int)$r->slot_key]=['w'=>$r->wait_min===null?null:(int)$r->wait_min,'s'=>$r->status];$nm[$r->attr_id]=tdrt_fix_name($r->attr_name);$sl[(int)$r->slot_key]=1;if($r->wait_min!==null)$tot[$r->attr_id]=($tot[$r->attr_id]??0)+(int)$r->wait_min;}
  $ks=array_keys($sl);sort($ks);arsort($tot);
  $ord=array_merge(array_keys($tot),array_diff(array_keys($nm),array_keys($tot)));
  $cls=function($w){if($w===null)return 'h0';if($w>=180)return 'h6';if($w>=120)return 'h5';if($w>=80)return 'h4';if($w>=50)return 'h3';if($w>=20)return 'h2';return 'h1';};
  ob_start();?>
<style>.thm{overflow-x:auto;margin:16px 0;-webkit-overflow-scrolling:touch}
.thm table{border-collapse:collapse;font-size:11px;background:#fff}
.thm th,.thm td{border:1px solid #ddd;text-align:center;padding:4px 6px;min-width:36px;white-space:nowrap}
.thm thead th{background:#2a2a3a;color:#fff;writing-mode:vertical-rl;text-orientation:mixed;padding:8px 4px;font-weight:600;max-width:32px;height:120px;line-height:1.15}
.thm thead th:first-child{writing-mode:initial;text-orientation:initial;max-width:none;min-width:50px;height:auto}
.thm tbody th{background:#f5f5f5;text-align:right;padding-right:6px;font-weight:500;position:sticky;left:0}
.h0{background:#fafafa;color:#ccc}.h1{background:#fff}.h2{background:#e0f2ff}.h3{background:#fff8b3}.h4{background:#ffd699}.h5{background:#ff9999}.h6{background:#cc4444;color:#fff;font-weight:600}
.thml{font-size:11px;color:#666;margin-bottom:6px}.thml span{display:inline-block;padding:2px 8px;border-radius:3px;margin-right:4px;border:1px solid #ddd}</style>
<div class="thml"><span class="h1">~20分</span><span class="h2">~50分</span><span class="h3">~80分</span><span class="h4">~120分</span><span class="h5">~180分</span><span class="h6">180+</span></div>
<div class="thm"><table><thead><tr><th>時刻</th><?php foreach($ord as $aid):?><th><?php echo esc_html($nm[$aid]);?></th><?php endforeach;?></tr></thead><tbody>
<?php foreach($ks as $sk):$hh=(int)substr((string)$sk,8,2);$mm=(int)substr((string)$sk,10,2);?>
<tr><th><?php printf('%02d:%02d',$hh,$mm);?></th>
<?php foreach($ord as $aid):$cell=$m[$aid][$sk]??null;$w=$cell['w']??null;$s=$cell['s']??'';$c=$cls($w);$d=$w===null?'-':($s&&$s!=='operating'?'休':$w);?>
<td class="<?php echo $c;?>"><?php echo esc_html($d);?></td>
<?php endforeach;?></tr>
<?php endforeach;?>
</tbody></table></div>
<?php return ob_get_clean();
}
add_shortcode('tdr_today_heatmap','tdrt_heatmap');

// ---------- DPA / プライオリティパス 発券タイムライン ----------
function tdrt_pass_today($a){
  global $wpdb;
  $a = shortcode_atts(['park'=>'tdl'], $a, 'tdr_pass_today');
  $park = strtolower($a['park'])==='tds'?'tds':'tdl';
  $pl = $park==='tdl'?'東京ディズニーランド':'東京ディズニーシー';
  $pc = $park==='tdl'?'#1d4f91':'#0a8aa6';
  $today = wp_date('Y-m-d');
  $ptbl = tdrt_pass_table();
  // 今日のイベントを取得
  $rows = $wpdb->get_results($wpdb->prepare(
    "SELECT attr_id,attr_name,pass_type,event,event_time FROM {$ptbl} "
    ."WHERE park=%s AND event_date=%s ORDER BY event_time ASC",
    $park, $today));
  // attr_id -> [type -> [start_time, end_time]]
  $by_attr = [];
  foreach($rows as $r){
    $aid = $r->attr_id;
    $by_attr[$aid]['name'] = tdrt_fix_name($r->attr_name);
    $by_attr[$aid][$r->pass_type][$r->event] = $r->event_time;
  }
  // 現在の状態
  $current = get_option('tdrt_realtime_'.$park, []);
  if(!is_array($current)) $current = [];
  $cur_idx = [];
  foreach($current as $c){ if(isset($c['name'])) $cur_idx[$c['name']] = $c; }

  $type_label = ['dpa'=>'DPA', 'pp'=>'PP', 'sbp'=>'SP'];
  $type_color = ['dpa'=>'#d63384', 'pp'=>'#0d6efd', 'sbp'=>'#198754'];

  ob_start();?>
<style>
.tpt{font-family:-apple-system,sans-serif}
.tpt h3{font-size:16px;margin:18px 0 10px;padding-left:10px;border-left:4px solid <?php echo $pc;?>}
.tpt table{width:100%;border-collapse:collapse;font-size:12px;background:#fff}
.tpt th,.tpt td{border:1px solid #e0e0e0;padding:6px 8px;text-align:left;vertical-align:middle}
.tpt thead th{background:#2a2a3a;color:#fff;font-weight:600;font-size:11px;text-align:center}
.tpt .pass{display:inline-block;padding:2px 6px;border-radius:3px;color:#fff;font-size:10px;font-weight:700;margin-right:4px}
.tpt .ended{opacity:0.5;text-decoration:line-through}
.tpt .live{background:#fff8b3}
.tpt .end-time{font-weight:700;color:#d0334d}
.tpt .start-time{color:#666;font-size:11px}
.tpt .none{color:#aaa;text-align:center;padding:20px}
.tpt .legend{font-size:11px;color:#666;margin:6px 0 12px}
.tpt .legend .pass{margin-right:4px}
</style>
<div class="tpt">
<h3>🎫 <?php echo esc_html($pl);?> パス発券状況</h3>
<div class="legend">
  <span class="pass" style="background:#d63384">DPA</span>ディズニー・プレミアアクセス（有料）
  <span class="pass" style="background:#0d6efd">PP</span>プライオリティパス（無料）
  <span class="pass" style="background:#198754">SP</span>スタンバイパス
</div>
<?php if(empty($by_attr)): ?>
<p class="none">本日のパス発券データはまだありません。<br><small>5〜30分間隔で自動収集中。</small></p>
<?php else: ?>
<table>
<thead><tr><th>アトラクション</th><th>種別</th><th>発券開始</th><th>発券終了</th><th>状況</th></tr></thead>
<tbody>
<?php
// ソート: 発券中(まだ終わってない)を上に、終わった順を時間降順で
$sorted = [];
foreach($by_attr as $aid=>$data){
  $name = $data['name'];
  foreach(['dpa','pp','sbp'] as $tp){
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
  $start_t = $row['start'] ? wp_date('H:i', strtotime($row['start'])) : '—';
  $end_t   = $row['end']   ? wp_date('H:i', strtotime($row['end']))   : ($row['live']?'—':'—');
  $live    = $row['live'];
?>
<tr class="<?php echo $live?'live':'ended';?>">
<td><?php echo esc_html($row['name']);?></td>
<td><span class="pass" style="background:<?php echo $type_color[$tp];?>"><?php echo $type_label[$tp];?></span></td>
<td class="start-time"><?php echo esc_html($start_t);?></td>
<td class="<?php echo (!$live && $row['end'])?'end-time':'';?>"><?php echo esc_html($end_t);?></td>
<td><?php echo $live?'🟢 発券中':'🔴 終了';?></td>
</tr>
<?php endforeach;?>
</tbody>
</table>
<?php endif;?>
<?php $last = (int) get_option('tdrt_realtime_last', 0); if($last):?>
<p style="font-size:11px;color:#888;margin-top:6px;text-align:right">最終更新: <?php echo esc_html(wp_date('H:i', $last));?></p>
<?php endif;?>
</div>
<?php return ob_get_clean();
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
        // Index prev by name for diff
        $prev_idx = [];
        foreach($prev as $p){ if(isset($p['name'])) $prev_idx[$p['name']] = $p; }
        // Diff
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
        $waits_payload = serialize(['rows'=>$waits_compat, 'ts'=>time()]);
        $waits_key = 'tdrt_waits_'.$park;
        $wexisting = $wpdb->get_var($wpdb->prepare("SELECT option_id FROM {$wpdb->options} WHERE option_name = %s", $waits_key));
        if($wexisting){
          $wpdb->update($wpdb->options, ['option_value'=>$waits_payload,'autoload'=>'no'], ['option_name'=>$waits_key], ['%s','%s'], ['%s']);
        } else {
          $wpdb->insert($wpdb->options, ['option_name'=>$waits_key,'option_value'=>$waits_payload,'autoload'=>'no'], ['%s','%s','%s']);
        }
        wp_cache_delete($waits_key, 'options');
        $stored[$park] = count($rows);
      }
      update_option('tdrt_realtime_last', time(), false);
      return ['ok'=>true, 'stored'=>$stored, 'events_logged'=>$events_logged, 'now'=>time()];
    },
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
