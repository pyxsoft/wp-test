<?php
/**
 * Deterministic PRNG for wp-test.
 *
 * Everything the generator produces — pixels, words, prices, dates — comes out
 * of this class, so a given seed yields byte-identical content on any server.
 *
 * We do NOT use mt_rand()/random_int(): mt_rand()'s stream has changed across
 * PHP releases and random_int() is by definition unpredictable. This is a plain
 * 32-bit LCG (Numerical Recipes constants). Its statistical quality is
 * irrelevant here; reproducibility is the whole point.
 *
 * Requires a 64-bit PHP build so the multiply never overflows into a float.
 */

if (PHP_INT_SIZE < 8) {
    fwrite(STDERR, "wp-test: a 64-bit PHP build is required (PHP_INT_SIZE=" . PHP_INT_SIZE . ")\n");
    exit(1);
}

final class Rng
{
    private const A = 1664525;
    private const C = 1013904223;
    private const M = 0xFFFFFFFF;

    private int $state;

    public function __construct(int $seed)
    {
        $this->state = $seed & self::M;
        // Discard the first few outputs: low seeds start with a very
        // predictable run in an LCG this small.
        for ($i = 0; $i < 8; $i++) {
            $this->next();
        }
    }

    /**
     * Derive an independent stream from a label.
     *
     * This is what makes the generator resumable and parallelisable: image 900
     * is generated from seed+"media:900" and never depends on images 1..899
     * having been generated first. Run the media step across 8 workers or one,
     * the output is the same.
     */
    public function fork(string $label): self
    {
        return new self(($this->state ^ crc32($label)) & self::M);
    }

    /** Next raw 32-bit value. */
    public function next(): int
    {
        $this->state = (self::A * $this->state + self::C) & self::M;
        return $this->state;
    }

    /** Float in [0, 1). */
    public function float(): float
    {
        return $this->next() / 4294967296.0;
    }

    /** Integer in [$min, $max], inclusive. */
    public function int(int $min, int $max): int
    {
        if ($max <= $min) {
            return $min;
        }
        return $min + (int) ($this->float() * ($max - $min + 1));
    }

    /** True with probability $p. */
    public function bool(float $p = 0.5): bool
    {
        return $this->float() < $p;
    }

    /** One element of a non-empty array (list semantics). */
    public function pick(array $items)
    {
        $values = array_values($items);
        return $values[$this->int(0, count($values) - 1)];
    }

    /** $n distinct elements, order randomised. */
    public function sample(array $items, int $n): array
    {
        $shuffled = $this->shuffle($items);
        return array_slice($shuffled, 0, max(0, $n));
    }

    /** Fisher-Yates driven by this stream (PHP's shuffle() is not reproducible). */
    public function shuffle(array $items): array
    {
        $values = array_values($items);
        for ($i = count($values) - 1; $i > 0; $i--) {
            $j = $this->int(0, $i);
            [$values[$i], $values[$j]] = [$values[$j], $values[$i]];
        }
        return $values;
    }

    /** Roughly gaussian value via the central limit theorem, clamped to ±3σ. */
    public function gauss(float $mean = 0.0, float $stddev = 1.0): float
    {
        $sum = 0.0;
        for ($i = 0; $i < 6; $i++) {
            $sum += $this->float();
        }
        $z = ($sum - 3.0) / 0.7071;
        $z = max(-3.0, min(3.0, $z));
        return $mean + $z * $stddev;
    }
}
