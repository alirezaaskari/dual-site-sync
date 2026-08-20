<?php
/**
 * پاک شدن کش بعد از واردات.
 *
 * اجرا:  php tests/test-cache-purge.php
 */

define('ABSPATH', '/fake/');
define('DSS_VERSION', '2.0.0');

/* ---------- شبیه‌ساز حداقلی ---------- */
$GLOBALS['H'] = [];
$GLOBALS['CLEANED'] = [];
$GLOBALS['WC_TRANSIENTS'] = [];
$GLOBALS['LOGS'] = [];

function add_action($t,$f,$p=10,$a=1){ $GLOBALS['H'][$t][]=$f; }
function add_filter($t,$f,$p=10,$a=1){ $GLOBALS['H'][$t][]=$f; }
function do_action($t){ $args=array_slice(func_get_args(),1);
    foreach($GLOBALS['H'][$t]??[] as $f) call_user_func_array($f,$args); }
function apply_filters($t,$v){ $args=array_slice(func_get_args(),2);
    foreach($GLOBALS['H'][$t]??[] as $f) $v=call_user_func_array($f,array_merge([$v],$args));
    return $v; }
function has_action($t,$f=false){ return !empty($GLOBALS['H'][$t]); }
function absint($v){ return abs((int)$v); }
function clean_post_cache($id){ $GLOBALS['CLEANED'][] = (int) $id; }
function wc_delete_product_transients($id){ $GLOBALS['WC_TRANSIENTS'][] = (int) $id; }
function get_permalink($id){ return 'https://example.com/?p=' . (int) $id; }
function wp_get_post_parent_id($id){ return $GLOBALS['PARENTS'][$id] ?? 0; }

class DSS_Logger {
    public static function log($level,$message,$context=[]){ $GLOBALS['LOGS'][] = compact('level','message','context'); }
    public static function warning($m,$c=[]){}
    public static function error($m,$c=[]){}
}

require __DIR__ . '/../flatsome-child/inc/dual-site-sync/includes/class-dss-cache.php';

$pass = 0; $fail = 0;
function line($label,$ok,$extra=''){
    global $pass,$fail; $ok ? $pass++ : $fail++;
    printf("  %-52s %s %s\n", $label, $ok ? '✔' : '✘ FAIL', $extra);
}

echo "\n########## پاک‌سازی پایه ##########\n";
$layers = DSS_Cache::purge_product(123);
line('کش پست وردپرس پاک شد', $GLOBALS['CLEANED'] === [123]);
line('ترنزینت‌های ووکامرس پاک شدند', $GLOBALS['WC_TRANSIENTS'] === [123]);
line('لایه‌ها گزارش شدند', in_array('WooCommerce', $layers, true) && in_array('WordPress', $layers, true));
line('در لاگ افزونه ثبت شد',
     count(array_filter($GLOBALS['LOGS'], fn($l)=>mb_strpos($l['message'],'کش') !== false)) === 1);

echo "\n########## ورودی نامعتبر ##########\n";
$GLOBALS['CLEANED'] = [];
line('شناسه‌ی صفر کاری نمی‌کند', DSS_Cache::purge_product(0) === [] && $GLOBALS['CLEANED'] === []);
line('شناسه‌ی غیرعددی هم', DSS_Cache::purge_product('abc') === []);

echo "\n########## قلاب لایه‌های دیگر ##########\n";
$GLOBALS['CDN'] = [];
add_action('dss_purge_cache', function($id){ $GLOBALS['CDN'][] = $id; });
add_filter('dss_purged_cache_layers', fn($l,$id) => array_merge($l, ['Cloudflare']));
$layers = DSS_Cache::purge_product(55);
line('قلاب dss_purge_cache صدا زده شد', $GLOBALS['CDN'] === [55]);
line('فیلتر لایه‌ها اعمال شد', in_array('Cloudflare', $layers, true));
line('لایه‌ی تکراری دوبار نمی‌آید', count($layers) === count(array_unique($layers)));

echo "\n########## واریشن: والد هم پاک می‌شود ##########\n";
$GLOBALS['PARENTS'] = [900 => 800];
$GLOBALS['CLEANED'] = [];
DSS_Cache::purge_product_tree(900);
line('هم واریشن و هم والد پاک شدند',
     $GLOBALS['CLEANED'] === [900, 800], '→ ' . json_encode($GLOBALS['CLEANED']));

$GLOBALS['CLEANED'] = [];
DSS_Cache::purge_product_tree(800);
line('محصول بدون والد فقط یک بار', $GLOBALS['CLEANED'] === [800]);

echo "\n########## افزونه‌ی کش نصب‌شده ##########\n";
$GLOBALS['ROCKET'] = [];
function rocket_clean_post($id){ $GLOBALS['ROCKET'][] = (int) $id; }
$layers = DSS_Cache::purge_product(77);
line('WP Rocket پاک شد', $GLOBALS['ROCKET'] === [77]);
line('و در فهرست لایه‌ها آمد', in_array('WP Rocket', $layers, true));

echo "\n########## وارد کردن، کش را پاک می‌کند ##########\n";
$src = file_get_contents(__DIR__ . '/../flatsome-child/inc/dual-site-sync/includes/class-dss-importer.php');
line('importer بعد از ذخیره کش را پاک می‌کند',
     mb_strpos($src, 'DSS_Cache::purge_product( $product_id );') !== false);
line('جای درستی صدا زده شده (بعد از ترم‌ها و تصاویر)',
     mb_strpos($src, 'DSS_Cache::purge_product') > mb_strpos($src, 'apply_images'));

printf("\n  پاس: %d   شکست: %d\n\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
