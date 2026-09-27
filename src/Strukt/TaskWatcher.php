<?php
namespace Strukt;

use Strukt\Fs\Fs;

/** Polls a file or directory and reports content changes to a callback. */
final class TaskWatcher
{
    /** @var callable(int): void Sleep implementation used between polls. */
    private $sleeper;

    /** @var callable(string): string File fingerprint implementation. */
    private $fingerprinter;

    /**
     * Creates a watcher with optional time and fingerprint injection.
     *
     * Injection keeps long-running polling code deterministic in tests and
     * lets applications replace sleep or hashing with platform-specific code.
     *
     * @param callable|null $sleeper Receives the interval in seconds.
     * @param callable|null $fingerprinter Receives a file path and returns its fingerprint.
     */
    public function __construct( ? callable $sleeper = null,  ? callable $fingerprinter = null)
    {
        $this->sleeper = $sleeper ?? static function (int $seconds) : void {
            if ($seconds > 0) {
                sleep($seconds);
            }
        };
        $this->fingerprinter = $fingerprinter ?? static function (string $file) : string {
            $hash = @hash_file('sha256', $file);

            return $hash === false ? '' : $hash;
        };
    }

    /**
     * Watches a path until stopped or an iteration limit is reached.
     *
     * The first snapshot establishes a baseline and does not invoke the
     * callback. Later snapshots report added, removed, and changed files.
     * Passing null for iterations keeps the watcher running indefinitely.
     *
     * @param string $path File or directory to watch.
     * @param callable $callback Receives a list of changed paths.
     * @param int $interval Polling interval in seconds.
     * @param int|null $iterations Number of polls, or null for an endless loop.
     * @return void
     * @throws \InvalidArgumentException When the path or options are invalid.
     */
    public function watch(
        string $path,
        callable $callback,
        int $interval = 1,
        ?int $iterations = null,
    ): void {
        if (! Fs::isFile($path) && ! Fs::isDir($path)) {
            throw new \InvalidArgumentException(sprintf('Unknown file or path [%s].', $path));
        }
        if ($interval < 0) {
            throw new \InvalidArgumentException('The watch interval cannot be negative.');
        }
        if ($iterations !== null && $iterations < 0) {
            throw new \InvalidArgumentException('The watch iteration count cannot be negative.');
        }

        $previous = $this->snapshot($path);
        $polls    = 0;

        while ($iterations === null || $polls < $iterations) {
            ($this->sleeper)($interval);
            clearstatcache(true);

            $current = $this->snapshot($path);
            $changed = $this->changed($previous, $current);
            if ($changed !== []) {
                $callback($changed);
            }

            $previous = $current;
            ++$polls;
        }
    }

    /**
     * Builds a deterministic path-to-fingerprint snapshot.
     *
     * @param string $path File or directory path.
     * @return array<string, string> Fingerprints keyed by file path.
     * @throws \InvalidArgumentException When the path does not exist.
     */
    public function snapshot(string $path): array
    {
        if (Fs::isFile($path)) {
            $files = [$path];
        } elseif (Fs::isDir($path)) {
            $files = Fs::lsr($path);
        } else {
            throw new \InvalidArgumentException(sprintf('Unknown file or path [%s].', $path));
        }

        $snapshot = [];
        foreach ($files as $file) {
            if (! Fs::isFile($file)) {
                continue;
            }

            $normalized            = Fs::ds($file);
            $snapshot[$normalized] = ($this->fingerprinter)($file);
        }

        ksort($snapshot);

        return $snapshot;
    }

    /**
     * Compares two snapshots and returns every changed path once.
     *
     * @param array<string, string> $previous Previous fingerprints.
     * @param array<string, string> $current Current fingerprints.
     * @return list<string> Added, removed, or changed paths.
     */
    private function changed(array $previous, array $current): array
    {
        $paths = [];
        foreach ($current as $path => $fingerprint) {
            if (! array_key_exists($path, $previous) || $previous[$path] !== $fingerprint) {
                $paths[$path] = true;
            }
        }
        foreach ($previous as $path => $_fingerprint) {
            if (! array_key_exists($path, $current)) {
                $paths[$path] = true;
            }
        }

        $changed = array_keys($paths);
        sort($changed);

        return $changed;
    }
}
