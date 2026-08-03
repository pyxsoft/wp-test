<?php
/**
 * Profile loading and shared constants for the generator steps.
 *
 * Profiles are plain KEY=VALUE files so that both bash (`source`) and PHP
 * (`parse_ini_file`) can read them without jq or any parser dependency.
 */

require_once __DIR__ . '/rng.php';

final class Config
{
    /**
     * Fixed clock for the generated site.
     *
     * Content dates are derived from this epoch rather than from "now", so two
     * runs a month apart produce the same site — same permalinks, same upload
     * folders, same archive pages. Override with WP_TEST_EPOCH if a benchmark
     * needs fresher-looking dates.
     */
    public const DEFAULT_EPOCH = '2024-01-15 09:00:00';

    public const DEFAULT_SEED = 20260803;

    private array $values;

    private function __construct(array $values)
    {
        $this->values = $values;
    }

    public static function load(?string $path = null): self
    {
        $path = $path ?: getenv('WP_TEST_PROFILE');
        if (!$path || !is_readable($path)) {
            fwrite(STDERR, "wp-test: profile not readable: " . var_export($path, true) . "\n");
            exit(1);
        }

        $values = self::parse($path);

        // Environment wins over the file, so a run can be tweaked without
        // editing (and then accidentally committing) a profile.
        //
        // Any WP_TEST_<KEY> is honoured, including keys the profile does not
        // define — otherwise WP_TEST_ELEMENTOR_PAGES on a profile without that
        // line would be ignored in silence, which is the worst way to find out.
        $control = [
            'PROFILE' => true, 'SEED' => true, 'EPOCH' => true,
            'STATE_DIR' => true, 'SHARD' => true, 'SHARD_LABEL' => true,
        ];

        foreach (getenv() as $name => $value) {
            if (strpos($name, 'WP_TEST_') !== 0 || $value === '') {
                continue;
            }
            $key = substr($name, strlen('WP_TEST_'));
            if (isset($control[$key])) {
                continue;
            }
            $values[$key] = is_numeric($value) ? $value + 0 : $value;
        }

        return new self($values);
    }

    /**
     * Read a KEY=VALUE profile.
     *
     * Deliberately not parse_ini_file(): PHP dropped '#' as an ini comment
     * character in PHP 7, and a profile has to stay `source`-able from bash,
     * where '#' is the only comment character there is. Rather than keep two
     * copies of every profile, we parse the handful of lines ourselves.
     */
    private static function parse(string $path): array
    {
        $values = [];
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            fwrite(STDERR, "wp-test: cannot read profile: $path\n");
            exit(1);
        }

        foreach ($lines as $number => $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || $line[0] === ';') {
                continue;
            }

            $at = strpos($line, '=');
            if ($at === false) {
                fwrite(STDERR, sprintf("wp-test: %s line %d is not KEY=VALUE: %s\n", $path, $number + 1, $line));
                exit(1);
            }

            $key = trim(substr($line, 0, $at));
            $value = trim(substr($line, $at + 1));

            // Strip a trailing inline comment, then surrounding quotes.
            if (preg_match('/^(.*?)\s+#.*$/', $value, $m)) {
                $value = rtrim($m[1]);
            }
            if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
                $value = substr($value, 1, -1);
            }

            $values[$key] = is_numeric($value) ? $value + 0 : $value;
        }

        return $values;
    }

    public function int(string $key, int $default = 0): int
    {
        return isset($this->values[$key]) ? (int) $this->values[$key] : $default;
    }

    public function str(string $key, string $default = ''): string
    {
        return isset($this->values[$key]) ? (string) $this->values[$key] : $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        if (!isset($this->values[$key])) {
            return $default;
        }
        $v = $this->values[$key];
        return $v === true || $v === 1 || $v === '1' || $v === 'true' || $v === 'yes';
    }

    public function all(): array
    {
        return $this->values;
    }

    public function seed(): int
    {
        $env = getenv('WP_TEST_SEED');
        return $env !== false && $env !== '' ? (int) $env : self::DEFAULT_SEED;
    }

    public function rng(string $stream): Rng
    {
        return (new Rng($this->seed()))->fork($stream);
    }

    public function epoch(): int
    {
        $env = getenv('WP_TEST_EPOCH');
        $ts = strtotime($env !== false && $env !== '' ? $env : self::DEFAULT_EPOCH);
        return $ts === false ? strtotime(self::DEFAULT_EPOCH) : $ts;
    }

    /**
     * A publication date $index items back from the epoch, spread over $spanDays.
     *
     * Content is dated backwards from the epoch so the newest post sits at the
     * top and archives fill in behind it, as on a site that has been running
     * for a while.
     */
    public function dateFor(int $index, int $total, int $spanDays, Rng $rng): string
    {
        $total = max(1, $total);
        $offsetDays = (int) round($spanDays * ($index / $total));
        $jitter = $rng->int(0, 20) * 3600;
        $ts = $this->epoch() - ($offsetDays * 86400) - $jitter;
        return date('Y-m-d H:i:s', $ts);
    }

    /** Where the run keeps its state and manifest fragments. */
    public function stateDir(): string
    {
        $dir = getenv('WP_TEST_STATE_DIR');
        if (!$dir) {
            $dir = sys_get_temp_dir() . '/wp-test-state';
        }
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            fwrite(STDERR, "wp-test: cannot create state dir: $dir\n");
            exit(1);
        }
        return $dir;
    }
}

/**
 * Shard spec "k/n", from WP_TEST_SHARD or from the args wp-cli passes on.
 *
 * The environment is checked first: wp-cli parses anything that looks like
 * --flag itself, so passing --shard=1/4 through to eval-file is fragile.
 */
function wp_test_shard(array $args): array
{
    $env = getenv('WP_TEST_SHARD');
    if ($env !== false && preg_match('#^(\d+)/(\d+)$#', $env, $m)) {
        $k = (int) $m[1];
        $n = (int) $m[2];
        if ($n > 0 && $k >= 1 && $k <= $n) {
            return [$k - 1, $n];
        }
    }

    foreach ($args as $arg) {
        if (preg_match('#^--shard=(\d+)/(\d+)$#', (string) $arg, $m)) {
            $k = (int) $m[1];
            $n = (int) $m[2];
            if ($n > 0 && $k >= 1 && $k <= $n) {
                return [$k - 1, $n];
            }
        }
    }
    return [0, 1];
}

/** Progress line that stays readable when several shards write at once. */
function wp_test_log(string $message): void
{
    $shard = getenv('WP_TEST_SHARD_LABEL');
    $prefix = $shard ? "[$shard] " : '';
    fwrite(STDERR, $prefix . $message . "\n");
}
