<?php
/**
 * Step 40 — WooCommerce catalogue and orders (heavy profile).
 *
 * Runs under wp-cli: `wp eval-file steps/40-woocommerce.php --shard=1/4`
 *
 * Why a shop matters for benchmarking: cart, checkout and My Account are
 * uncacheable by design, and product pages carry heavy meta queries. A blog
 * with full-page caching in front measures the cache; a shop measures the
 * server. Orders are generated too — they are what makes wp_postmeta (or the
 * HPOS tables) big enough for the query planner's choices to show up.
 *
 * Everything goes through the WooCommerce CRUD API rather than raw inserts, so
 * lookup tables and product attributes stay consistent with what the plugin
 * expects.
 */

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/media.php';
require_once __DIR__ . '/../lib/text.php';

if (!class_exists('WC_Product_Simple')) {
    wp_test_log('WooCommerce is not active — skipping step 40');
    return;
}

$config = Config::load();
[$shardIndex, $shardCount] = wp_test_shard($args ?? []);

ini_set('memory_limit', '1024M');
set_time_limit(0);

$productCount = $config->int('WOO_PRODUCTS');
$orderCount = $config->int('WOO_ORDERS');
$started = microtime(true);

wp_defer_term_counting(true);
wp_suspend_cache_invalidation(true);

/* ------------------------------------------------------- product categories */

$categoryNames = ['Enclosures', 'Adapters', 'Sensors', 'Cabling', 'Power', 'Mounting',
    'Tooling', 'Storage', 'Networking', 'Optics', 'Cooling', 'Spares'];

$productCatIds = [];
foreach ($categoryNames as $name) {
    $existing = get_term_by('name', $name, 'product_cat');
    if ($existing) {
        $productCatIds[] = (int) $existing->term_id;
        continue;
    }
    $term = wp_insert_term($name, 'product_cat');
    if (!is_wp_error($term)) {
        $productCatIds[] = (int) $term['term_id'];
    }
}

/* ------------------------------------------------------------- product images */

// Reuse the library from step 20 rather than generating a second one: the disk
// footprint is already accounted for and product pages get real images.
$imagePool = get_posts([
    'post_type'      => 'attachment',
    'post_status'    => 'inherit',
    'post_mime_type' => 'image/jpeg',
    'posts_per_page' => -1,
    'orderby'        => 'ID',
    'order'          => 'ASC',
    'fields'         => 'ids',
]);

/* ------------------------------------------------------------------ products */

$made = 0;
$productIds = [];

for ($i = 0; $i < $productCount; $i++) {
    if ($i % $shardCount !== $shardIndex) {
        continue;
    }

    $rng = $config->rng("product:$i");
    $text = new TextGen($rng);

    $product = new WC_Product_Simple();
    $name = $text->productName();
    $price = round($rng->gauss(85, 60), 2);
    $price = max(4.95, min(1200.0, $price));

    $product->set_name($name);
    $product->set_slug(sanitize_title($name . '-' . $i));
    $product->set_status('publish');
    $product->set_catalog_visibility('visible');
    $product->set_description($text->productDescription());
    $product->set_short_description($text->excerpt());
    $product->set_sku(sprintf('WPT-%06d', $i));
    $product->set_regular_price((string) $price);

    if ($rng->bool(0.22)) {
        $product->set_sale_price((string) round($price * $rng->int(60, 90) / 100, 2));
    }

    $product->set_manage_stock(true);
    $product->set_stock_quantity($rng->int(0, 400));
    $product->set_stock_status($rng->bool(0.92) ? 'instock' : 'outofstock');
    $product->set_weight((string) round($rng->float() * 8 + 0.1, 2));
    $product->set_length((string) $rng->int(4, 60));
    $product->set_width((string) $rng->int(4, 40));
    $product->set_height((string) $rng->int(2, 30));
    $product->set_date_created($config->dateFor($i, max(1, $productCount), 800, $rng));

    if ($productCatIds) {
        $product->set_category_ids($rng->sample($productCatIds, $rng->int(1, 3)));
    }

    if ($imagePool) {
        $product->set_image_id($imagePool[($i * 7) % count($imagePool)]);
        // A gallery of three is typical for a real catalogue and multiplies the
        // number of thumbnails a product page requests.
        $gallery = [];
        for ($g = 1; $g <= 3; $g++) {
            $gallery[] = $imagePool[($i * 7 + $g * 13) % count($imagePool)];
        }
        $product->set_gallery_image_ids($gallery);
    }

    $id = $product->save();
    if ($id) {
        $productIds[] = (int) $id;
        $made++;
    }

    if ($made % 250 === 0 && $made > 0) {
        wp_test_log(sprintf(
            'products %d/%d  (%.1fs)',
            $made,
            (int) ceil($productCount / $shardCount),
            microtime(true) - $started
        ));
    }
}

