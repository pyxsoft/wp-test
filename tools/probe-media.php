<?php
/**
 * Calibration probe for the image generator.
 *
 * Runs standalone — no WordPress, no database — so you can check on any box how
 * big the originals come out and how long they take before committing to a full
 * build. Use it when tuning JPEG_QUALITY and IMAGE_DETAIL in a profile.
 *
 *   php tools/probe-media.php [count] [width] [quality] [detail]
 *
 * The numbers that matter: MB per original (drives the disk footprint) and
 * seconds per image (drives the build time, multiplied by the thumbnail pass).
 */

require_once __DIR__ . '/../lib/rng.php';
require_once __DIR__ . '/../lib/media.php';

$count   = (int) ($argv[1] ?? 5);
$width   = (int) ($argv[2] ?? 3000);
$quality = (int) ($argv[3] ?? 88);
$detail  = (int) ($argv[4] ?? 14);

if (!extension_loaded('gd')) {
    fwrite(STDERR, "gd extension is not loaded — install php-gd\n");
    exit(1);
}

ini_set('memory_limit', '1024M');
set_time_limit(0);

$outDir = sys_get_temp_dir() . '/wp-test-probe';
if (!is_dir($outDir)) {
    mkdir($outDir, 0755, true);
}

$gen = new MediaGen($quality, $detail);

// Mirror what step 20 does: mostly photographic JPEG, a minority of flat PNG.
$plan = [
    ['photo', 'jpeg'], ['photo', 'jpeg'], ['portrait', 'jpeg'],
    ['product', 'jpeg'], ['graphic', 'png'], ['screenshot', 'png'],
];

printf("probe: %d images, %dpx wide, quality %d, detail %d\n", $count, $width, $quality, $detail);
printf("gd %s, php %s\n\n", GD_VERSION ?? '?', PHP_VERSION);
printf("  %-4s %-11s %-5s %-10s %8s %8s  %s\n", '#', 'style', 'fmt', 'size', 'MB', 'secs', 'pixel hash');

$totalBytes = 0;
$totalTime = 0.0;

for ($i = 0; $i < $count; $i++) {
    $rng = (new Rng(20260803))->fork("probe:$i");
    [$style, $format] = $plan[$i % count($plan)];

    // PNG originals are capped in step 20, so the probe caps them too.
    $w = ($format === 'png' && $width > 2400) ? 2000 : $width;
    $height = (int) round($w / 1.5);
    $path = sprintf('%s/probe-%02d.%s', $outDir, $i, $format === 'png' ? 'png' : 'jpg');

    $t0 = microtime(true);
    $bytes = $gen->write($path, $w, $height, $style, $rng, "probe #$i", $format);
    $elapsed = microtime(true) - $t0;

    $totalBytes += $bytes;
    $totalTime += $elapsed;

    printf(
        "  %-4d %-11s %-5s %-10s %8.2f %8.2f  %s\n",
        $i,
        $style,
        $format,
        $w . 'x' . $height,
        $bytes / 1048576,
        $elapsed,
        $gen->lastPixelHash()
    );
}

printf(
    "\n  average %.2f MB per original, %.2f s per image\n",
    $totalBytes / max(1, $count) / 1048576,
    $totalTime / max(1, $count)
);
printf("  1000 originals would be %.1f GB and %.0f min of drawing\n",
    ($totalBytes / max(1, $count)) * 1000 / 1073741824,
    ($totalTime / max(1, $count)) * 1000 / 60);
printf("  (thumbnails are extra, and usually cost more than the drawing)\n");
printf("\n  files in %s\n", $outDir);
