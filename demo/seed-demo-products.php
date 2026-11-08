<?php

if (!class_exists('WooCommerce')) {
    fwrite(STDERR, "WooCommerce must be active before seeding demo products.\n");
    exit(1);
}

$catalog = [
    ['پیراهن روزمره آبی', '159000', '129000', 'ABP-DEMO-001', 'پوشاک'],
    ['تی‌شرت ساده سفید', '189000', '', 'ABP-DEMO-002', 'پوشاک'],
    ['شال نخی طرح‌دار', '245000', '219000', 'ABP-DEMO-003', 'پوشاک'],
    ['کیف دستی کوچک', '420000', '', 'ABP-DEMO-004', 'اکسسوری'],
    ['کیف دوشی روزانه', '590000', '520000', 'ABP-DEMO-005', 'اکسسوری'],
    ['کمربند کلاسیک', '210000', '', 'ABP-DEMO-006', 'اکسسوری'],
    ['ماگ سرامیکی ساده', '98000', '79000', 'ABP-DEMO-007', 'خانه و سبک زندگی'],
    ['دفتر یادداشت روزانه', '85000', '', 'ABP-DEMO-008', 'خانه و سبک زندگی'],
    ['چراغ مطالعه رومیزی', '375000', '339000', 'ABP-DEMO-009', 'خانه و سبک زندگی'],
    ['جوراب نخی روزمره', '65000', '', 'ABP-DEMO-010', 'پوشاک'],
    ['کیف پول جمع‌وجور', '275000', '249000', 'ABP-DEMO-011', 'اکسسوری'],
    ['بطری آب ورزشی', '135000', '', 'ABP-DEMO-012', 'خانه و سبک زندگی'],
];

$categories = [];
foreach (array_unique(array_column($catalog, 4)) as $name) {
    $term = term_exists($name, 'product_cat');
    if (!$term) {
        $term = wp_insert_term($name, 'product_cat');
    }
    if (is_wp_error($term)) {
        fwrite(STDERR, $term->get_error_message() . "\n");
        exit(1);
    }
    $categories[$name] = (int) (is_array($term) ? $term['term_id'] : $term);
}

$created = 0;
foreach ($catalog as [$name, $regularPrice, $salePrice, $sku, $category]) {
    if (wc_get_product_id_by_sku($sku)) {
        continue;
    }

    $product = new WC_Product_Simple();
    $product->set_name($name);
    $product->set_status('publish');
    $product->set_catalog_visibility('visible');
    $product->set_regular_price($regularPrice);
    $product->set_sku($sku);
    $product->set_stock_status('instock');
    $product->set_category_ids([$categories[$category]]);

    if ($salePrice !== '') {
        $product->set_sale_price($salePrice);
        $product->set_date_on_sale_from('2026-11-01 00:00:00');
        $product->set_date_on_sale_to('2026-11-15 23:59:59');
    }

    $product->save();
    $created++;
}

echo "Created {$created} demo products.\n";
