<?php
/**
 * مسیر product-state — فقط خواندنی.
 *
 * اجرا:  php tests/test-product-state.php
 */

define('ABSPATH', '/fake/');
define('DSS_VERSION', '2.0.0');

/* ---------- شبیه‌ساز حداقلی ---------- */
$H = [];
function add_action($t,$f,$p=10,$a=1){ $GLOBALS['H'][$t][]=$f; }
function add_filter($t,$f,$p=10,$a=1){ $GLOBALS['H'][$t][]=$f; }
function do_action($t){ foreach($GLOBALS['H'][$t]??[] as $f) call_user_func($f); }
function apply_filters($t,$v){ return $v; }
function absint($v){ return abs((int)$v); }
function sanitize_text_field($v){ return trim(strip_tags((string)$v)); }
function get_transient($k){ return $GLOBALS['TR'][$k] ?? false; }
function set_transient($k,$v,$t=0){ $GLOBALS['TR'][$k]=$v; return true; }
function wp_strip_all_tags($s){ return strip_tags((string)$s); }
function register_rest_route($ns,$r,$a){ $GLOBALS['ROUTES'][$ns.$r]=$a; }
function hash_equals_polyfill($a,$b){ return hash_equals($a,$b); }

class WP_Error {
    private $c,$m,$d;
    public function __construct($c='',$m='',$d=[]){ $this->c=$c; $this->m=$m; $this->d=$d; }
    public function get_error_code(){ return $this->c; }
    public function get_error_message(){ return $this->m; }
    public function get_error_data(){ return $this->d; }
}
function is_wp_error($t){ return $t instanceof WP_Error; }
class WP_REST_Response { public $data,$status;
    public function __construct($d=null,$s=200){ $this->data=$d; $this->status=$s; } }
class WP_REST_Server { const CREATABLE = 'POST'; }
class WP_REST_Request {
    private $h,$j,$b;
    public function __construct($h=[],$j=[],$b=''){ 
        $this->h=array_change_key_case($h,CASE_LOWER); $this->j=$j; $this->b=$b; }
    public function get_header($k){ return $this->h[strtolower($k)] ?? ''; }
    public function get_json_params(){ return $this->j; }
    public function get_body(){ return $this->b; }
}

/* ---------- شبیه‌ساز DSS ---------- */
class DSS_Config {
    public static function secret(){ return 'TOPSECRET'; }
    public static function target_key(){ return 'siteB'; }
    public static function current_key(){ return 'siteA'; }
}
class DSS_Logger { public static function warning($m,$c=[]){} public static function error($m,$c=[]){}
                   public static function log($l,$m,$c=[]){} }
class DSS_Importer { public static function handle($p){ return ['success'=>true,'message'=>'','id'=>1]; } }
class DSS_Client {
    public static function sign($ts,$nonce,$body){
        return hash_hmac('sha256', $ts.'.'.$nonce.'.'.$body, DSS_Config::secret()); }
}

/* ---------- شبیه‌ساز ووکامرس ---------- */
$PRODUCTS = [];
class FakeProduct {
    private $d;
    public function __construct($d){ $this->d=$d; }
    public function get_id(){ return $this->d['id']; }
    public function get_sku(){ return $this->d['sku'] ?? ''; }
    public function get_name(){ return $this->d['name'] ?? ''; }
    public function get_type(){ return $this->d['type'] ?? 'simple'; }
    public function get_status(){ return 'publish'; }
    public function is_type($t){ return $this->get_type()===$t; }
    public function get_regular_price(){ return $this->d['regular_price'] ?? ''; }
    public function get_sale_price(){ return $this->d['sale_price'] ?? ''; }
    public function get_stock_status(){ return $this->d['stock_status'] ?? 'instock'; }
    public function get_manage_stock(){ return $this->d['manage_stock'] ?? false; }
    public function get_stock_quantity(){ return $this->d['stock_quantity'] ?? null; }
    public function get_children(){ return $this->d['children'] ?? []; }
    public function get_attributes(){ return $this->d['attributes'] ?? []; }
    public function get_date_modified(){ return new class($this->d['modified'] ?? '2026-08-01 10:00:00') {
        private $v; public function __construct($v){ $this->v=$v; }
        public function date($f){ return $this->v; } }; }
}
function wc_get_product($id){ return isset($GLOBALS['PRODUCTS'][$id]) ? new FakeProduct($GLOBALS['PRODUCTS'][$id]) : false; }
function wc_get_product_id_by_sku($sku){
    foreach($GLOBALS['PRODUCTS'] as $id=>$d) if(($d['sku']??'')===$sku) return $id;
    return 0; }

require __DIR__ . '/../flatsome-child/inc/dual-site-sync/includes/class-dss-rest.php';

/* ---------- داده ---------- */
$PRODUCTS = [
    10 => ['id'=>10,'sku'=>'0715','name'=>'کت تک','type'=>'simple','regular_price'=>'2500000',
           'sale_price'=>'2200000','stock_status'=>'instock','manage_stock'=>true,'stock_quantity'=>7,
           'modified'=>'2026-08-10 09:00:00'],
    20 => ['id'=>20,'sku'=>'SHIRT','name'=>'پیراهن','type'=>'variable','children'=>[21,22]],
    21 => ['id'=>21,'sku'=>'SHIRT-A','type'=>'variation','regular_price'=>'1800000',
           'stock_status'=>'instock','attributes'=>['color'=>'آبی']],
    22 => ['id'=>22,'sku'=>'SHIRT-B','type'=>'variation','regular_price'=>'1900000',
           'stock_status'=>'outofstock','attributes'=>['color'=>'قرمز']],
];

