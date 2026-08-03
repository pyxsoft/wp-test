<?php
/**
 * Step 20 — media library.
 *
 * Runs under wp-cli (`wp eval-file`) so WordPress is already bootstrapped: the
 * attachments go in through wp_insert_attachment() and get their thumbnails
 * from wp_generate_attachment_metadata(), exactly as an upload through the
 * admin would. Importing with `wp media import` once per file would be correct
 * too, but it pays a full WordPress bootstrap per image — unbearable at 2500.
 *
 * Usage:
 *   wp eval-file steps/20-media.php --shard=1/4
 */

require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/media.php';
require_once __DIR__ . '/../lib/text.php';

if (!function_exists('wp_generate_attachment_metadata')) {
    require_once ABSPATH . 'wp-admin/includes/image.php';
}

$config = Config::load();
[$shardIndex, $shardCount] = wp_test_shard($args ?? []);

$total = $config->int('IMAGES');
$quality = $config->int('JPEG_QUALITY', 85);
$detail = $config->int('IMAGE_DETAIL', 14);
$minWidth = $config->int('IMAGE_MIN_WIDTH', 1600);
$maxWidth = $config->int('IMAGE_MAX_WIDTH', 2400);
$thumbnails = $config->str('THUMBNAILS', 'full');

// 4800x3600 in GD is ~69 MB per buffer, and imagescale holds two at once.
ini_set('memory_limit', '1024M');
set_time_limit(0);

$gen = new MediaGen($quality, $detail);
$text = new TextGen($config->rng('media-text'));

// Aspect ratios worth having in the library: landscape dominates, but a few
// portraits and squares make the thumbnail pass do real work.
$ratios = [
    ['name' => 'landscape', 'ratio' => 3 / 2,  'weight' => 55],
    ['name' => 'wide',      'ratio' => 16 / 9, 'weight' => 20],
    ['name' => 'square',    'ratio' => 1.0,    'weight' => 13],
    ['name' => 'portrait',  'ratio' => 2 / 3,  'weight' => 12],
];

$styles = ['photo', 'photo', 'photo', 'portrait', 'product', 'screenshot'];

// PNG share of the library.
//
// This is not cosmetic. Whether a server converts an image to WebP depends on
// the source format: a lossless PNG almost always shrinks a lot, while a JPEG
// is already compressed and the saving is smaller, so engines apply a
// worthwhile-ness threshold that PNGs clear more easily. A fixture made only of
// JPEGs cannot tell you whether PNG conversion works, and vice versa — so the
// library carries both, in a ratio close to what a real WordPress site has.
$pngShare = max(0, min(100, $config->int('PNG_SHARE', 20)));

// Styles that read as flat graphics: these are what a real site stores as PNG
// (screenshots, diagrams, logos) and what compresses sanely in a lossless
// format. Photographic noise in a PNG would produce 25 MB files.
$pngStyles = ['screenshot', 'graphic', 'graphic'];

$records = [];
$bytesTotal = 0;
$made = 0;
$started = microtime(true);

for ($i = 0; $i < $total; $i++) {
    if ($i % $shardCount !== $shardIndex) {
        continue;
    }

    // Per-image stream: image 900 does not depend on images 1..899 existing,
    // which is what lets the shards run in parallel and still agree.
    $rng = $config->rng("media:$i");

    $ratio = wp_test_weighted_pick($ratios, $rng);
    $width = $rng->int($minWidth, $maxWidth);
    $height = max(200, (int) round($width / $ratio['ratio']));

    $isPng = $rng->int(1, 100) <= $pngShare;
    $style = $isPng ? $rng->pick($pngStyles) : $rng->pick($styles);
    $format = $isPng ? 'png' : 'jpeg';
    $extension = $isPng ? 'png' : 'jpg';
    $mime = $isPng ? 'image/png' : 'image/jpeg';

    // PNG originals are capped: lossless encoding of a 4800px frame produces a
    // file nothing on a real site would carry, and it would eat the profile's
    // whole size budget in a handful of images.
    if ($isPng && $width > 2400) {
        $width = $rng->int(1200, 2400);
        $height = max(200, (int) round($width / $ratio['ratio']));
    }

    $date = $config->dateFor($i, $total, 900, $rng);
    $uploads = wp_upload_dir($date);
    if (!empty($uploads['error'])) {
        wp_test_log('uploads dir error: ' . $uploads['error']);
        exit(1);
    }

    $slug = sprintf('%s-%04d-%s', $ratio['name'], $i, $style);
    $filename = wp_unique_filename($uploads['path'], $slug . '.' . $extension);
    $path = $uploads['path'] . '/' . $filename;
    $url = $uploads['url'] . '/' . $filename;

    $caption = sprintf('wp-test %s #%04d %dx%d', $config->str('PROFILE_NAME'), $i, $width, $height);
    $bytes = $gen->write($path, $width, $height, $style, $rng, $caption, $format);

    $attachmentId = wp_insert_attachment([
        'guid'           => $url,
        'post_mime_type' => $mime,
        'post_title'     => ucfirst($text->title()),
        'post_content'   => '',
        'post_excerpt'   => $text->excerpt(),
        'post_status'    => 'inherit',
        'post_date'      => $date,
        'post_date_gmt'  => $date,
    ], $path);

    if (is_wp_error($attachmentId) || !$attachmentId) {
        wp_test_log("failed to insert attachment for $path");
        continue;
    }

    update_post_meta($attachmentId, '_wp_attachment_image_alt', $text->excerpt());

    if ($thumbnails !== 'none') {
        // The expensive part of this step by a wide margin, and the part that
        // makes the on-disk size realistic: every registered size is rendered.
        $meta = wp_generate_attachment_metadata($attachmentId, $path);
        wp_update_attachment_metadata($attachmentId, $meta);
    }

    $records[] = [
        'index'  => $i,
        'id'     => $attachmentId,
        'file'   => str_replace(ABSPATH, '', $path),
        'w'      => $width,
        'h'      => $height,
        'style'  => $style,
        'format' => $format,
        'bytes'  => $bytes,
        'pixels' => $gen->lastPixelHash(),
    ];

    $bytesTotal += $bytes;
    $made++;

    if ($made % 25 === 0) {
        $elapsed = microtime(true) - $started;
        wp_test_log(sprintf(
            'media %d/%d  %.1f MB originals  %.1fs  (%.2f s/img)',
            $made,
            (int) ceil($total / $shardCount),
            $bytesTotal / 1048576,
            $elapsed,
            $elapsed / max(1, $made)
        ));
    }
}

$statePath = sprintf('%s/media-%d.json', $config->stateDir(), $shardIndex);
file_put_contents($statePath, json_encode([
    'shard'       => "$shardIndex/$shardCount",
    'count'       => $made,
    'bytes'       => $bytesTotal,
    'attachments' => $records,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

wp_test_log(sprintf(
    'media done: %d images, %.1f MB of originals, %.1fs',
    $made,
    $bytesTotal / 1048576,
    microtime(true) - $started
));

/** Weighted choice that consumes exactly one value from the stream. */
function wp_test_weighted_pick(array $items, Rng $rng): array
{
    $sum = 0;
    foreach ($items as $item) {
        $sum += $item['weight'];
    }
    $roll = $rng->int(1, max(1, $sum));
    foreach ($items as $item) {
        $roll -= $item['weight'];
        if ($roll <= 0) {
            return $item;
        }
    }
    return $items[0];
}
