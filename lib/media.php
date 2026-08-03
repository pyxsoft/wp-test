<?php
/**
 * Deterministic image generator built on GD.
 *
 * Why GD and not ImageMagick/Pillow: this runs on the machine under test, which
 * is a hosting server. GD is guaranteed present — WordPress needs GD or Imagick
 * to produce thumbnails at all — so the generator adds no dependencies to a box
 * that can already serve WordPress.
 *
 * The hard part is not drawing an image, it is drawing one that JPEG cannot
 * compress away. A 4000x3000 smooth gradient encodes to ~150 KB, which is
 * useless for a bandwidth or cache benchmark. Real photos are heavy because of
 * high-frequency detail, so we synthesise that explicitly:
 *
 *   1. Low-frequency base: a tiny noise image bicubic-scaled to full size,
 *      giving smooth colour fields (cheap: ~2k setpixel calls).
 *   2. High-frequency detail: one noise tile stamped across the whole canvas
 *      with imagecopymerge (cheap: the tile is built once, the stamping is C).
 *      This is what makes the file big — JPEG codes each 8x8 block on its own,
 *      so repeating the tile costs just as many bits as unique noise would.
 *   3. Structure: translucent ellipses and bands, so the result reads as an
 *      image rather than as static, and the DCT has real edges to deal with.
 *
 * Cost is ~0.3-1 s per 4000x3000 image, which keeps the heavy profile in the
 * tens of minutes rather than the tens of hours a per-pixel PHP loop would need.
 */

require_once __DIR__ . '/rng.php';

final class MediaGen
{
    /** Detail tile edge, in pixels. Built once per image, then stamped. */
    private const TILE = 128;

    /** Base noise grid; scaled up to the full canvas for the colour fields. */
    private const BASE = 24;

    private string $lastPixelHash = '';

    public function __construct(
        private int $quality = 88,
        private int $detail = 14
    ) {
    }

    /**
     * Render one image and write it.
     *
     * @param string $style  One of: photo, portrait, product, screenshot, graphic.
     * @param string $format 'jpeg' or 'png'.
     * @return int Bytes written.
     */
    public function write(
        string $path,
        int $width,
        int $height,
        string $style,
        Rng $rng,
        string $caption = '',
        string $format = 'jpeg'
    ): int {
        // Vary detail and quality per image. Without this every original comes
        // out within 1% of the same size, which no real media library does —
        // and a fixture with uniform file sizes hides exactly the behaviour a
        // bandwidth or cache benchmark is trying to expose.
        $detail = (int) round($this->detail * (0.45 + $rng->float() * 1.25));
        $detail = max(2, min(40, $detail));
        $quality = max(60, min(96, $this->quality + $rng->int(-8, 6)));

        if ($format === 'png') {
            // PNG is lossless, so noise does not cost a few percent here — it
            // costs multiples. A screenshot-like PNG with flat colour lands
            // around 1 MB; the same frame full of noise lands at 25 MB and
            // would blow the profile's size budget on a handful of files.
            $detail = (int) round($detail * 0.15);
        }

        $img = $this->render($width, $height, $style, $rng, $caption, $detail);

        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException("cannot create directory: $dir");
        }

        $written = $format === 'png'
            ? imagepng($img, $path, 6)
            : imagejpeg($img, $path, $quality);

        if (!$written) {
            imagedestroy($img);
            throw new RuntimeException("cannot write image: $path");
        }

        // Hash before releasing the image. Reading the file back and decoding it
        // again would cost as much as drawing it did, on the slowest step of the
        // whole build.
        $this->lastPixelHash = $this->hashImage($img);

        imagedestroy($img);

