<?php
/**
 * Step 30 — taxonomies, pages, posts, comments and navigation.
 *
 * Runs under wp-cli: `wp eval-file steps/30-content.php`
 *
 * This step is not sharded. Posts reference attachments and terms by ID and
 * comments hang off posts, so a single ordered pass keeps the resulting
 * database identical between runs. It is also cheap next to the media step —
 * inserts, not image processing.
 */

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/text.php';

$config = Config::load();
ini_set('memory_limit', '512M');
set_time_limit(0);

$started = microtime(true);

// Bulk-insert hygiene: without these, every insert recounts comments and
// rebuilds term caches, which turns a 1500-post run into a very long one.
wp_defer_comment_counting(true);
wp_defer_term_counting(true);
wp_suspend_cache_invalidation(true);

$text = new TextGen($config->rng('content'));

/* ---------------------------------------------------------------- taxonomies */

$categoryNames = [
    'Performance', 'Security', 'Hosting', 'Databases', 'Networking', 'Email',
    'DNS', 'Storage', 'Automation', 'Monitoring', 'Caching', 'TLS', 'Backups',
    'WordPress', 'PHP', 'Linux', 'Containers', 'CDN', 'Firewalls', 'Migrations',
    'Benchmarks', 'Tooling', 'Scaling', 'Reliability', 'Cost control', 'Compliance',
    'Observability', 'Load balancing', 'Filesystems', 'Kernel', 'Queues', 'Search',
    'Analytics', 'Logging', 'Provisioning', 'Recovery', 'Capacity', 'Latency',
    'Deployment', 'Configuration',
];

$categoryIds = [];
$wanted = min($config->int('CATEGORIES', 6), count($categoryNames));
for ($i = 0; $i < $wanted; $i++) {
    $term = wp_insert_term($categoryNames[$i], 'category');
    if (is_wp_error($term)) {
        $existing = get_term_by('name', $categoryNames[$i], 'category');
        if ($existing) {
            $categoryIds[] = (int) $existing->term_id;
        }
        continue;
    }
    $categoryIds[] = (int) $term['term_id'];
}

$tagIds = [];
$tagRng = $config->rng('tags');
$tagCount = $config->int('TAGS', 20);
for ($i = 0; $i < $tagCount; $i++) {
    $name = sprintf('%s-%02d', $tagRng->pick(['tuning', 'howto', 'deep-dive', 'field-notes',
        'postmortem', 'reference', 'quickstart', 'gotcha', 'checklist', 'teardown']), $i);
    $term = wp_insert_term($name, 'post_tag');
    if (!is_wp_error($term)) {
        $tagIds[] = (int) $term['term_id'];
    }
}

wp_test_log(sprintf('taxonomies: %d categories, %d tags', count($categoryIds), count($tagIds)));

/* ------------------------------------------------------------------ media map */

$attachments = get_posts([
    'post_type'      => 'attachment',
    'post_status'    => 'inherit',
    'posts_per_page' => -1,
    'orderby'        => 'ID',
    'order'          => 'ASC',
    'fields'         => 'ids',
]);

$media = [];
foreach ($attachments as $id) {
    $media[] = ['id' => (int) $id, 'url' => wp_get_attachment_url($id)];
}

if (!$media) {
    wp_test_log('warning: no attachments found — run step 20 first for a media-heavy site');
}

/* ---------------------------------------------------------------------- pages */

$pageTitles = ['About', 'Contact', 'Privacy Policy', 'Terms of Service', 'Documentation',
    'Pricing', 'Support', 'Changelog', 'Status', 'Careers', 'Partners', 'Press kit',
    'Roadmap', 'Security', 'Accessibility', 'Cookie Policy', 'Refunds', 'Imprint',
    'Case studies', 'Integrations', 'Glossary', 'FAQ', 'Downloads', 'Training', 'Community'];

$pageIds = [];
$pageCount = min($config->int('PAGES', 5), count($pageTitles));
for ($i = 0; $i < $pageCount; $i++) {
    $rng = $config->rng("page:$i");
    $pageText = new TextGen($rng);
    $images = $media ? $rng->sample($media, $rng->int(0, 3)) : [];

    $id = wp_insert_post([
        'post_type'    => 'page',
        'post_status'  => 'publish',
        'post_title'   => $pageTitles[$i],
        'post_name'    => sanitize_title($pageTitles[$i]),
        'post_content' => $pageText->body($rng->int(5, 12), $images),
        'post_date'    => $config->dateFor($i, max(1, $pageCount), 700, $rng),
    ], true);

    if (!is_wp_error($id)) {
        $pageIds[$pageTitles[$i]] = (int) $id;
        if ($images && $rng->bool(0.5)) {
            set_post_thumbnail($id, $images[0]['id']);
        }
    }
}

wp_test_log(sprintf('pages: %d', count($pageIds)));

/* ---------------------------------------------------------------------- posts */

$postCount = $config->int('POSTS', 25);
$minBlocks = $config->int('BODY_MIN_BLOCKS', 6);
$maxBlocks = $config->int('BODY_MAX_BLOCKS', 14);
$maxPostImages = $config->int('IMAGES_PER_POST_MAX', 3);
$maxComments = $config->int('COMMENTS_MAX', 4);

