<?php
/**
 * Deterministic Elementor page builder content.
 *
 * Why this exists: a benchmark fixture made of plain posts measures disk, cache
 * and bandwidth well, but barely touches PHP. Real agency sites are slow for a
 * different reason — a page builder that reconstructs the page from a JSON
 * document on every uncached request, resolving hundreds of widget settings and
 * enqueueing per-post CSS. That is the cost this file synthesises.
 *
 * Elementor stores its document in the `_elementor_data` post meta: a nested
 * JSON tree of section > column > widget. We build that tree directly rather
 * than driving the editor, which is the only way to do it deterministically and
 * without a browser.
 *
 * Fragility, stated plainly: this depends on Elementor's document schema, which
 * has changed across major versions. Pin the plugin version in the profile's
 * .plugins file, and if a future Elementor rejects the tree, the symptom will be
 * a page that renders empty — check `_elementor_version` first.
 */

require_once __DIR__ . '/rng.php';
require_once __DIR__ . '/text.php';

final class ElementorGen
{
    /**
     * Widgets from the free plugin only.
     *
     * Anything from Elementor Pro is deliberately absent: it is paid, cannot be
     * pinned in a public fixture, and a benchmark nobody else can rebuild is
     * not a benchmark.
     */
    private const WIDGETS = [
        'heading', 'text-editor', 'image', 'button', 'divider', 'spacer',
        'icon-box', 'image-box', 'icon-list', 'counter', 'progress',
        'tabs', 'accordion', 'toggle', 'alert', 'star-rating', 'testimonial',
    ];

    /** Widgets that are cheap and appear everywhere; weighted higher. */
    private const COMMON = ['heading', 'text-editor', 'image', 'button', 'icon-box'];

    public function __construct(
        private Rng $rng,
        private TextGen $text,
        private string $version = '3.25.0'
    ) {
    }

    /**
     * Build one page document.
     *
     * @param array<int,array{id:int,url:string}> $images Attachments to place.
     * @param int $sections How many top-level sections to emit.
     * @return array The `_elementor_data` tree, ready to json_encode.
     */
    public function document(int $sections, array $images): array
    {
        $tree = [];
        $imageCursor = 0;

        for ($s = 0; $s < $sections; $s++) {
            $columns = $this->rng->pick([1, 2, 2, 2, 3, 3, 4]);
            $columnSize = (int) round(100 / $columns);

            $columnElements = [];
            for ($c = 0; $c < $columns; $c++) {
                $widgets = [];
                $count = $this->rng->int(1, 4);

                for ($w = 0; $w < $count; $w++) {
                    $type = $this->rng->bool(0.55)
                        ? $this->rng->pick(self::COMMON)
                        : $this->rng->pick(self::WIDGETS);

                    $image = null;
                    if (in_array($type, ['image', 'image-box'], true) && $images) {
                        $image = $images[$imageCursor % count($images)];
                        $imageCursor++;
                    }

                    $widgets[] = $this->widget($type, $image);
                }

                $columnElements[] = [
                    'id'       => $this->id(),
                    'elType'   => 'column',
                    'settings' => [
                        '_column_size' => $columnSize,
                        '_inline_size' => null,
                    ],
                    'elements' => $widgets,
                    'isInner'  => false,
                ];
            }

            $tree[] = [
                'id'       => $this->id(),
                'elType'   => 'section',
                'settings' => $this->sectionSettings(),
                'elements' => $columnElements,
                'isInner'  => false,
            ];
        }

        return $tree;
    }

    /** Elementor element ids are 7 hex characters; ours come from the seed. */
    private function id(): string
    {
        return substr(str_pad(dechex($this->rng->next()), 7, '0', STR_PAD_LEFT), 0, 7);
    }

    private function sectionSettings(): array
    {
        $settings = [
            'structure'            => (string) $this->rng->pick([10, 20, 30, 40]),
            'padding'              => $this->dimensions($this->rng->int(20, 90), $this->rng->int(0, 40)),
            'content_position'     => $this->rng->pick(['top', 'middle', 'center']),
        ];

        // A minority of sections carry a background, which is what makes
        // Elementor emit a per-section CSS rule instead of reusing defaults.
        if ($this->rng->bool(0.35)) {
            $settings['background_background'] = 'classic';
            $settings['background_color'] = sprintf(
                '#%02X%02X%02X',
                $this->rng->int(200, 255),
                $this->rng->int(200, 255),
                $this->rng->int(200, 255)
            );
        }

        return $settings;
    }

    private function dimensions(int $vertical, int $horizontal): array
    {
        return [
            'unit'     => 'px',
            'top'      => (string) $vertical,
            'right'    => (string) $horizontal,
            'bottom'   => (string) $vertical,
            'left'     => (string) $horizontal,
            'isLinked' => false,
        ];
    }

    private function widget(string $type, ?array $image): array
    {
        return [
            'id'         => $this->id(),
            'elType'     => 'widget',
            'widgetType' => $type,
            'settings'   => $this->widgetSettings($type, $image),
            'elements'   => [],
        ];
    }

