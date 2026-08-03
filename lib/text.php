<?php
/**
 * Deterministic text generator.
 *
 * Post bodies are not filler here: they decide how much HTML the theme renders,
 * how big the pages are on the wire, and how much work the database does. So
 * the output is shaped like real editorial content — headings, paragraphs,
 * lists, blockquotes, code blocks and inline links — rather than one long
 * lorem ipsum blob that gzip would flatten to nothing.
 */

require_once __DIR__ . '/rng.php';

final class TextGen
{
    private const WORDS = [
        'server', 'hosting', 'latency', 'cache', 'origin', 'request', 'response', 'throughput',
        'bandwidth', 'domain', 'record', 'certificate', 'renewal', 'mailbox', 'delivery', 'queue',
        'backup', 'restore', 'snapshot', 'archive', 'account', 'quota', 'package', 'reseller',
        'firewall', 'ruleset', 'signature', 'threshold', 'baseline', 'benchmark', 'workload',
        'concurrency', 'saturation', 'percentile', 'histogram', 'sampling', 'telemetry', 'metric',
        'container', 'runtime', 'process', 'worker', 'socket', 'handshake', 'protocol', 'header',
        'compression', 'payload', 'fragment', 'checksum', 'migration', 'schema', 'index', 'query',
        'planner', 'transaction', 'replica', 'failover', 'cluster', 'topology', 'routing', 'gateway',
        'template', 'directive', 'configuration', 'deployment', 'pipeline', 'artifact', 'release',
        'threshold', 'capacity', 'contention', 'allocation', 'fragmentation', 'eviction', 'warmup',
    ];

    private const CONNECTORS = [
        'however', 'in practice', 'by contrast', 'as a result', 'more importantly', 'in short',
        'on the other hand', 'crucially', 'for the same reason', 'in most deployments',
    ];

    private const TOPICS = [
        'Tuning', 'Measuring', 'Understanding', 'Debugging', 'Scaling', 'Hardening', 'Automating',
        'Migrating', 'Profiling', 'Benchmarking', 'Rethinking', 'Simplifying', 'Auditing',
    ];

    private const SUBJECTS = [
        'PHP-FPM pools', 'static asset delivery', 'TLS handshakes', 'DNS propagation',
        'mail queue backlogs', 'database connection limits', 'object caching', 'image pipelines',
        'HTTP keep-alive', 'reverse proxy buffering', 'disk quotas', 'cron scheduling',
        'log rotation', 'firewall rulesets', 'backup windows', 'WordPress cron', 'CDN cache keys',
        'origin shielding', 'session storage', 'opcode caches', 'file descriptor limits',
    ];

    public function __construct(private Rng $rng)
    {
    }

    /** A plausible article title. */
    public function title(): string
    {
        $t = $this->rng->pick(self::TOPICS);
        $s = $this->rng->pick(self::SUBJECTS);

        return $this->rng->bool(0.35)
            ? sprintf('%s %s: a practical guide', $t, $s)
            : sprintf('%s %s', $t, $s);
    }

    /** A short excerpt, one or two sentences. */
    public function excerpt(): string
    {
        return $this->sentence($this->rng->int(12, 24)) . ' ' . $this->sentence($this->rng->int(8, 18));
    }

    /**
     * A full post body in block markup.
     *
     * @param int $paragraphs Roughly how many top-level blocks to emit.
     * @param array<int,array{id:int,url:string}> $images Attachments to embed.
     */
    public function body(int $paragraphs, array $images = []): string
    {
        $out = [];
        $imageIdx = 0;

        $out[] = $this->paragraph($this->rng->int(4, 7));

        for ($i = 0; $i < $paragraphs; $i++) {
            $roll = $this->rng->float();

            if ($roll < 0.14) {
                $out[] = $this->heading();
            } elseif ($roll < 0.22) {
                $out[] = $this->listBlock();
            } elseif ($roll < 0.28) {
                $out[] = $this->quote();
            } elseif ($roll < 0.34) {
                $out[] = $this->code();
            } elseif ($roll < 0.46 && $imageIdx < count($images)) {
                $out[] = $this->imageBlock($images[$imageIdx]);
                $imageIdx++;
            } else {
                $out[] = $this->paragraph($this->rng->int(3, 8));
            }
        }

        // Any image we didn't place inline goes into a closing gallery: this is
        // what makes media-heavy pages actually request dozens of files.
        $remaining = array_slice($images, $imageIdx);
        if (count($remaining) >= 3) {
            $out[] = $this->gallery($remaining);
        }

        return implode("\n\n", $out);
    }

    /** WooCommerce product description: shorter, with a spec list. */
    public function productDescription(): string
    {
        return $this->paragraph($this->rng->int(3, 5)) . "\n\n" . $this->listBlock();
    }

    public function productName(): string
    {
        $adjectives = ['Compact', 'Rugged', 'Wireless', 'Modular', 'Insulated', 'Adjustable',
                       'Portable', 'Reinforced', 'Ultralight', 'Precision', 'Industrial', 'Vintage'];
        $nouns = ['Mount', 'Enclosure', 'Adapter', 'Toolkit', 'Bracket', 'Analyzer', 'Charger',
                  'Housing', 'Regulator', 'Harness', 'Cartridge', 'Module', 'Sensor', 'Cabinet'];
        $series = ['Pro', 'Mk II', 'X', 'Plus', 'Lite', 'HD', '2000', 'Edge'];

        $name = $this->rng->pick($adjectives) . ' ' . $this->rng->pick($nouns);
        if ($this->rng->bool(0.5)) {
            $name .= ' ' . $this->rng->pick($series);
        }
        return $name;
    }