$authors = [];
$authorRng = $config->rng('authors');
$authorCount = max(1, min(8, (int) ceil($postCount / 40)));
for ($i = 0; $i < $authorCount; $i++) {
    $login = 'author' . ($i + 1);
    $existing = get_user_by('login', $login);
    if ($existing) {
        $authors[] = (int) $existing->ID;
        continue;
    }
    $name = $authorRng->pick(['Alex Ferrer', 'Marta Mendez', 'Diego Rojas', 'Sofia Duarte',
        'Tomas Salas', 'Elena Vega', 'Ivan Prieto', 'Clara Nieto']);
    $uid = wp_insert_user([
        'user_login'   => $login,
        'user_pass'    => wp_generate_password(24, true),
        'user_email'   => $login . '@wp-test.invalid',
        'display_name' => $name,
        'role'         => 'author',
    ]);
    if (!is_wp_error($uid)) {
        $authors[] = (int) $uid;
    }
}
if (!$authors) {
    $authors = [1];
}

$postIds = [];
$commentTotal = 0;
$mediaCursor = 0;

for ($i = 0; $i < $postCount; $i++) {
    $rng = $config->rng("post:$i");
    $postText = new TextGen($rng);

    // Walk the library in order rather than sampling: every image ends up used
    // by something, so nothing sits on disk that the site never requests.
    $take = $media ? $rng->int(1, max(1, $maxPostImages)) : 0;
    $images = [];
    for ($k = 0; $k < $take && $media; $k++) {
        $images[] = $media[($mediaCursor + $k) % count($media)];
    }
    $mediaCursor += max(1, $take);

    $date = $config->dateFor($i, $postCount, 1000, $rng);

    $id = wp_insert_post([
        'post_type'    => 'post',
        'post_status'  => 'publish',
        'post_title'   => $postText->title(),
        'post_content' => $postText->body($rng->int($minBlocks, $maxBlocks), $images),
        'post_excerpt' => $postText->excerpt(),
        'post_author'  => $rng->pick($authors),
        'post_date'    => $date,
        'comment_status' => 'open',
    ], true);

    if (is_wp_error($id)) {
        wp_test_log('post insert failed: ' . $id->get_error_message());
        continue;
    }
    $id = (int) $id;
    $postIds[] = $id;

    if ($categoryIds) {
        wp_set_object_terms($id, $rng->sample($categoryIds, $rng->int(1, 3)), 'category');
    }
    if ($tagIds) {
        wp_set_object_terms($id, $rng->sample($tagIds, $rng->int(1, 5)), 'post_tag');
    }
    if ($images) {
        set_post_thumbnail($id, $images[0]['id']);
    }

    $comments = $rng->int(0, $maxComments);
    for ($c = 0; $c < $comments; $c++) {
        wp_insert_comment([
            'comment_post_ID'      => $id,
            'comment_author'       => $postText->authorName(),
            'comment_author_email' => sprintf('reader%d@wp-test.invalid', $rng->int(1, 500)),
            'comment_content'      => $postText->comment(),
            'comment_approved'     => 1,
            'comment_date'         => date('Y-m-d H:i:s', strtotime($date) + $rng->int(3600, 864000)),
        ]);
        $commentTotal++;
    }

    if (($i + 1) % 100 === 0) {
        wp_test_log(sprintf('posts %d/%d  (%.1fs)', $i + 1, $postCount, microtime(true) - $started));
    }
}

wp_test_log(sprintf('posts: %d, comments: %d', count($postIds), $commentTotal));

/* ----------------------------------------------------------------- navigation */

$menuName = 'wp-test-primary';
$menu = wp_get_nav_menu_object($menuName);
$menuId = $menu ? (int) $menu->term_id : (int) wp_create_nav_menu($menuName);

if ($menuId && !is_wp_error($menuId)) {
    $items = 0;
    $limit = $config->int('MENU_ITEMS', 6);

    foreach ($pageIds as $title => $pageId) {
        if ($items >= $limit) {
            break;
        }
        wp_update_nav_menu_item($menuId, 0, [
            'menu-item-title'     => $title,
            'menu-item-object-id' => $pageId,
            'menu-item-object'    => 'page',
            'menu-item-type'      => 'post_type',
            'menu-item-status'    => 'publish',
        ]);
        $items++;
    }

    foreach ($categoryIds as $categoryId) {
        if ($items >= $limit) {
            break;
        }
        wp_update_nav_menu_item($menuId, 0, [
            'menu-item-title'     => get_cat_name($categoryId),
            'menu-item-object-id' => $categoryId,
            'menu-item-object'    => 'category',
            'menu-item-type'      => 'taxonomy',
            'menu-item-status'    => 'publish',
        ]);
        $items++;
    }

    $locations = get_registered_nav_menus();
    if ($locations) {
        $assigned = get_theme_mod('nav_menu_locations', []);
        foreach (array_keys($locations) as $location) {
            $assigned[$location] = $menuId;
        }
        set_theme_mod('nav_menu_locations', $assigned);
    }
}

/* -------------------------------------------------------------------- cleanup */

wp_suspend_cache_invalidation(false);
wp_defer_comment_counting(false);
wp_defer_term_counting(false);

if ($categoryIds) {
    wp_update_term_count_now($categoryIds, 'category');
}
if ($tagIds) {
    wp_update_term_count_now($tagIds, 'post_tag');
}

file_put_contents($config->stateDir() . '/content.json', json_encode([
    'posts'      => count($postIds),
    'pages'      => count($pageIds),
    'comments'   => $commentTotal,
    'categories' => count($categoryIds),
    'tags'       => count($tagIds),
    'authors'    => count($authors),
], JSON_PRETTY_PRINT));

wp_test_log(sprintf('content done in %.1fs', microtime(true) - $started));