/* ---------- ابزار تست ---------- */
function line($l,$ok,$x=''){ printf("  %-48s %s %s\n", $l, $ok?'✔':'✘ FAIL', $x); }
function signed_request($body_array, $overrides = []) {
    $body = json_encode($body_array);
    $ts   = (string) time();
    $nonce= 'n' . mt_rand(1000,999999);
    $h = array_merge([
        'X-DSS-Site'      => 'siteB',
        'X-DSS-Timestamp' => $ts,
        'X-DSS-Nonce'     => $nonce,
        'X-DSS-Signature' => DSS_Client::sign($ts, $nonce, $body),
    ], $overrides);
    return new WP_REST_Request($h, $body_array, $body);
}

$rest = DSS_Rest::instance();
do_action('rest_api_init');

echo "\n########## ۱) ثبت مسیر ##########\n";
line('مسیر product-state ثبت شد', isset($GLOBALS['ROUTES']['dss/v1/product-state']));
line('فقط POST می‌پذیرد',
     ($GLOBALS['ROUTES']['dss/v1/product-state']['methods'] ?? '') === 'POST');
line('امضا بررسی می‌شود',
     is_array($GLOBALS['ROUTES']['dss/v1/product-state']['permission_callback'] ?? null));

echo "\n########## ۲) امنیت ##########\n";
$r = $rest->verify_request(signed_request(['ids'=>[10]]));
line('درخواست امضاشده پذیرفته می‌شود', $r === true);

$r = $rest->verify_request(signed_request(['ids'=>[10]], ['X-DSS-Signature'=>'جعلی']));
line('امضای جعلی رد می‌شود', is_wp_error($r) && $r->get_error_code()==='dss_bad_signature');

$r = $rest->verify_request(signed_request(['ids'=>[10]], ['X-DSS-Site'=>'siteA']));
line('کلید خودِ همین سایت رد می‌شود', is_wp_error($r) && $r->get_error_code()==='dss_unknown_site');

$r = $rest->verify_request(signed_request(['ids'=>[10]], ['X-DSS-Timestamp'=>(string)(time()-9999)]));
line('درخواست کهنه رد می‌شود', is_wp_error($r) && $r->get_error_code()==='dss_stale_request');

$req = signed_request(['ids'=>[10]]);
$rest->verify_request($req);
line('بازپخش همان درخواست رد می‌شود',
     is_wp_error($rest->verify_request($req)) );

echo "\n########## ۳) خواندن با شناسه ##########\n";
$res = $rest->handle_product_state(new WP_REST_Request([], ['ids'=>[10]]));
$p = $res->data['products'][0] ?? [];
line('یک محصول برگشت', count($res->data['products']) === 1);
line('قیمت اصلی درست است', ($p['regular_price'] ?? '') === '2500000');
line('قیمت حراج درست است', ($p['sale_price'] ?? '') === '2200000');
line('وضعیت موجودی درست است', ($p['stock_status'] ?? '') === 'instock');
line('تعداد موجودی درست است', ($p['stock_quantity'] ?? null) === 7);
line('زمان تغییر برگشت', ($p['modified'] ?? '') === '2026-08-10 09:00:00');
line('کلید سایت در پاسخ هست', ($res->data['site'] ?? '') === 'siteA');

echo "\n########## ۴) خواندن با SKU ##########\n";
$res = $rest->handle_product_state(new WP_REST_Request([], ['skus'=>['0715']]));
line('SKU به محصول درست رسید', ($res->data['products'][0]['id'] ?? 0) === 10);

$res = $rest->handle_product_state(new WP_REST_Request([], ['skus'=>['وجود ندارد']]));
line('SKU ناموجود خطا نمی‌دهد', $res->data['products'] === []);

echo "\n########## ۵) محصول متغیر ##########\n";
$res = $rest->handle_product_state(new WP_REST_Request([], ['ids'=>[20]]));
$p = $res->data['products'][0];
line('وریشن‌ها هم برمی‌گردند', count($p['variations']) === 2, '→ ' . count($p['variations']));
line('قیمت هر وریشن جداست',
     $p['variations'][0]['regular_price'] === '1800000'
     && $p['variations'][1]['regular_price'] === '1900000');
line('وضعیت هر وریشن جداست',
     $p['variations'][1]['stock_status'] === 'outofstock');

echo "\n########## ۶) محدودیت‌ها ##########\n";
$many = range(1, 200);
$res = $rest->handle_product_state(new WP_REST_Request([], ['ids'=>$many]));
line('حداکثر ۵۰ مورد پردازش می‌شود', count($res->data['products']) <= 50);

$res = $rest->handle_product_state(new WP_REST_Request([], ['ids'=>[]]));
line('لیست خالی پاسخ خالی می‌دهد', $res->data['products'] === []);

$res = $rest->handle_product_state(new WP_REST_Request([], []));
line('بدون ids و skus هم خطا نمی‌دهد', $res->data['products'] === []);

$res = $rest->handle_product_state(new WP_REST_Request([], ['ids'=>[99999]]));
line('شناسه‌ی ناموجود نادیده گرفته می‌شود', $res->data['products'] === []);

echo "\n########## ۷) فقط خواندنی بودن ##########\n";
$before = $GLOBALS['PRODUCTS'];
$rest->handle_product_state(new WP_REST_Request([], ['ids'=>[10,20]]));
line('هیچ داده‌ای تغییر نکرد', $GLOBALS['PRODUCTS'] === $before);
