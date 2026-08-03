<?php
/**
 * Step 90 — manifest.
 *
 * Records what was actually produced, so a benchmark run can state the shape of
 * its fixture instead of assuming it. Two runs of the same profile and seed
 * should agree on every field here except timings and byte sizes of JPEGs,
 * which can drift a little with the server's libjpeg (see `pixels_hash`).
 *
 * Runs under wp-cli: `wp eval-file steps/90-manifest.php`
 */

require_once __DIR__ . '/../lib/config.php';

global $wpdb;

$config = Config::load();
$stateDir = $config->stateDir();

/* -------------------------------------------------------------- media shards */

$mediaFiles = glob($stateDir . '/media-*.json') ?: [];
$attachments = [];
$originalBytes = 0;

foreach ($mediaFiles as $file) {
    $data = json_decode((string) file_get_contents($file), true);
    if (!is_array($data)) {
        continue;
    }
    $originalBytes += (int) ($data['bytes'] ?? 0);
    foreach ($data['attachments'] ?? [] as $record) {
        $attachments[(int) $record['index']] = $record;
    }
}
ksort($attachments);

// One hash over every image's pixel hash, in index order: this is the number to
// compare between two servers to prove they generated the same library.
$pixelsHash = hash('sha256', implode('', array_map(
    static fn(array $a): string => (string) ($a['pixels'] ?? ''),
    $attachments
)));

// Break the library down by source format. WebP conversion behaves differently
// for PNG and for already-compressed JPEG, so any WebP result has to say which
// of the two it is talking about — and how much of the fixture that covers.
$byFormat = ['jpeg' => ['count' => 0, 'bytes' => 0], 'png' => ['count' => 0, 'bytes' => 0]];
foreach ($attachments as $record) {
    $format = $record['format'] ?? 'jpeg';
    if (!isset($byFormat[$format])) {
        $byFormat[$format] = ['count' => 0, 'bytes' => 0];
    }
    $byFormat[$format]['count']++;
    $byFormat[$format]['bytes'] += (int) ($record['bytes'] ?? 0);
}

/* ------------------------------------------------------------ woo + content */

$woo = ['products' => 0, 'orders' => 0];
foreach (glob($stateDir . '/woo-*.json') ?: [] as $file) {
    $data = json_decode((string) file_get_contents($file), true);
    $woo['products'] += (int) ($data['products'] ?? 0);
    $woo['orders'] += (int) ($data['orders'] ?? 0);
}

$content = [];
if (is_readable($stateDir . '/content.json')) {
    $content = json_decode((string) file_get_contents($stateDir . '/content.json'), true) ?: [];
}

$elementor = [];
if (is_readable($stateDir . '/elementor.json')) {
    $elementor = json_decode((string) file_get_contents($stateDir . '/elementor.json'), true) ?: [];
    // The per-page listing is useful while building but noise in a manifest.
    unset($elementor['documents']);
}

// Plugin versions, not just slugs. A plugin pack that resolves to different
// versions on the two servers being compared is a difference that has to be
// visible before anyone quotes a number.
if (!function_exists('get_plugin_data')) {
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
}
$plugins = [];
foreach ((array) get_option('active_plugins', []) as $file) {
    $slug = dirname($file) !== '.' ? dirname($file) : basename($file, '.php');
    $data = @get_plugin_data(WP_PLUGIN_DIR . '/' . $file, false, false);
    $plugins[$slug] = $data['Version'] ?? '?';
}
ksort($plugins);

/* -------------------------------------------------------------- measurements */

$uploadDir = wp_upload_dir();
$uploadPath = $uploadDir['basedir'];

$uploadBytes = 0;
$uploadFiles = 0;
if (is_dir($uploadPath)) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($uploadPath, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $uploadBytes += $file->getSize();
            $uploadFiles++;
        }
    }
}

$dbBytes = (int) $wpdb->get_var($wpdb->prepare(
    'SELECT SUM(data_length + index_length) FROM information_schema.TABLES WHERE table_schema = %s',
    DB_NAME
));

