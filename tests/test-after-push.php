<?php
/**
 * قلابِ dss_after_push — بعد از هر فرستادن، از هر جا.
 *
 * اجرا:  php tests/test-after-push.php
 */
define('ABSPATH', '/fake/');
define('DSS_VERSION', '2.0.0');

$H = [];
function add_action($t, $f, $p = 10, $a = 1) { $GLOBALS['H'][$t][] = [$f, $a]; }
function do_action($t, ...$args) {
    foreach ($GLOBALS['H'][$t] ?? [] as [$f, $a]) { call_user_func_array($f, array_slice($args, 0, $a)); }
}
function absint($v) { return abs((int) $v); }
function get_post_meta($id, $k, $s = false) { return $GLOBALS['META'][$id][$k] ?? ''; }
function update_post_meta($id, $k, $v) { $GLOBALS['META'][$id][$k] = $v; }
function wc_get_product($id) { return isset($GLOBALS['P'][$id]) ? (object) ['id' => $id] : false; }

class DSS_Config {
    public static function configuration_errors() { return $GLOBALS['ERRORS'] ?? []; }
    public static function target_key() { return 'siteB'; }
    public static function current_key() { return 'siteA'; }
}
class DSS_Exporter {
    public static function build($p, $m) { if (!empty($GLOBALS['BUILD_THROWS'])) { throw new RuntimeException('x'); } return ['mode' => $m]; }
    public static function mode_label($m) { return $m; }
}
class DSS_Client { public static function send($payload) { return $GLOBALS['SEND']; } }
class DSS_Importer {
    const META_REMOTE_ID = '_dss_remote_id';
    const META_CREATED_BY = '_dss_created_by';
    public static function link($a, $b, $c) {}
}
class DSS_Logger { public static function log($l, $m, $c = []) {} public static function error($m, $c = []) {} }

require __DIR__ . '/../flatsome-child/inc/dual-site-sync/includes/class-dss-ajax.php';

$pass = 0; $fail = 0;
function line($l, $ok, $x = '') {
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("  %-46s %s %s\n", $l, $ok ? '✔' : '✘ FAIL', $x);
}

$SEEN = [];
add_action('dss_after_push', function ($id, $mode, $result) {
    $GLOBALS['SEEN'][] = [$id, $mode, $result['success']];
}, 10, 3);

$P = [7 => true];

echo "\n########## dss_after_push ##########\n";

$SEND = ['success' => true, 'message' => 'ok', 'id' => 99];
DSS_Ajax::run(7, 'full');
line('بعد از فرستادنِ موفق صدا زده شد', $SEEN === [[7, 'full', true]], '→ ' . json_encode($SEEN));

$SEEN = [];
$SEND = ['success' => false, 'message' => 'نه', 'id' => 0];
DSS_Ajax::run(7, 'full_no_price');
line('بعد از شکست هم، با نتیجه‌اش', $SEEN === [[7, 'full_no_price', false]], '→ ' . json_encode($SEEN));

echo "\n  وقتی چیزی فرستاده نشده، صدا زده نمی‌شود:\n";
$SEEN = [];
$ERRORS = ['ناقص'];
DSS_Ajax::run(7, 'full');
line('پیکربندیِ ناقص', $SEEN === []);
$ERRORS = [];

DSS_Ajax::run(404, 'full');
line('محصولِ ناموجود', $SEEN === []);

$META[7]['_dss_remote_id'] = 99;
DSS_Ajax::run(7, 'create');
line('ایجادِ تکراری', $SEEN === []);

$BUILD_THROWS = true;
DSS_Ajax::run(7, 'full');
line('خطا در ساختِ بسته', $SEEN === []);

printf("\n  نتیجه: %d پاس، %d شکست\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
