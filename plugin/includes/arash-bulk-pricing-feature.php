<?php

// SPDX-License-Identifier: GPL-2.0-or-later
defined('ABSPATH') || exit;


final class Arash_Bulk_Pricing {
    const VERSION = '0.2.25';
    const NONCE = 'arash_bulk_pricing';

    public function __construct() {
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'assets'], 100);
        add_action('wp_ajax_arash_bulk_price_update', [$this, 'bulk_update']);
        add_action('wp_ajax_arash_bulk_price_filter', [$this, 'filter_products']);
    }

    public function admin_menu() {
        add_submenu_page(
            'woocommerce', 'قیمت‌گذاری گروهی ووکامرس', 'قیمت‌گذاری گروهی', 'manage_woocommerce',
            'arash-bulk-pricing', [$this, 'render_page']
        );
    }

    public function assets($hook) {
        if ($hook !== 'woocommerce_page_arash-bulk-pricing') return;
        wp_enqueue_style('dashicons');
        wp_enqueue_style(
            'arash-jalali-datepicker',
            plugins_url('assets/vendor/jalalidatepicker/jalalidatepicker.min.css', ARASH_BULK_PRICING_FILE),
            [],
            '1.0.0'
        );
        wp_enqueue_script(
            'arash-jalali-datepicker',
            plugins_url('assets/vendor/jalalidatepicker/jalalidatepicker.min.js', ARASH_BULK_PRICING_FILE),
            [],
            '1.0.0',
            true
        );
        wp_enqueue_style('arash-bulk-pricing', plugins_url('assets/admin.css', ARASH_BULK_PRICING_FILE), [], self::VERSION);
        wp_enqueue_script('arash-bulk-pricing', plugins_url('assets/admin.js', ARASH_BULK_PRICING_FILE), ['jquery', 'arash-jalali-datepicker'], self::VERSION, true);
        wp_localize_script('arash-bulk-pricing', 'ArashBulkPricing', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(self::NONCE),
            'confirm' => __('Apply this bulk price operation to the selected products?', 'arash-bulk-pricing'),
        ]);
    }

    private function products($filters = null) {
        if (!class_exists('WooCommerce')) return [];
        if (!is_array($filters)) $filters = $_GET;
        $args = [
            'status' => 'publish', 'limit' => -1, 'return' => 'objects',
            'paginate' => false, 'orderby' => 'title', 'order' => 'ASC',
        ];
        if (!empty($filters['category'])) $args['category'] = [sanitize_title(wp_unslash($filters['category']))];
        $only_available = !empty($filters['in_stock']);
        if ($only_available) $args['stock_status'] = 'instock';
        $parents = wc_get_products($args);
        $rows = [];
        foreach ($parents as $parent) {
            if ($only_available && !$parent->is_in_stock()) continue;
            $rows[] = $parent;
            if ($parent->is_type('variable')) {
                foreach ($parent->get_children() as $child_id) {
                    $child = wc_get_product($child_id);
                    if ($child && $child->get_status() !== 'trash' && (!$only_available || $child->is_in_stock())) $rows[] = $child;
                }
            }
        }
        $field = sanitize_key(wp_unslash($filters['filter_column'] ?? ''));
        $operator = sanitize_key(wp_unslash($filters['filter_operator'] ?? ''));
        if ($field === 'sale_period') $operator = 'contains';
        $value = sanitize_text_field(wp_unslash($filters['filter_value'] ?? ''));
        $filter_start = sanitize_text_field(wp_unslash($filters['filter_start'] ?? ''));
        $filter_end = sanitize_text_field(wp_unslash($filters['filter_end'] ?? ''));
        $has_value = $field === 'sale_period' ? ($filter_start !== '' || $filter_end !== '') : $value !== '';
        $valid_operator = $field === 'sale_period' || in_array($operator, ['contains', 'not_contains', 'greater_than', 'less_than'], true);
        if ($has_value && $valid_operator && in_array($field, ['product', 'sku', 'regular', 'sale', 'sale_period'], true)) {
            $rows = array_values(array_filter($rows, function ($product) use ($field, $operator, $value, $filter_start, $filter_end) {
                return $this->matches_product_filter($product, $field, $operator, $value, $filter_start, $filter_end);
            }));
        }
        return $rows;
    }

    private function matches_product_filter($product, $field, $operator, $value, $filter_start = '', $filter_end = '') {
        if ($field === 'sale_period') {
            $from = $product->get_date_on_sale_from();
            $to = $product->get_date_on_sale_to();
            $actual_start = $from ? $this->gregorian_to_jalali($from->date('Y-m-d')) : '';
            $actual_end = $to ? $this->gregorian_to_jalali($to->date('Y-m-d')) : '';
            $filter_start = $this->normalize_jalali_date($filter_start);
            $filter_end = $this->normalize_jalali_date($filter_end);

            if ($operator === 'contains' || $operator === 'not_contains') {
                $has_period = $actual_start !== '' || $actual_end !== '';
                $range_start = $filter_start ?: $filter_end;
                $range_end = $filter_end ?: $filter_start;
                if ($range_start > $range_end) [$range_start, $range_end] = [$range_end, $range_start];
                $overlaps = $has_period && $range_start !== '' && $range_end !== ''
                    && ($actual_start === '' || $actual_start <= $range_end)
                    && ($actual_end === '' || $actual_end >= $range_start);
                return $operator === 'contains' ? $overlaps : !$overlaps;
            }

            if ($operator === 'greater_than') {
                $boundary = $filter_end ?: $filter_start;
                return $actual_start !== '' && $boundary !== '' && $actual_start > $boundary;
            }
            $boundary = $filter_start ?: $filter_end;
            return $actual_end !== '' && $boundary !== '' && $actual_end < $boundary;
        }

        switch ($field) {
            case 'product':
                $actual = $product->get_name();
                break;
            case 'sku':
                $actual = $product->get_sku();
                break;
            case 'regular':
                $actual = $product->get_regular_price();
                break;
            case 'sale':
                $actual = $product->get_sale_price();
                break;
            default:
                return false;
        }

        if ($operator === 'contains' || $operator === 'not_contains') {
            $contains = function_exists('mb_stripos') ? mb_stripos((string) $actual, $value) !== false : stripos((string) $actual, $value) !== false;
            return $operator === 'contains' ? $contains : !$contains;
        }

        if ($field === 'regular' || $field === 'sale') {
            $numeric_value = strtr($value, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9', '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9', '٬' => '', ',' => '', '٫' => '.']);
            if ($actual === '' || !is_numeric($numeric_value)) return false;
            $comparison = (float) $actual <=> (float) $numeric_value;
        } else {
            $comparison = strnatcasecmp((string) $actual, $value) <=> 0;
        }

        return $operator === 'greater_than' ? $comparison > 0 : $comparison < 0;
    }

    public function filter_products() {
        check_ajax_referer(self::NONCE, 'nonce');
        if (!current_user_can('manage_woocommerce')) wp_send_json_error(['message' => 'Permission denied.'], 403);

        $filters = [
            'filter_column' => sanitize_key(wp_unslash($_POST['filter_column'] ?? '')),
            'filter_operator' => sanitize_key(wp_unslash($_POST['filter_operator'] ?? '')),
            'filter_value' => sanitize_text_field(wp_unslash($_POST['filter_value'] ?? '')),
            'filter_start' => sanitize_text_field(wp_unslash($_POST['filter_start'] ?? '')),
            'filter_end' => sanitize_text_field(wp_unslash($_POST['filter_end'] ?? '')),
            'category' => sanitize_title(wp_unslash($_POST['category'] ?? '')),
            'in_stock' => !empty($_POST['in_stock']),
        ];
        $products = $this->products($filters);
        $total = count($products);
        $per_page = sanitize_key($_POST['per_page'] ?? '10');
        if (!in_array($per_page, ['10', '30', '50', 'all'], true)) $per_page = '10';
        $page = max(1, absint($_POST['page'] ?? 1));
        $page_size = $per_page === 'all' ? $total : (int) $per_page;
        $offset = $per_page === 'all' ? 0 : ($page - 1) * $page_size;
        $visible = $per_page === 'all' ? $products : array_slice($products, $offset, $page_size);

        ob_start();
        $this->render_rows($visible);
        $rows_html = ob_get_clean();
        wp_send_json_success([
            'html' => $rows_html,
            'total' => $total,
            'page' => $page,
            'per_page' => $per_page,
        ]);
    }

    private function render_rows($products) {
        if (!$products): ?>
            <tr><td colspan="7" class="abp-empty">محصولی با این فیلترها پیدا نشد.</td></tr>
        <?php return; endif;
        foreach ($products as $product): $sale = $product->get_sale_price(); $is_parent = $product->is_type('variable'); $is_child = $product->is_type('variation'); ?>
            <tr data-id="<?php echo esc_attr($product->get_id()); ?>" class="<?php echo $is_child ? 'abp-variation' : ($is_parent ? 'abp-parent' : ''); ?>"><th class="check-column"><input type="checkbox" class="abp-row" <?php disabled($is_parent); ?>></th><td><?php echo $is_child ? '&nbsp;&nbsp;↳ ' : ''; ?><a href="<?php echo esc_url(get_edit_post_link($product->get_id())); ?>"><?php echo esc_html($product->get_name()); ?></a><?php if ($is_parent): ?> <span class="abp-parent-label">(والد؛ قیمت در وارییشن‌ها)</span><?php endif; ?></td><td><?php echo esc_html($product->get_sku() ?: '—'); ?></td><td><?php echo esc_html($is_child ? 'وارییشن' : ($is_parent ? 'والد متغیر' : 'ساده')); ?></td><td><input class="abp-inline" data-field="regular" value="<?php echo esc_attr($product->get_regular_price()); ?>" <?php disabled($is_parent); ?>></td><td><input class="abp-inline" data-field="sale" value="<?php echo esc_attr($sale); ?>" <?php disabled($is_parent); ?>></td><td><div class="abp-date-controls"><input class="abp-date-inline abp-date-picker" type="text" data-field="start" value="<?php echo esc_attr($this->gregorian_to_jalali($product->get_date_on_sale_from() ? $product->get_date_on_sale_from()->date('Y-m-d') : '')); ?>" placeholder="شروع شمسی" readonly <?php disabled($is_parent); ?>><span aria-hidden="true">→</span><input class="abp-date-inline abp-date-picker" type="text" data-field="end" value="<?php echo esc_attr($this->gregorian_to_jalali($product->get_date_on_sale_to() ? $product->get_date_on_sale_to()->date('Y-m-d') : '')); ?>" placeholder="پایان شمسی" readonly <?php disabled($is_parent); ?>><?php if (!$is_parent): ?><button class="button abp-save-row">ذخیره</button><?php endif; ?></div></td></tr>
        <?php endforeach;
    }

    private function jalali_to_gregorian($jy, $jm, $jd) {
        $jy = (int) $jy - 979; $jm = (int) $jm - 1; $jd = (int) $jd - 1;
        $days = 365 * $jy + (int) ($jy / 33) * 8 + (int) ((($jy % 33) + 3) / 4);
        for ($i = 0; $i < $jm; $i++) $days += $i < 6 ? 31 : 30;
        $days += $jd + 79;
        $gy = 1600 + 400 * (int) ($days / 146097); $days %= 146097;
        $leap = true;
        if ($days >= 36525) { $days--; $gy += 100 * (int) ($days / 36524); $days %= 36524; if ($days >= 365) $days++; else $leap = false; }
        $gy += 4 * (int) ($days / 1461); $days %= 1461;
        if ($days >= 366) { $leap = false; $days--; $gy += (int) ($days / 365); $days %= 365; }
        $gd = $days + 1; $gdm = [31, $leap ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31]; $gm = 0;
        while ($gm < 12 && $gd > $gdm[$gm]) { $gd -= $gdm[$gm]; $gm++; }
        return sprintf('%04d-%02d-%02d', $gy, $gm + 1, $gd);
    }

    private function normalize_persian_digits($value) {
        return strtr((string) $value, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9', '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);
    }

    private function normalize_jalali_date($value) {
        $value = str_replace(['-', '.'], '/', trim($this->normalize_persian_digits($value)));
        if (!preg_match('/^(\d{4})\/(\d{1,2})\/(\d{1,2})$/', $value, $match)) return '';
        return sprintf('%04d/%02d/%02d', $match[1], $match[2], $match[3]);
    }

    private function jalali_input_to_gregorian($value) {
        $date = $this->normalize_jalali_date($value);
        if ($date === '') return '';
        [$year, $month, $day] = array_map('intval', explode('/', $date));
        return $this->jalali_to_gregorian($year, $month, $day);
    }

    private function gregorian_to_jalali($date) {
        if (!$date) return '';
        [$gy, $gm, $gd] = array_map('intval', explode('-', substr($date, 0, 10)));
        $gdm = [0,31,28,31,30,31,30,31,31,30,31,30,31]; $gy2 = $gy - 1600; $gm2 = $gm - 1; $gd2 = $gd - 1;
        $days = 365*$gy2 + (int)(($gy2+3)/4) - (int)(($gy2+99)/100) + (int)(($gy2+399)/400);
        for ($i=0;$i<$gm2;$i++) $days += $gdm[$i+1]; if ($gm2>1 && (($gy%4===0 && $gy%100!==0)||$gy%400===0)) $days++; $days += $gd2 - 79;
        $jy = 979 + 33*(int)($days/12053); $days %= 12053; $jy += 4*(int)($days/1461); $days %= 1461; if ($days>365) { $jy += (int)(($days-1)/365); $days = ($days-1)%365; }
        $jm = $days < 186 ? 1 + (int)($days/31) : 7 + (int)(($days-186)/30); $jd = 1 + ($days < 186 ? $days%31 : ($days-186)%30);
        return sprintf('%04d/%02d/%02d', $jy, $jm, $jd);
    }

    public function render_page() {
        if (!current_user_can('manage_woocommerce')) wp_die(esc_html__('You do not have permission to access this page.', 'arash-bulk-pricing'));
        $products = $this->products();
        $initial_rows = array_slice($products, 0, 10);
        $categories = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false]);
        $filter_column = sanitize_key(wp_unslash($_GET['filter_column'] ?? 'product'));
        $filter_is_period = $filter_column === 'sale_period';
        $filter_value = sanitize_text_field(wp_unslash($_GET['filter_value'] ?? ''));
        $filter_start = sanitize_text_field(wp_unslash($_GET['filter_start'] ?? ''));
        $filter_end = sanitize_text_field(wp_unslash($_GET['filter_end'] ?? ''));
        ?>
        <div class="wrap arash-bulk-pricing" dir="rtl">
            <div class="abp-page-heading"><h1>قیمت‌گذاری گروهی</h1><button id="abp-save-changes" class="button button-secondary" disabled>ذخیره تغییرات</button></div>
                <p class="description">محصولات را فیلتر کنید و قیمت‌ها را در جدول ویرایش کنید. عملیات گروهی ابتدا فقط باکس‌های ردیف‌های انتخابی را تغییر می‌دهد؛ برای ثبت تغییرات در پایگاه‌داده «ذخیره تغییرات» را بزنید. محاسبه درصد قیمت ویژه بر اساس قیمت عادی انجام می‌شود.</p>
            <div class="abp-table-toolbar">
                <section class="abp-operation" aria-label="عملیات گروهی">
                    <div class="abp-operation-row">
                        <select id="abp-field"><option value="regular">قیمت عادی</option><option value="sale">قیمت ویژه</option></select>
                        <select id="abp-operation"><option value="increase">افزایش درصدی</option><option value="decrease">کاهش درصدی</option><option value="set">تعیین مبلغ</option><option value="round">گردکردن به مضرب</option><option value="clear">حذف قیمت ویژه</option></select>
                        <input id="abp-value" type="number" min="0" step="0.01" placeholder="درصد یا مبلغ">
                        <input id="abp-start" class="abp-date-picker" type="text" aria-label="شروع ویژه" placeholder="شروع ویژه (شمسی)" readonly>
                        <input id="abp-end" class="abp-date-picker" type="text" aria-label="پایان ویژه" placeholder="پایان ویژه (شمسی)" readonly>
                        <button id="abp-apply" class="button button-primary" disabled>اعمال روی انتخاب‌شده‌ها</button>
                    </div>
                </section>
                <div class="abp-table-toolbar-top">
                    <div class="abp-toolbar-filter-row">
                        <form id="abp-filters" class="abp-filters" method="get">
                            <input type="hidden" name="page" value="arash-bulk-pricing">
                            <select name="filter_column" aria-label="ستون فیلتر">
                                <option value="product" <?php selected($_GET['filter_column'] ?? 'product', 'product'); ?>>محصول</option>
                                <option value="sku" <?php selected($_GET['filter_column'] ?? '', 'sku'); ?>>SKU</option>
                                <option value="regular" <?php selected($_GET['filter_column'] ?? '', 'regular'); ?>>قیمت</option>
                                <option value="sale" <?php selected($_GET['filter_column'] ?? '', 'sale'); ?>>قیمت فروش ویژه</option>
                                <option value="sale_period" <?php selected($_GET['filter_column'] ?? '', 'sale_period'); ?>>بازه فروش ویژه</option>
                            </select>
                            <select name="filter_operator" aria-label="عملگر فیلتر" <?php echo $filter_is_period ? 'hidden disabled' : ''; ?>>
                                <option value="contains" <?php selected($_GET['filter_operator'] ?? 'contains', 'contains'); ?>>شامل</option>
                                <option value="not_contains" <?php selected($_GET['filter_operator'] ?? '', 'not_contains'); ?>>شامل نشود</option>
                                <option value="greater_than" <?php selected($_GET['filter_operator'] ?? '', 'greater_than'); ?>>بزرگتر از</option>
                                <option value="less_than" <?php selected($_GET['filter_operator'] ?? '', 'less_than'); ?>>کوچکتر از</option>
                            </select>
                            <input id="abp-filter-value" type="search" name="filter_value" value="<?php echo esc_attr($filter_value); ?>" placeholder="مقدار فیلتر" <?php disabled($filter_is_period); ?>>
                            <div id="abp-filter-date-values" class="abp-filter-date-values" <?php echo $filter_is_period ? '' : 'hidden'; ?>>
                                <input class="abp-date-picker" type="text" name="filter_start" value="<?php echo esc_attr($filter_start); ?>" aria-label="شروع بازه فیلتر" placeholder="از تاریخ شمسی" readonly <?php disabled(!$filter_is_period); ?>>
                                <span aria-hidden="true">تا</span>
                                <input class="abp-date-picker" type="text" name="filter_end" value="<?php echo esc_attr($filter_end); ?>" aria-label="پایان بازه فیلتر" placeholder="تا تاریخ شمسی" readonly <?php disabled(!$filter_is_period); ?>>
                            </div>
                            <select name="category"><option value="">همه دسته‌ها</option><?php foreach ($categories as $cat): ?><option value="<?php echo esc_attr($cat->slug); ?>" <?php selected($_GET['category'] ?? '', $cat->slug); ?>><?php echo esc_html($cat->name); ?></option><?php endforeach; ?></select>
                            <label><input type="checkbox" name="in_stock" value="1" <?php checked(!empty($_GET['in_stock'])); ?>> فقط محصولات موجود</label>
                            <button type="submit" class="button button-primary">اعمال فیلتر</button>
                        </form>
                    </div>
                    <div class="abp-toolbar-paging-row">
                        <div class="abp-table-paging">
                            <label>ردیف در صفحه <select id="abp-per-page"><option value="10" selected>10</option><option value="30">30</option><option value="50">50</option><option value="all">همه</option></select></label>
                            <div id="abp-pagination-top" class="abp-pagination" data-total="<?php echo esc_attr(count($products)); ?>" aria-live="polite"></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="abp-table-grid"><div class="abp-table-wrap"><table class="widefat fixed striped abp-table"><thead><tr><th class="check-column"><input type="checkbox" id="abp-select-all"></th><th>محصول</th><th>SKU</th><th>نوع</th><th>قیمت</th><th>قیمت فروش ویژه</th><th>بازه فروش ویژه</th></tr></thead><tbody>
            <?php $this->render_rows($initial_rows); ?>
            </tbody></table>
            </div><div id="abp-pagination-bottom" class="abp-pagination abp-pagination-bottom" aria-live="polite"></div><div class="abp-grid-loading" role="status" aria-live="polite" hidden><div class="abp-grid-message"><span class="abp-grid-spinner" aria-hidden="true"></span><span id="abp-status"></span></div></div></div>
        </div>
        <?php
    }

    public function bulk_update() {
        global $wpdb;
        check_ajax_referer(self::NONCE, 'nonce');
        if (!current_user_can('manage_woocommerce')) wp_send_json_error(['message' => 'Permission denied.'], 403);
        $ids = array_values(array_filter(array_map('absint', (array) ($_POST['ids'] ?? []))));
        $field = sanitize_key($_POST['field'] ?? '');
        $operation = sanitize_key($_POST['operation'] ?? '');
        $value = isset($_POST['value']) ? (float) $_POST['value'] : 0;
        $start = $this->jalali_input_to_gregorian(sanitize_text_field(wp_unslash($_POST['start'] ?? '')));
        $end = $this->jalali_input_to_gregorian(sanitize_text_field(wp_unslash($_POST['end'] ?? '')));
        if (sanitize_key($_POST['mode'] ?? '') === 'save_rows') {
            $rows = json_decode(wp_unslash($_POST['rows'] ?? '[]'), true);
            if (!is_array($rows) || !$rows) wp_send_json_error(['message' => 'ردیفی برای ذخیره وجود ندارد.'], 400);

            $products = [];
            foreach ($rows as $row) {
                $id = absint($row['id'] ?? 0);
                $product = wc_get_product($id);
                $regular = sanitize_text_field($row['regular'] ?? '');
                $sale = sanitize_text_field($row['sale'] ?? '');
                if (!$product || $product->is_type('variable') || $regular === '' || !is_numeric($regular) || (float) $regular < 0 || ($sale !== '' && (!is_numeric($sale) || (float) $sale < 0))) {
                    wp_send_json_error(['message' => 'یکی از ردیف‌ها یا قیمت‌های واردشده معتبر نیست؛ هیچ تغییری ذخیره نشد.'], 400);
                }
                $products[] = ['product' => $product, 'regular' => $regular, 'sale' => $sale,
                    'start' => sanitize_text_field($row['start'] ?? ''), 'end' => sanitize_text_field($row['end'] ?? '')];
            }

            $saved = [];
            foreach ($products as $item) {
                $product = $item['product'];
                $product->set_regular_price(wc_format_decimal($item['regular'], wc_get_price_decimals()));
                $product->set_sale_price($item['sale'] === '' ? '' : wc_format_decimal($item['sale'], wc_get_price_decimals()));
                foreach (['start', 'end'] as $date_key) {
                    $date = $this->jalali_input_to_gregorian($item[$date_key]);
                    if ($date_key === 'start') $product->set_date_on_sale_from($date ?: null);
                    else $product->set_date_on_sale_to($date ?: null);
                }
                $product->save();
                $date_from = $product->get_date_on_sale_from();
                $date_to = $product->get_date_on_sale_to();
                $saved[] = ['id' => $product->get_id(), 'regular' => $product->get_regular_price(), 'sale' => $product->get_sale_price(),
                    'start' => $this->gregorian_to_jalali($date_from ? $date_from->date('Y-m-d') : ''),
                    'end' => $this->gregorian_to_jalali($date_to ? $date_to->date('Y-m-d') : '')];
            }
            wp_send_json_success(['rows' => $saved]);
        }
        if (sanitize_key($_POST['mode'] ?? '') === 'rollback') {
            $rows = json_decode(wp_unslash($_POST['snapshot'] ?? '[]'), true);
            if (!is_array($rows)) wp_send_json_error(['message' => 'Snapshot نامعتبر است.'], 400);
            foreach ($rows as $row) {
                $product = wc_get_product(absint($row['id'] ?? 0));
                if (!$product) continue;
                $product->set_regular_price(sanitize_text_field($row['regular'] ?? ''));
                $product->set_sale_price(sanitize_text_field($row['sale'] ?? ''));
                foreach (['from', 'to'] as $date_key) {
                    $date_value = $this->jalali_input_to_gregorian(sanitize_text_field(wp_unslash($row[$date_key] ?? '')));
                    if ($date_key === 'from') $product->set_date_on_sale_from($date_value ?: null); else $product->set_date_on_sale_to($date_value ?: null);
                }
                $product->save();
            }
            wp_send_json_success(['restored' => count($rows)]);
        }
        if (!$ids || !in_array($field, ['regular', 'sale'], true) || !in_array($operation, ['increase', 'decrease', 'set', 'round', 'clear'], true)) wp_send_json_error(['message' => 'Invalid operation.'], 400);
        if ($field === 'regular' && $operation === 'clear') wp_send_json_error(['message' => 'Regular price cannot be cleared.'], 400);
        if ($value < 0 || ($operation === 'round' && $value < 1) || (in_array($operation, ['increase', 'decrease'], true) && $value > 100)) wp_send_json_error(['message' => 'Invalid percentage/value.'], 400);
        $updated = 0; $errors = []; $changed = [];
        $wpdb->query('START TRANSACTION');
        foreach ($ids as $id) {
            $product = wc_get_product($id);
            if (!$product) { $errors[] = $id; break; }
            // Percentage operations use the regular price as their base, including
            // when the destination field is the sale price. This also lets us
            // create a sale price when the product does not already have one.
            $old = $operation === 'round'
                ? ($field === 'regular' ? $product->get_regular_price() : $product->get_sale_price())
                : $product->get_regular_price();
            if ($operation === 'clear') $new = '';
            elseif ($operation === 'set') $new = $value;
            elseif ($old === '') { $errors[] = $id; break; }
            elseif ($operation === 'round') $new = round((float) $old / $value, 0, PHP_ROUND_HALF_UP) * $value;
            else $new = ((float) $old) * (1 + ($operation === 'increase' ? 1 : -1) * $value / 100);
            $new = $new === '' ? '' : wc_format_decimal(max(0, (float) $new), wc_get_price_decimals());
            if ($field === 'regular') $product->set_regular_price($new); else $product->set_sale_price($new);
            if (isset($_POST['sale_value']) && $field === 'regular') {
                $inline_sale = sanitize_text_field(wp_unslash($_POST['sale_value']));
                $product->set_sale_price($inline_sale === '' ? '' : wc_format_decimal(max(0, (float) $inline_sale), wc_get_price_decimals()));
            }
            if (!empty($_POST['save_dates'])) {
                $product->set_date_on_sale_from($start ?: null);
                $product->set_date_on_sale_to($end ?: null);
            } elseif ($field === 'sale' && $new !== '') { if ($start) $product->set_date_on_sale_from($start); if ($end) $product->set_date_on_sale_to($end); }
            if ($field === 'sale' && $new === '') { $product->set_date_on_sale_from(null); $product->set_date_on_sale_to(null); }
            $product->save(); $updated++;
            $date_from = $product->get_date_on_sale_from();
            $date_to = $product->get_date_on_sale_to();
            $changed[] = [
                'id' => $id,
                'regular' => $product->get_regular_price(),
                'sale' => $product->get_sale_price(),
                'start' => $this->gregorian_to_jalali($date_from ? $date_from->date('Y-m-d') : ''),
                'end' => $this->gregorian_to_jalali($date_to ? $date_to->date('Y-m-d') : ''),
            ];
        }
        if ($errors) {
            $wpdb->query('ROLLBACK');
            wp_send_json_error(['message' => 'عملیات کامل نشد و هیچ تغییری ثبت نشد.', 'errors' => $errors], 409);
        }
        $wpdb->query('COMMIT');
        wp_send_json_success(['updated' => $updated, 'rows' => $changed]);
    }
}

new Arash_Bulk_Pricing();
