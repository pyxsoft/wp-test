<?php
/**
 * Step 50 — Elementor pages.
 *
 * Runs under wp-cli: `wp eval-file steps/50-elementor.php`
 *
 * Creates landing-style pages whose content lives in `_elementor_data`, so the
 * front end has to rebuild them from JSON on every uncached request. That is
 * the PHP cost a plain-post fixture never exercises.
 *
 * Not sharded: the page count is small (tens, not thousands) and the work is
 * inserts, not image processing.
 */

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/text.php';
require_once __DIR__ . '/../lib/elementor.php';

$config = Config::load();

if (!defined('ELEMENTOR_VERSION') && !wp_test_elementor_active()) {
    wp_test_log('Elementor is not active — skipping step 50');
    return;
}

ini_set('memory_limit', '512M');
set_time_limit(0);

$pageCount = $config->int('ELEMENTOR_PAGES', 0);
if ($pageCount < 1) {
    wp_test_log('ELEMENTOR_PAGES is 0 — skipping step 50');
    return;
}

$minSections = $config->int('ELEMENTOR_MIN_SECTIONS', 6);
$maxSections = $config->int('ELEMENTOR_MAX_SECTIONS', 18);
$version = defined('ELEMENTOR_VERSION') ? ELEMENTOR_VERSION : '3.25.0';

$started = microtime(true);

$media = [];
foreach (get_posts([
    'post_type'      => 'attachment',
    'post_status'    => 'inherit',
    'post_mime_type' => 'image/jpeg',
    'posts_per_page' => -1,
    'orderby'        => 'ID',
    'order'          => 'ASC',
    'fields'         => 'ids',
]) as $id) {
    $media[] = ['id' => (int) $id, 'url' => wp_get_attachment_url($id)];
}

$titles = ['Home', 'Services', 'Solutions', 'Our work', 'Portfolio', 'Team', 'Process',
    'Testimonials', 'Industries', 'Technology', 'Approach', 'Consulting', 'Managed hosting',
    'Migration services', 'Security audit', 'Performance review', 'Get a quote', 'Book a demo',
    'Enterprise', 'Startups', 'Agencies', 'Resources', 'Guides', 'Webinars', 'Events',
    'Partners', 'Careers', 'Culture', 'Locations', 'Contact sales'];

$created = [];
$totalWidgets = 0;
$totalBytes = 0;

for ($i = 0; $i < $pageCount; $i++) {
    $rng = $config->rng("elementor:$i");
    $text = new TextGen($rng);
    $gen = new ElementorGen($rng, $text, $version);

    $sections = $rng->int($minSections, $maxSections);
    $images = $media ? $rng->sample($media, min(count($media), $rng->int(3, 12))) : [];
    $document = $gen->document($sections, $images);

    $title = $titles[$i % count($titles)];
    if ($i >= count($titles)) {
        $title .= ' ' . (int) floor($i / count($titles) + 1);
    }

    $postId = wp_insert_post([
        'post_type'    => 'page',
        'post_status'  => 'publish',
        'post_title'   => $title,
        'post_name'    => sanitize_title($title),
        // Deliberately empty: Elementor renders from the meta, and leaving real
        // content here would mask a document that failed to load.
        'post_content' => '',
        'post_date'    => $config->dateFor($i, max(1, $pageCount), 500, $rng),
    ], true);

    if (is_wp_error($postId)) {
        wp_test_log('elementor page insert failed: ' . $postId->get_error_message());
        continue;
    }

    $gen->attach((int) $postId, $document);

    if ($images) {
        set_post_thumbnail((int) $postId, $images[0]['id']);
    }

    $widgets = wp_test_count_widgets($document);
    $totalWidgets += $widgets;
    $totalBytes += strlen((string) wp_json_encode($document));
    $created[] = ['id' => (int) $postId, 'title' => $title, 'sections' => $sections, 'widgets' => $widgets];

    if (($i + 1) % 10 === 0) {
        wp_test_log(sprintf('elementor %d/%d pages (%.1fs)', $i + 1, $pageCount, microtime(true) - $started));
    }
}

// Front page: an agency site's home is a builder page, and it is the URL every
// benchmark hits first.
if ($created) {
    update_option('show_on_front', 'page');
    update_option('page_on_front', $created[0]['id']);

    // Switching the front page to a static one leaves the post archive with no
    // URL at all. Benchmarks need it: the blog index is the cheapest cacheable
    // page with many thumbnails on it, which is a different workload from both
    // a builder page and a single post.
    $blogId = wp_insert_post([
        'post_type'    => 'page',
        'post_status'  => 'publish',
        'post_title'   => 'Blog',
        'post_name'    => 'blog',
        'post_content' => '',
    ], true);
    if (!is_wp_error($blogId)) {
        update_option('page_for_posts', (int) $blogId);
    }

    wp_test_log(sprintf(
        'front page set to "%s" (#%d), post archive at /blog/',
        $created[0]['title'],
        $created[0]['id']
    ));
}

// Elementor caches per-post CSS in uploads. Clearing it here means the first
// benchmark request regenerates it, which is the honest cold-cache behaviour;
// leaving stale files would flatter the warm numbers.
if (class_exists('\Elementor\Plugin')) {
    try {
        \Elementor\Plugin::$instance->files_manager->clear_cache();
        wp_test_log('elementor css cache cleared');
    } catch (Throwable $e) {
        wp_test_log('could not clear elementor cache: ' . $e->getMessage());
    }
}

file_put_contents($config->stateDir() . '/elementor.json', json_encode([
    'pages'       => count($created),
    'widgets'     => $totalWidgets,
    'data_bytes'  => $totalBytes,
    'documents'   => $created,
], JSON_PRETTY_PRINT));

wp_test_log(sprintf(
    'elementor done: %d pages, %d widgets, %.1f KB of document JSON, %.1fs',
    count($created),
    $totalWidgets,
    $totalBytes / 1024,
    microtime(true) - $started
));

function wp_test_count_widgets(array $tree): int
{
    $count = 0;
    foreach ($tree as $node) {
        if (($node['elType'] ?? '') === 'widget') {
            $count++;
        }
        if (!empty($node['elements'])) {
            $count += wp_test_count_widgets($node['elements']);
        }
    }
    return $count;
}

/** Elementor defines its constant late; fall back to the plugin list. */
function wp_test_elementor_active(): bool
{
    $active = (array) get_option('active_plugins', []);
    foreach ($active as $plugin) {
        if (strpos($plugin, 'elementor/') === 0) {
            return true;
        }
    }
    return false;
}
