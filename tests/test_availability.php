<?php
// Exercise production renderers: stale same-day records remain dated history,
// yesterday/future records never become current, unknown is not sold out.
date_default_timezone_set('Asia/Tokyo');
$options=[];$open=true;
function get_option($k,$d=[]){global $options;return $options[$k]??$d;}
function wp_date($f,$t=null){return date($f,$t??time());}
function esc_html($s){return htmlspecialchars((string)$s,ENT_QUOTES);}
function esc_attr($s){return esc_html($s);}
function esc_url($s){return esc_html($s);}
function shortcode_atts($d,$a,$n=''){return array_merge($d,$a);}
function tdrt_fix_name($s){return $s;}
function tdrt_is_open($p){global $open;return $open;}
function tdrt_get_stopped_attraction_names(){return [];}
function tdrt_db_hi($v){return date('H:i',strtotime($v));}
class FakeDB {
 function prepare($s,...$a){return $s;}
 function get_results($s){return [(object)['attr_id'=>'x','attr_name'=>'テスト施設','pass_type'=>'dpa','event'=>'start','event_time'=>date('Y-m-d').' 09:00:00']];}
}
$wpdb=new FakeDB();
$tokens=token_get_all(file_get_contents(__DIR__.'/../wp-plugin/tdr-today/tdr-today.php'));
$wanted=['tdrt_ticket_sales_snapshot','tdrt_get_all_ticket_types','tdrt_render_ticket_sales','tdrt_render_pass_today'];
for($i=0;$i<count($tokens);$i++){
 if(!is_array($tokens[$i])||$tokens[$i][0]!==T_FUNCTION)continue;
 $name='';for($j=$i+1;$j<count($tokens);$j++){if(is_array($tokens[$j])&&$tokens[$j][0]===T_STRING){$name=$tokens[$j][1];break;}if($tokens[$j]==='(')break;}
 if(!in_array($name,$wanted,true))continue;
 $fn='';$depth=0;$started=false;
 for($j=$i;$j<count($tokens);$j++){$t=$tokens[$j];$fn.=is_array($t)?$t[1]:$t;
 if(is_array($t)&&in_array($t[0],[T_CURLY_OPEN,T_DOLLAR_OPEN_CURLY_BRACES],true))$depth++;elseif($t==='{'){$depth++;$started=true;}elseif($t==='}'){$depth--;if($started&&$depth===0)break;}}
 eval($fn);
}
function check($v,$m){if(!$v)throw new Exception($m);}
$key='tdrt_ticket_sales_v2_'.date('Ymd').'_tdl';
$row=['name'=>'期間限定チケット','badge'=>'期間限定','prices'=>['adult'=>6000],'available'=>true,'sale_status'=>'販売中','condition'=>'公式確認','link'=>'https://www.tokyodisneyresort.jp/ticket/index.html'];
$options[$key]=['date'=>date('Ymd'),'checked_at'=>time(),'items'=>[$row]];
check(tdrt_ticket_sales_snapshot()['parks']['tdl']['fresh'],'fresh observation');
check(str_contains(tdrt_render_ticket_sales('tdl'),'期間限定チケット'),'arbitrary official ticket retained');
$earlier=max(strtotime('today'),time()-7200);$options[$key]['checked_at']=$earlier;
if(time()-$earlier>1800){$html=tdrt_render_ticket_sales('tdl');check(str_contains($html,'更新が遅れています'),'stale explicit');check(str_contains($html,'最終確認時：販売中'),'historical status label');check(!tdrt_ticket_sales_snapshot()['parks']['tdl']['fresh'],'stale not fresh');}
$options[$key]['checked_at']=time()-86400;
check(!isset(tdrt_ticket_sales_snapshot()['parks']['tdl']),'yesterday suppressed');
$options[$key]['checked_at']=time()+100;
check(!isset(tdrt_ticket_sales_snapshot()['parks']['tdl']),'future rejected');
$options['tdrt_realtime_last']=time();$options['tdrt_realtime_last_tdl']=time();
$options['tdrt_realtime_tdl']=[['name'=>'テスト施設','has_dpa'=>false]];
$html=tdrt_render_pass_today(['park'=>'tdl']);
check(str_contains($html,'発行終了は未確認'),'missing end observation is not ended');
$options['tdrt_realtime_last_tdl']=$earlier;
if(time()-$earlier>1200)check(str_contains(tdrt_render_pass_today(['park'=>'tdl']),'現在の発行状況ではありません'),'park-specific stale warning');
$options['tdrt_realtime_last_tdl']=time()-86400;
check(str_contains(tdrt_render_pass_today(['park'=>'tdl']),'現在の発行状況は確認できていません'),'yesterday pass suppressed');
echo "availability freshness, midnight, per-park and unknown-state regression tests passed\n";
