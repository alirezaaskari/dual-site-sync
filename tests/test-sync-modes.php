<?php
/**
 * حالت‌های همگام‌سازی — و مخصوصاً «بدون قیمت».
 *
 * اجرا:  php tests/test-sync-modes.php
 *
 * قرارِ «بدون قیمت» این است: همه چیز برود جز قیمت. راهش هم این است
 * که کلیدهای قیمت در بسته *نباشند* — نه اینکه خالی بروند. وارد‌کننده
 * با array_key_exists کار می‌کند، پس کلیدِ نبوده یعنی «دست نزن» و
 * قیمتِ سایت مقابل همان می‌ماند که بود. کلیدِ خالی ولی قیمت را صفر
 * می‌کرد.
 */

define('ABSPATH', '/fake/');
define('DSS_VERSION', '2.0.0');

/* ---------- شبیه‌ساز حداقلی ---------- */
function apply_filters($t, $v) { return $v; }
function get_permalink($id) { return 'https://a.test/?p=' . $id; }
function get_edit_post_link($id, $c = '') { return 'https://a.test/edit/' . $id; }
function absint($v) { return abs((int) $v); }

class DSS_Config {
    public static $on = ['sync_categories' => false, 'sync_brands' => false,
                         'sync_swatches' => false, 'sync_status' => false,
                         'sync_stock_fields' => true, 'shared_inventory' => false,
                         'sync_variation_images' => false];
    public static function is_on($k) { return !empty(self::$on[$k]); }
    public static function current_key() { return 'siteA'; }
}
class DSS_Media {
    public static function featured_url($id) { return 'https://a.test/img/' . $id . '.jpg'; }
    public static function url($id) { return 'https://a.test/img/' . $id . '.jpg'; }
}
class DSS_Swatches { public static function export($p, $i) { return []; } }
class DSS_Variation_Gallery { public static function export($id) { return []; } }

class FakeDate {
    private $t;
    public function __construct($t) { $this->t = $t; }
    public function getTimestamp() { return $this->t; }
}

class WC_Product {
    protected $d;
    public function __construct($d) { $this->d = $d; }
    private function v($k, $f = '') { return array_key_exists($k, $this->d) ? $this->d[$k] : $f; }
    public function get_id() { return $this->d['id']; }
    public function get_type() { return $this->v('type', 'simple'); }
    public function is_type($t) { return $this->get_type() === $t; }
    public function get_sku($c = 'view') { return $this->v('sku'); }
    public function get_regular_price($c = 'view') { return $this->v('regular_price'); }
    public function get_sale_price($c = 'view') { return $this->v('sale_price'); }
    public function get_date_on_sale_from() { return isset($this->d['from']) ? new FakeDate($this->d['from']) : null; }
    public function get_date_on_sale_to() { return isset($this->d['to']) ? new FakeDate($this->d['to']) : null; }
    public function get_short_description($c = 'view') { return $this->v('short_description', 'کوتاه'); }
    public function get_description($c = 'view') { return $this->v('description', 'بلند'); }
    public function get_name($c = 'view') { return $this->v('name', 'محصول'); }
    public function get_menu_order() { return 0; }
    public function get_catalog_visibility() { return 'visible'; }
    public function get_featured() { return false; }
    public function get_tax_status() { return 'taxable'; }
    public function get_tax_class() { return ''; }
    public function get_weight($c = 'view') { return ''; }
    public function get_length($c = 'view') { return ''; }
    public function get_width($c = 'view') { return ''; }
    public function get_height($c = 'view') { return ''; }
    public function get_purchase_note() { return ''; }
    public function get_status() { return 'publish'; }
    public function get_slug() { return 'mahsul'; }
    public function get_manage_stock($c = 'view') { return $this->v('manage_stock', false); }
    public function get_stock_quantity($c = 'view') { return $this->v('stock_quantity', null); }
    public function get_stock_status($c = 'view') { return $this->v('stock_status', 'instock'); }
    public function get_backorders($c = 'view') { return 'no'; }
    public function get_low_stock_amount() { return ''; }
    public function get_gallery_image_ids() { return $this->v('gallery', []); }
    public function get_children() { return $this->v('children', []); }
    public function get_attributes() { return []; }
}

class WC_Product_Variation extends WC_Product {
    public function get_variation_attributes() { return ['pa_size' => 'L']; }
    public function get_image_id($c = 'view') { return 0; }
}

$PRODUCTS = [];
function wc_get_product($id) {
    $d = $GLOBALS['PRODUCTS'][$id] ?? null;
    if (!$d) { return false; }
    return ($d['type'] ?? '') === 'variation' ? new WC_Product_Variation($d) : new WC_Product($d);
}

require __DIR__ . '/../flatsome-child/inc/dual-site-sync/includes/class-dss-exporter.php';

/* ---------- داده ---------- */
$PRODUCTS = [
    10 => ['id' => 10, 'sku' => 'SH-1', 'type' => 'variable', 'name' => 'پیراهن',
           'regular_price' => '', 'children' => [11, 12], 'gallery' => [9001]],
    11 => ['id' => 11, 'sku' => 'SH-1-L', 'type' => 'variation', 'parent' => 10,
           'regular_price' => '1800000', 'sale_price' => '1500000'],
    12 => ['id' => 12, 'sku' => 'SH-1-XL', 'type' => 'variation', 'parent' => 10,
           'regular_price' => '1900000', 'sale_price' => ''],
    20 => ['id' => 20, 'sku' => 'KT-1', 'type' => 'simple', 'name' => 'کت',
           'regular_price' => '2500000', 'sale_price' => '2200000',
           'from' => 1700000000, 'to' => 1800000000, 'gallery' => [9002]],
];

