<?php
// Exercise production functions without booting WordPress or touching a database.
class WP_Error { public function __construct(public $code, public $message='', public $data=[]){} }
function is_wp_error($v){return $v instanceof WP_Error;}
function sanitize_text_field($v){return trim(strip_tags((string)$v));}
function esc_url_raw($v){return (string)$v;}
function wp_parse_url($v,$part){return parse_url($v,$part);}
function get_option($k,$default=[]){global $options;return $options[$k]??$default;}
function wp_date($format,$ts=null){return date($format,$ts??time());}
define('DAY_IN_SECONDS',86400);
$code=file_get_contents(__DIR__.'/../wp-plugin/tdr-today/tdr-today.php');
$wanted=['tdrt_validate_closure_items','tdrt_parse_stop_period','tdrt_closure_health','tdrt_items_by_src'];
$tokens=token_get_all($code);
for($i=0;$i<count($tokens);$i++){
 if(!is_array($tokens[$i])||$tokens[$i][0]!==T_FUNCTION)continue;
 $name='';for($j=$i+1;$j<count($tokens);$j++){if(is_array($tokens[$j])&&$tokens[$j][0]===T_STRING){$name=$tokens[$j][1];break;}if($tokens[$j]==='(')break;}
 if(!in_array($name,$wanted,true))continue;
 $function='';$depth=0;$started=false;
 for($j=$i;$j<count($tokens);$j++){
  $t=$tokens[$j];$function.=is_array($t)?$t[1]:$t;
  if(is_array($t)&&in_array($t[0],[T_CURLY_OPEN,T_DOLLAR_OPEN_CURLY_BRACES],true)){$depth++;}elseif($t==='{'){$depth++;$started=true;}elseif($t==='}'){$depth--;if($started&&$depth===0)break;}
 }
 eval($function);
}
function check($yes,$message){if(!$yes)throw new Exception($message);}
$fixtures=json_decode(file_get_contents(__DIR__.'/official-closures.json'),true);
$options=[];$wpdb=null;
foreach(['tdl','tds'] as $park){
 $items=tdrt_validate_closure_items($park,$fixtures[$park]);
 check(!is_wp_error($items),"official $park validation");
 $options['tdrt_closures_'.$park]=['checked_at'=>time(),'items'=>$items];
 check(tdrt_closure_health($park)['healthy'],"fresh $park");
 $selected=tdrt_items_by_src('stop_'.$park,300);
 check(count($selected)===count($fixtures[$park]),"full $park snapshot");
}
$indiana=array_values(array_filter($fixtures['tds'],fn($v)=>str_contains($v['title'],'インディ・ジョーンズ')))[0];
$unknown=$indiana;$unknown['title']=str_replace('2026/11/29','未定',$unknown['title']);
$a=tdrt_validate_closure_items('tds',[$unknown]);$b=tdrt_validate_closure_items('tds',[$indiana]);
check($a[0]['_id']===$b[0]['_id'],'stable facility id when end date changes');
check(is_wp_error(tdrt_validate_closure_items('tds',[])),'reject empty list');
check(is_wp_error(tdrt_validate_closure_items('tds',[$indiana,$indiana])),'reject duplicate facility');
$bad=$indiana;$bad['url']='https://evil.example/tds/attraction/detail/222/';
check(is_wp_error(tdrt_validate_closure_items('tds',[$bad])),'reject nonofficial host');
$bad=$indiana;$bad['title']=str_replace('2026/11/29','2026/2/30',$bad['title']);
check(is_wp_error(tdrt_validate_closure_items('tds',[$bad])),'reject invalid calendar date');
$options['tdrt_closures_tds']['checked_at']=time()-86401;
check(!tdrt_closure_health('tds')['healthy'],'detect stale snapshots');
unset($options['tdrt_closures_tdl']);
check(!tdrt_closure_health('tdl')['healthy'],'detect missing snapshot');
echo "PHP closure validation/snapshot/health tests passed\n";