wp_test_log(sprintf('products: %d in %.1fs', $made, microtime(true) - $started));

/* -------------------------------------------------------------------- orders */

// Only one shard writes orders: they draw from the whole catalogue, so running
// this concurrently would just contend on the same tables for no speedup.
$ordersMade = 0;
if ($shardIndex === 0 && $orderCount > 0) {
    $catalogue = get_posts([
        'post_type'      => 'product',
        'post_status'    => 'publish',
        'posts_per_page' => min(2000, max(1, $productCount)),
        'orderby'        => 'ID',
        'order'          => 'ASC',
        'fields'         => 'ids',
    ]);

    $statuses = ['completed', 'completed', 'completed', 'processing', 'processing',
                 'on-hold', 'cancelled', 'refunded'];

    for ($i = 0; $i < $orderCount && $catalogue; $i++) {
        $rng = $config->rng("order:$i");
        $text = new TextGen($rng);

        $order = wc_create_order();
        if (is_wp_error($order)) {
            continue;
        }

        $lines = $rng->int(1, 4);
        for ($l = 0; $l < $lines; $l++) {
            $product = wc_get_product($catalogue[$rng->int(0, count($catalogue) - 1)]);
            if ($product) {
                $order->add_product($product, $rng->int(1, 3));
            }
        }

        $name = explode(' ', $text->authorName());
        $address = [
            'first_name' => $name[0],
            'last_name'  => $name[1] ?? 'Doe',
            'email'      => sprintf('customer%d@wp-test.invalid', $i),
            'phone'      => '+34' . $rng->int(600000000, 699999999),
            'address_1'  => $rng->int(1, 200) . ' ' . $rng->pick(['Main', 'Park', 'Station', 'Harbour']) . ' St',
            'city'       => $rng->pick(['Madrid', 'Lisbon', 'Dublin', 'Porto', 'Valencia', 'Bilbao']),
            'postcode'   => (string) $rng->int(10000, 99999),
            'country'    => $rng->pick(['ES', 'PT', 'IE', 'FR', 'DE']),
        ];
        $order->set_address($address, 'billing');
        $order->set_address($address, 'shipping');

        $order->set_date_created($config->dateFor($i, max(1, $orderCount), 700, $rng));
        $order->calculate_totals();
        $order->set_status($rng->pick($statuses));
        $order->save();

        $ordersMade++;

        if ($ordersMade % 250 === 0) {
            wp_test_log(sprintf('orders %d/%d  (%.1fs)', $ordersMade, $orderCount, microtime(true) - $started));
        }
    }
}

wp_suspend_cache_invalidation(false);
wp_defer_term_counting(false);
if ($productCatIds) {
    wp_update_term_count_now($productCatIds, 'product_cat');
}

file_put_contents(
    sprintf('%s/woo-%d.json', $config->stateDir(), $shardIndex),
    json_encode(['products' => $made, 'orders' => $ordersMade], JSON_PRETTY_PRINT)
);

wp_test_log(sprintf('woocommerce done: %d products, %d orders, %.1fs', $made, $ordersMade, microtime(true) - $started));