$rowCounts = [
    'posts'      => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts}"),
    'postmeta'   => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta}"),
    'comments'   => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->comments}"),
    'terms'      => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->terms}"),
    'options'    => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options}"),
    'users'      => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->users}"),
];

$postTypes = [];
$rows = $wpdb->get_results("SELECT post_type, post_status, COUNT(*) AS n FROM {$wpdb->posts} GROUP BY post_type, post_status", ARRAY_A) ?: [];
foreach ($rows as $row) {
    $postTypes[$row['post_type'] . ':' . $row['post_status']] = (int) $row['n'];
}

$sizes = [];
foreach (wp_get_registered_image_subsizes() as $name => $spec) {
    $sizes[$name] = sprintf('%dx%d%s', $spec['width'], $spec['height'], !empty($spec['crop']) ? ' crop' : '');
}

/* ------------------------------------------------------------------ manifest */

$manifest = [
    'generator' => 'wp-test',
    'profile'   => $config->str('PROFILE_NAME'),
    'seed'      => $config->seed(),
    'epoch'     => date('c', $config->epoch()),
    'site' => [
        'url'          => home_url(),
        'wp_version'   => get_bloginfo('version'),
        'php_version'  => PHP_VERSION,
        'theme'        => (string) wp_get_theme()->get('Name'),
        'plugins'      => $plugins,
        'image_sizes'  => $sizes,
    ],
    'content' => $content + [
        'attachments' => count($attachments),
        'products'    => $woo['products'],
        'orders'      => $woo['orders'],
    ],
    'media_by_format' => $byFormat,
    'elementor'       => $elementor,
    'rows' => $rowCounts,
    'post_types' => $postTypes,
    'disk' => [
        'uploads_bytes'   => $uploadBytes,
        'uploads_mb'      => round($uploadBytes / 1048576, 1),
        'uploads_files'   => $uploadFiles,
        'originals_bytes' => $originalBytes,
        'originals_mb'    => round($originalBytes / 1048576, 1),
        'database_bytes'  => $dbBytes,
        'database_mb'     => round($dbBytes / 1048576, 1),
        'total_mb'        => round(($uploadBytes + $dbBytes) / 1048576, 1),
    ],
    'target_size_mb' => $config->int('TARGET_SIZE_MB'),
    'pixels_hash'    => substr($pixelsHash, 0, 32),
    'profile_values' => $config->all(),
];

$out = ABSPATH . 'wp-test-manifest.json';
file_put_contents($out, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
file_put_contents($stateDir . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");

printf("\n  profile      %s (seed %d)\n", $manifest['profile'], $manifest['seed']);
printf("  posts        %d   pages %d   comments %d\n",
    $content['posts'] ?? 0, $content['pages'] ?? 0, $content['comments'] ?? 0);
printf("  attachments  %d originals -> %d files on disk\n", count($attachments), $uploadFiles);
printf("  formats      %d jpeg (%.1f MB), %d png (%.1f MB)\n",
    $byFormat['jpeg']['count'], $byFormat['jpeg']['bytes'] / 1048576,
    $byFormat['png']['count'], $byFormat['png']['bytes'] / 1048576);
if ($woo['products']) {
    printf("  woocommerce  %d products, %d orders\n", $woo['products'], $woo['orders']);
}
if (!empty($elementor['pages'])) {
    printf("  elementor    %d pages, %d widgets, %.0f KB of document JSON\n",
        $elementor['pages'], $elementor['widgets'] ?? 0, ($elementor['data_bytes'] ?? 0) / 1024);
}
printf("  plugins      %d active: %s\n", count($plugins), implode(', ', array_keys($plugins)));
printf("  uploads      %.1f MB\n", $uploadBytes / 1048576);
printf("  database     %.1f MB (%d posts rows, %d postmeta rows)\n",
    $dbBytes / 1048576, $rowCounts['posts'], $rowCounts['postmeta']);
printf("  total        %.1f MB (target %d MB)\n", ($uploadBytes + $dbBytes) / 1048576, $manifest['target_size_mb']);
printf("  pixels hash  %s\n", $manifest['pixels_hash']);
printf("\n  manifest     %s\n\n", $out);