        clearstatcache(true, $path);
        return (int) filesize($path);
    }

    /**
     * Pixel hash of the image most recently written by write().
     *
     * Taken from the in-memory image, before encoding. That is deliberate and
     * it is the value the manifest reports: it answers "did the generator draw
     * the same thing on both servers?" without dragging in the encoder. Hashing
     * the saved JPEG instead would make the answer depend on the server's
     * libjpeg version and report drift where the generator agreed perfectly.
     */
    public function lastPixelHash(): string
    {
        return $this->lastPixelHash;
    }

    /**
     * Checksum of the pixels of a file already on disk.
     *
     * Note this is NOT the same value as lastPixelHash(): this one decodes the
     * saved file, so for a JPEG it includes the losses of the encode. Use it to
     * inspect an existing file; use lastPixelHash() to compare two builds.
     */
    public function pixelHash(string $path): string
    {
        $data = @file_get_contents($path);
        if ($data === false) {
            return '';
        }
        $img = @imagecreatefromstring($data);
        unset($data);
        if ($img === false) {
            return '';
        }
        $hash = $this->hashImage($img);
        imagedestroy($img);
        return $hash;
    }

    /**
     * Sample the pixels on a fixed grid and hash them.
     *
     * Sampled rather than exhaustive: a full 4800x3600 read in PHP would take
     * longer than drawing the image, and a 64x64 grid already catches any real
     * divergence between two builds.
     */
    private function hashImage($img): string
    {
        $w = imagesx($img);
        $h = imagesy($img);
        $ctx = hash_init('sha256');
        $step = max(1, (int) (min($w, $h) / 64));
        for ($y = 0; $y < $h; $y += $step) {
            $row = '';
            for ($x = 0; $x < $w; $x += $step) {
                $row .= pack('N', imagecolorat($img, $x, $y));
            }
            hash_update($ctx, $row);
        }
        return substr(hash_final($ctx), 0, 16);
    }

    private function render(int $width, int $height, string $style, Rng $rng, string $caption, int $detail)
    {
        $palette = $this->palette($style, $rng);

        $img = $this->base($width, $height, $style, $palette, $rng);
        $this->structure($img, $width, $height, $style, $palette, $rng);
        $this->stampDetail($img, $width, $height, $rng, $detail);

        if ($caption !== '') {
            $this->caption($img, $width, $height, $caption);
        }

        return $img;
    }

    /** Colour anchors per style, so a product shot doesn't look like a landscape. */
    private function palette(string $style, Rng $rng): array
    {
        $hue = $rng->float();
        $spread = match ($style) {
            'product'    => 0.06,
            'portrait'   => 0.10,
            'screenshot' => 0.04,
            'graphic'    => 0.30,
            default      => 0.22,
        };
        $sat = match ($style) {
            'product'    => $rng->float() * 0.25 + 0.10,
            'screenshot' => $rng->float() * 0.20 + 0.05,
            'graphic'    => $rng->float() * 0.30 + 0.55,
            default      => $rng->float() * 0.45 + 0.35,
        };
        $val = match ($style) {
            'product'    => $rng->float() * 0.15 + 0.80,
            'screenshot' => $rng->float() * 0.10 + 0.88,
            'graphic'    => $rng->float() * 0.20 + 0.75,
            default      => $rng->float() * 0.35 + 0.45,
        };

        $colors = [];
        for ($i = 0; $i < 5; $i++) {
            $h = fmod($hue + $rng->gauss(0.0, $spread) + 1.0, 1.0);
            $colors[] = $this->hsvToRgb($h, $sat * (0.7 + $rng->float() * 0.6), $val * (0.7 + $rng->float() * 0.6));
        }
        return $colors;
    }

    /** Low-frequency colour fields: tiny noise grid scaled up with bicubic. */
    private function base(int $width, int $height, string $style, array $palette, Rng $rng)
    {
        // Flat graphics get a flat ground. A bicubic-scaled noise grid produces
        // thousands of subtly different colours, which is invisible in a JPEG
        // and catastrophic in a PNG — lossless encoding has to store every one.
        if ($style === 'graphic' || $style === 'screenshot') {
            $img = imagecreatetruecolor($width, $height);
            [$r, $g, $b] = $palette[0];
            imagefilledrectangle($img, 0, 0, $width, $height, imagecolorallocate($img, $r, $g, $b));
            return $img;
        }

        $bw = self::BASE;
        $bh = max(2, (int) round(self::BASE * $height / max(1, $width)));

        $small = imagecreatetruecolor($bw, $bh);
        for ($y = 0; $y < $bh; $y++) {
            for ($x = 0; $x < $bw; $x++) {
                [$r, $g, $b] = $palette[$rng->int(0, count($palette) - 1)];
                $jitter = static fn(int $c): int => max(0, min(255, $c + (int) round($rng->gauss(0, 18))));
                imagesetpixel($small, $x, $y, imagecolorallocate($small, $jitter($r), $jitter($g), $jitter($b)));
            }
        }

        $big = imagescale($small, $width, $height, IMG_BICUBIC);
        imagedestroy($small);

        if ($big === false) {
            // imagescale can fail on very large targets under a low memory_limit.
            throw new RuntimeException("imagescale failed for {$width}x{$height}; raise PHP memory_limit");
        }
        return $big;
    }

    /** Translucent shapes so the frame has composition and hard edges. */
    private function structure($img, int $width, int $height, string $style, array $palette, Rng $rng): void
    {
        $shapes = match ($style) {
            'product'    => $rng->int(2, 4),
            'screenshot' => $rng->int(6, 12),
            'graphic'    => $rng->int(4, 9),
            default      => $rng->int(5, 11),
        };

        for ($i = 0; $i < $shapes; $i++) {
            [$r, $g, $b] = $palette[$rng->int(0, count($palette) - 1)];
            // Flat graphics use opaque fills: large areas of a single colour are
            // what makes a PNG compress the way a real diagram or logo does.
            $alpha = $style === 'graphic' ? $rng->int(0, 25) : $rng->int(35, 95);
            $color = imagecolorallocatealpha($img, $r, $g, $b, $alpha);

            if ($style === 'graphic') {
                if ($rng->bool(0.5)) {
                    $x = $rng->int(0, $width);
                    $y = $rng->int(0, $height);
                    imagefilledrectangle($img, $x, $y, $x + $rng->int(100, (int) ($width * 0.5)),
                        $y + $rng->int(60, (int) ($height * 0.4)), $color);
                } else {
                    imagefilledellipse($img, $rng->int(0, $width), $rng->int(0, $height),
                        $rng->int(80, (int) ($width * 0.5)), $rng->int(80, (int) ($height * 0.5)), $color);
                }
                continue;
            }

            if ($style === 'screenshot') {
                // Rectangular bands read as UI chrome.
                $x = $rng->int(0, $width);
                $y = $rng->int(0, $height);
                imagefilledrectangle($img, $x, $y, $x + $rng->int(80, (int) ($width * 0.7)), $y + $rng->int(20, (int) ($height * 0.15)), $color);
                continue;
            }

            $cx = $rng->int(0, $width);
            $cy = $rng->int(0, $height);
            $rw = $rng->int((int) ($width * 0.15), (int) ($width * 0.85));
            $rh = $rng->int((int) ($height * 0.15), (int) ($height * 0.85));
            imagefilledellipse($img, $cx, $cy, $rw, $rh, $color);
        }

        // A horizon line gives landscape shots a believable structure.
        if ($style === 'photo' && $rng->bool(0.6)) {
            [$r, $g, $b] = $palette[0];
            $line = imagecolorallocatealpha($img, $r, $g, $b, 60);
            $hy = $rng->int((int) ($height * 0.35), (int) ($height * 0.7));
            imagefilledrectangle($img, 0, $hy, $width, $hy + $rng->int(2, (int) ($height * 0.02)), $line);
        }
    }

    /**
     * Stamp a noise tile across the canvas.
     *
     * This is the step that decides the file size. $detail is the merge
     * percentage: 0 gives a smooth (tiny) file, 25 gives coarse static. The
     * profiles tune it together with JPEG quality to hit their size targets.
     */
    private function stampDetail($img, int $width, int $height, Rng $rng, int $detail): void
    {
        if ($detail <= 0) {
            return;
        }

        $tile = imagecreatetruecolor(self::TILE, self::TILE);
        for ($y = 0; $y < self::TILE; $y++) {
            for ($x = 0; $x < self::TILE; $x++) {
                $v = $rng->int(0, 255);
                // Slight channel decorrelation: chroma noise survives JPEG's
                // chroma subsampling worse, which is realistic for photos.
                $c = imagecolorallocate(
                    $tile,
                    $v,
                    max(0, min(255, $v + $rng->int(-24, 24))),
                    max(0, min(255, $v + $rng->int(-24, 24)))
                );
                imagesetpixel($tile, $x, $y, $c);
            }
        }

        for ($y = 0; $y < $height; $y += self::TILE) {
            for ($x = 0; $x < $width; $x += self::TILE) {
                $w = min(self::TILE, $width - $x);
                $h = min(self::TILE, $height - $y);
                imagecopymerge($img, $tile, $x, $y, 0, 0, $w, $h, $detail);
            }
        }

        imagedestroy($tile);
    }

    /** Burn an identifier into the frame so a screenshot tells you which asset it is. */
    private function caption($img, int $width, int $height, string $text): void
    {
        $shadow = imagecolorallocatealpha($img, 0, 0, 0, 40);
        $fg = imagecolorallocate($img, 255, 255, 255);
        $pad = max(8, (int) ($width * 0.01));
        $boxH = 30;
        imagefilledrectangle($img, 0, $height - $boxH - $pad, $width, $height, $shadow);
        imagestring($img, 5, $pad, $height - $boxH, substr($text, 0, 120), $fg);
    }

    private function hsvToRgb(float $h, float $s, float $v): array
    {
        $s = max(0.0, min(1.0, $s));
        $v = max(0.0, min(1.0, $v));
        $i = (int) floor($h * 6);
        $f = $h * 6 - $i;
        $p = $v * (1 - $s);
        $q = $v * (1 - $f * $s);
        $t = $v * (1 - (1 - $f) * $s);

        [$r, $g, $b] = match ($i % 6) {
            0 => [$v, $t, $p],
            1 => [$q, $v, $p],
            2 => [$p, $v, $t],
            3 => [$p, $q, $v],
            4 => [$t, $p, $v],
            default => [$v, $p, $q],
        };

        return [(int) round($r * 255), (int) round($g * 255), (int) round($b * 255)];
    }
}