$pass = 0; $fail = 0;
function line($l, $ok, $x = '') {
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("  %-50s %s %s\n", $l, $ok ? '✔' : '✘ FAIL', $x);
}

$PRICE_KEYS = ['regular_price', 'sale_price', 'date_on_sale_from', 'date_on_sale_to'];

function has_any($arr, $keys) {
    foreach ($keys as $k) { if (array_key_exists($k, $arr)) { return true; } }
    return false;
}

/* ============================================================ */
echo "\n########## ۱) حالت پذیرفته می‌شود ##########\n";

line('در فهرست حالت‌های مجاز است',
     in_array('full_no_price', DSS_Exporter::MODES, true));
line('برچسب فارسی دارد',
     DSS_Exporter::mode_label('full_no_price') === 'همگام‌سازی بدون قیمت',
     '→ ' . DSS_Exporter::mode_label('full_no_price'));
line('قیمت نمی‌برد', DSS_Exporter::mode_includes_price('full_no_price') === false);
line('ولی تصویر می‌برد', DSS_Exporter::mode_includes_images('full_no_price') === true);
line('و محتوا می‌برد', DSS_Exporter::mode_includes_content('full_no_price') === true);

echo "\n  حالت‌های دیگر دست‌نخورده‌اند:\n";
line('کامل قیمت می‌برد', DSS_Exporter::mode_includes_price('full') === true);
line('بدون تصاویر هم قیمت می‌برد',
     DSS_Exporter::mode_includes_price('full_no_images') === true);
line('جزئی هم قیمت می‌برد', DSS_Exporter::mode_includes_price('partial') === true);
line('موجودی قیمت نمی‌برد', DSS_Exporter::mode_includes_price('stock') === false);

/* ============================================================ */
echo "\n########## ۲) بستهٔ محصول ساده ##########\n";

$full = DSS_Exporter::build(wc_get_product(20), 'full');
$nop  = DSS_Exporter::build(wc_get_product(20), 'full_no_price');

line('کامل: قیمت هست', ($full['product']['regular_price'] ?? '') === '2500000');
line('کامل: حراج هست', ($full['product']['sale_price'] ?? '') === '2200000');
line('کامل: تاریخ حراج هست', ($full['product']['date_on_sale_from'] ?? null) === 1700000000);

line('بدون قیمت: هیچ کلیدِ قیمتی نیست',
     !has_any($nop['product'], $PRICE_KEYS),
     '→ ' . json_encode(array_values(array_intersect(array_keys($nop['product']), $PRICE_KEYS))));
line('نه اینکه خالی باشد — نیست',
     !array_key_exists('regular_price', $nop['product']));
line('نام رفته', ($nop['product']['name'] ?? '') === 'کت');
line('توضیح کوتاه رفته', ($nop['product']['short_description'] ?? '') === 'کوتاه');
line('توضیح بلند رفته', ($nop['product']['description'] ?? '') === 'بلند');
line('تصاویر رفته', !empty($nop['images']['featured']),
     '→ ' . ($nop['images']['featured'] ?? '—'));
line('گالری رفته', ($nop['images']['gallery'] ?? []) !== []);
line('موجودی رفته', array_key_exists('stock_status', $nop['product']));
line('پرچمِ قیمت در بسته false است', ($nop['flags']['price'] ?? null) === false);
line('و در حالت کامل true', ($full['flags']['price'] ?? null) === true);

/* ============================================================ */
echo "\n########## ۳) وریشن‌ها ##########\n";

$vfull = DSS_Exporter::build(wc_get_product(10), 'full');
$vnop  = DSS_Exporter::build(wc_get_product(10), 'full_no_price');

line('کامل: قیمت وریشن هست',
     ($vfull['variations'][0]['regular_price'] ?? '') === '1800000');
line('بدون قیمت: کلیدِ قیمتِ وریشن نیست',
     !array_key_exists('regular_price', $vnop['variations'][0])
     && !array_key_exists('sale_price', $vnop['variations'][0]),
     '→ ' . json_encode(array_keys($vnop['variations'][0]), JSON_UNESCAPED_UNICODE));
line('ولی SKU و ویژگی‌ها هستند',
     ($vnop['variations'][0]['sku'] ?? '') === 'SH-1-L'
     && !empty($vnop['variations'][0]['attributes']));
line('هر دو وریشن آمده‌اند', count($vnop['variations']) === 2,
     '→ ' . count($vnop['variations']));
line('موجودیِ وریشن رفته',
     array_key_exists('stock_status', $vnop['variations'][0]));

/* ============================================================ */
echo "\n########## ۴) حالتِ موجودی دست‌نخورده است ##########\n";
/*
 * «موجودی» از قبل قیمت نمی‌برد — با یک راهِ دیگر: بسته‌ی محصول را
 * کامل خالی می‌کرد. آن راه نباید عوض شده باشد.
 */
$st  = DSS_Exporter::build(wc_get_product(20), 'stock');
$vst = DSS_Exporter::build(wc_get_product(10), 'stock');

line('قیمتی در بسته نیست', !has_any($st['product'], $PRICE_KEYS));
line('توضیح کوتاه هم نیست', !array_key_exists('short_description', $st['product']));
line('تصویری هم نیست', !isset($st['images']));
line('قیمتِ وریشن هم نیست',
     !array_key_exists('regular_price', $vst['variations'][0]),
     '→ ' . json_encode(array_keys($vst['variations'][0]), JSON_UNESCAPED_UNICODE));
line('ولی موجودیِ وریشن هست',
     array_key_exists('stock_status', $vst['variations'][0]));

printf("\n  نتیجه: %d پاس، %d شکست\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