    private function widgetSettings(string $type, ?array $image): array
    {
        switch ($type) {
            case 'heading':
                return [
                    'title'      => ucfirst($this->text->title()),
                    'size'       => $this->rng->pick(['default', 'medium', 'large', 'xl']),
                    'header_size'=> $this->rng->pick(['h2', 'h2', 'h3', 'h4']),
                    'align'      => $this->rng->pick(['left', 'left', 'center']),
                ];

            case 'text-editor':
                return [
                    'editor' => '<p>' . $this->text->excerpt() . '</p><p>' . $this->text->excerpt() . '</p>',
                ];

            case 'image':
                return [
                    'image' => [
                        'url' => $image['url'] ?? '',
                        'id'  => $image['id'] ?? 0,
                    ],
                    'image_size' => $this->rng->pick(['large', 'medium_large', 'full']),
                    'align'      => $this->rng->pick(['center', 'left']),
                ];

            case 'image-box':
                return [
                    'image' => [
                        'url' => $image['url'] ?? '',
                        'id'  => $image['id'] ?? 0,
                    ],
                    'title_text'       => ucfirst($this->text->productName()),
                    'description_text' => $this->text->excerpt(),
                    'position'         => $this->rng->pick(['top', 'left']),
                ];

            case 'button':
                return [
                    'text'      => $this->rng->pick(['Read more', 'Get started', 'Contact us', 'See details', 'Download']),
                    'link'      => ['url' => '#', 'is_external' => '', 'nofollow' => ''],
                    'align'     => $this->rng->pick(['left', 'center']),
                    'size'      => $this->rng->pick(['sm', 'md', 'lg']),
                ];

            case 'divider':
                return [
                    'style'  => $this->rng->pick(['solid', 'dashed', 'dotted']),
                    'weight' => ['unit' => 'px', 'size' => $this->rng->int(1, 4)],
                ];

            case 'spacer':
                return ['space' => ['unit' => 'px', 'size' => $this->rng->int(20, 120)]];

            case 'icon-box':
                return [
                    'selected_icon'    => ['value' => $this->icon(), 'library' => 'fa-solid'],
                    'title_text'       => ucfirst($this->text->productName()),
                    'description_text' => $this->text->excerpt(),
                    'position'         => $this->rng->pick(['top', 'left']),
                ];

            case 'icon-list':
                $items = [];
                $n = $this->rng->int(3, 7);
                for ($i = 0; $i < $n; $i++) {
                    $items[] = [
                        '_id'           => $this->id(),
                        'text'          => ucfirst($this->text->excerpt()),
                        'selected_icon' => ['value' => $this->icon(), 'library' => 'fa-solid'],
                    ];
                }
                return ['icon_list' => $items, 'space_between' => ['unit' => 'px', 'size' => $this->rng->int(6, 20)]];

            case 'counter':
                return [
                    'starting_number' => 0,
                    'ending_number'   => $this->rng->int(50, 99999),
                    'title'           => ucfirst($this->text->productName()),
                    'duration'        => $this->rng->int(1000, 3000),
                ];

            case 'progress':
                return [
                    'title'      => ucfirst($this->text->productName()),
                    'percent'    => ['unit' => '%', 'size' => $this->rng->int(15, 100)],
                    'display_percentage' => 'show',
                ];

            case 'tabs':
            case 'accordion':
            case 'toggle':
                $items = [];
                $n = $this->rng->int(3, 6);
                for ($i = 0; $i < $n; $i++) {
                    $items[] = $type === 'tabs'
                        ? [
                            '_id'         => $this->id(),
                            'tab_title'   => ucfirst($this->text->productName()),
                            'tab_content' => '<p>' . $this->text->excerpt() . '</p>',
                        ]
                        : [
                            '_id'           => $this->id(),
                            'tab_title'     => ucfirst($this->text->title()),
                            'tab_content'   => '<p>' . $this->text->excerpt() . '</p>',
                            'selected_icon' => ['value' => $this->icon(), 'library' => 'fa-solid'],
                        ];
                }
                return $type === 'tabs' ? ['tabs' => $items] : ['tabs' => $items];

            case 'alert':
                return [
                    'alert_type'        => $this->rng->pick(['info', 'success', 'warning', 'danger']),
                    'alert_title'       => ucfirst($this->text->productName()),
                    'alert_description' => $this->text->excerpt(),
                ];

            case 'star-rating':
                return [
                    'rating' => $this->rng->int(3, 5),
                    'title'  => ucfirst($this->text->productName()),
                ];

            case 'testimonial':
                return [
                    'testimonial_content' => $this->text->excerpt(),
                    'testimonial_name'    => $this->text->authorName(),
                    'testimonial_job'     => $this->rng->pick(['CTO', 'Founder', 'Developer', 'Designer', 'Sysadmin']),
                ];

            default:
                return ['title' => ucfirst($this->text->title())];
        }
    }

    private function icon(): string
    {
        return 'fas fa-' . $this->rng->pick([
            'check', 'star', 'bolt', 'shield-alt', 'server', 'database', 'rocket',
            'cog', 'chart-line', 'lock', 'cloud', 'wrench', 'globe', 'clock',
        ]);
    }

    /**
     * Write the document onto a post, with the meta Elementor needs to take over
     * rendering. Without _elementor_edit_mode the front end ignores the data and
     * falls back to post_content — the page would look fine and measure nothing.
     */
    public function attach(int $postId, array $document): void
    {
        // wp_slash: update_post_meta unslashes on the way in, and the JSON is
        // full of quotes and backslashes. Elementor stores it slashed for the
        // same reason.
        update_post_meta($postId, '_elementor_data', wp_slash(wp_json_encode($document)));
        update_post_meta($postId, '_elementor_edit_mode', 'builder');
        update_post_meta($postId, '_elementor_template_type', 'wp-page');
        update_post_meta($postId, '_elementor_version', $this->version);
        update_post_meta($postId, '_elementor_page_assets', []);
    }
}