    public function comment(): string
    {
        $openers = [
            'Ran into exactly this last week.',
            'Good write-up, though I would add one thing.',
            'Does this still hold on the latest release?',
            'We measured something similar in production.',
            'Thanks, this saved me a long afternoon.',
            'Not sure I agree with the conclusion here.',
        ];
        return $this->rng->pick($openers) . ' ' . $this->sentence($this->rng->int(10, 26));
    }

    public function authorName(): string
    {
        $first = ['Alex', 'Marta', 'Diego', 'Sofia', 'Tomas', 'Elena', 'Ivan', 'Clara', 'Hugo',
                  'Nadia', 'Pablo', 'Irene', 'Victor', 'Lucia', 'Andres', 'Paula'];
        $last = ['Ferrer', 'Mendez', 'Rojas', 'Duarte', 'Salas', 'Iglesias', 'Vega', 'Prieto',
                 'Carrasco', 'Nieto', 'Bermudez', 'Aguilar', 'Rivas', 'Cortes'];
        return $this->rng->pick($first) . ' ' . $this->rng->pick($last);
    }

    private function heading(): string
    {
        $level = $this->rng->bool(0.7) ? 2 : 3;
        $text = ucfirst($this->words($this->rng->int(3, 6)));
        return sprintf(
            "<!-- wp:heading {\"level\":%d} -->\n<h%d>%s</h%d>\n<!-- /wp:heading -->",
            $level,
            $level,
            $text,
            $level
        );
    }

    private function paragraph(int $sentences): string
    {
        $parts = [];
        for ($i = 0; $i < $sentences; $i++) {
            $s = $this->sentence($this->rng->int(9, 26));
            if ($this->rng->bool(0.12)) {
                $s = $this->withLink($s);
            }
            if ($this->rng->bool(0.10)) {
                $s = $this->withEmphasis($s);
            }
            $parts[] = $s;
        }
        return "<!-- wp:paragraph -->\n<p>" . implode(' ', $parts) . "</p>\n<!-- /wp:paragraph -->";
    }

    private function listBlock(): string
    {
        $items = [];
        $n = $this->rng->int(3, 7);
        for ($i = 0; $i < $n; $i++) {
            $items[] = '<li>' . ucfirst($this->words($this->rng->int(4, 12))) . '</li>';
        }
        return "<!-- wp:list -->\n<ul>" . implode('', $items) . "</ul>\n<!-- /wp:list -->";
    }

    private function quote(): string
    {
        return "<!-- wp:quote -->\n<blockquote class=\"wp-block-quote\"><p>"
            . $this->sentence($this->rng->int(14, 30))
            . "</p><cite>" . $this->authorName() . "</cite></blockquote>\n<!-- /wp:quote -->";
    }

    private function code(): string
    {
        $lines = [];
        $n = $this->rng->int(3, 8);
        for ($i = 0; $i < $n; $i++) {
            $lines[] = sprintf(
                '%s %s=%d',
                $this->rng->pick(['set', 'export', 'tune', 'limit', 'define']),
                strtoupper($this->rng->pick(self::WORDS)),
                $this->rng->int(1, 4096)
            );
        }
        return "<!-- wp:code -->\n<pre class=\"wp-block-code\"><code>"
            . implode("\n", $lines)
            . "</code></pre>\n<!-- /wp:code -->";
    }

    private function imageBlock(array $image): string
    {
        return sprintf(
            "<!-- wp:image {\"id\":%d,\"sizeSlug\":\"large\"} -->\n"
            . "<figure class=\"wp-block-image size-large\"><img src=\"%s\" alt=\"%s\" class=\"wp-image-%d\"/>"
            . "<figcaption>%s</figcaption></figure>\n<!-- /wp:image -->",
            $image['id'],
            $image['url'],
            ucfirst($this->words(4)),
            $image['id'],
            ucfirst($this->words($this->rng->int(4, 9)))
        );
    }

    private function gallery(array $images): string
    {
        $ids = array_map(static fn(array $i): int => $i['id'], $images);
        $figures = [];
        foreach ($images as $image) {
            $figures[] = sprintf(
                '<figure class="wp-block-image"><img src="%s" alt="%s" class="wp-image-%d"/></figure>',
                $image['url'],
                ucfirst($this->words(3)),
                $image['id']
            );
        }
        return sprintf(
            "<!-- wp:gallery {\"ids\":[%s],\"columns\":3} -->\n"
            . "<figure class=\"wp-block-gallery columns-3\">%s</figure>\n<!-- /wp:gallery -->",
            implode(',', $ids),
            implode('', $figures)
        );
    }

    private function withLink(string $sentence): string
    {
        $words = explode(' ', $sentence);
        if (count($words) < 4) {
            return $sentence;
        }
        $at = $this->rng->int(1, count($words) - 2);
        $words[$at] = sprintf('<a href="/?p=%d">%s</a>', $this->rng->int(1, 400), $words[$at]);
        return implode(' ', $words);
    }

    private function withEmphasis(string $sentence): string
    {
        $words = explode(' ', $sentence);
        if (count($words) < 3) {
            return $sentence;
        }
        $at = $this->rng->int(1, count($words) - 2);
        $tag = $this->rng->bool() ? 'strong' : 'em';
        $words[$at] = "<$tag>{$words[$at]}</$tag>";
        return implode(' ', $words);
    }

    private function sentence(int $words): string
    {
        $text = $this->words($words);
        if ($this->rng->bool(0.25)) {
            $text = $this->rng->pick(self::CONNECTORS) . ', ' . $text;
        }
        return ucfirst($text) . $this->rng->pick(['.', '.', '.', '.', '?', '!']);
    }

    private function words(int $n): string
    {
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = self::WORDS[$this->rng->int(0, count(self::WORDS) - 1)];
        }
        return implode(' ', $out);
    }
}
