<?php

declare(strict_types=1);

namespace ExeQue\LumberjackAgent;

use Closure;

/**
 * Gzipped batches waiting on disk for Lumberjack to come back. The oldest
 * batch is dropped first when the spool is full.
 *
 * The directory is read once; after that the spool keeps its own list of files and
 * their sizes, so a push or a count costs no directory scan however long Lumberjack
 * has been away. ponytail: the agent is the spool's only writer; a file put there by
 * anything else is seen after the agent restarts.
 */
class Spool
{
    private bool $warned = false;

    /** @var array<string, int>|null size by file, oldest first; null until the directory was read */
    private ?array $files = null;

    private int $bytes = 0;

    public function __construct(
        private readonly string $path,
        private readonly int $maxBytes,
        private readonly ?Closure $warn = null,
    ) {
    }

    /**
     * Only the agent's user can read a batch: it holds the app's data.
     */
    public function push(string $body): void
    {
        if (! is_dir($this->path)) {
            @mkdir($this->path, 0700, true);
        }

        // Before writing, so the directory as it was is read, not the new batch twice.
        $this->index();

        // Wall-clock nanoseconds, so the order survives a reboot (hrtime() starts over at
        // boot); still larger than any hrtime() name an older version left.
        $name = sprintf('%s/%020d-%s.json.gz', $this->path, (int) (microtime(true) * 1e9), bin2hex(random_bytes(4)));
        $old = umask(0077);
        $written = @file_put_contents($name, $body, LOCK_EX);
        umask($old);

        if ($written === false) {
            $this->failed("Could not write to the spool at {$this->path}; batches are dropped while Lumberjack cannot be reached. Make it writable by the agent's user.");

            return;
        }

        $this->files[$name] = $written;
        $this->bytes += $written;
        $this->trim();
    }

    private function failed(string $message): void
    {
        if ($this->warned) {
            return;
        }

        $this->warned = true;
        @error_log('Lumberjack agent: '.$message);
        ($this->warn)?->__invoke($message);
    }

    public function oldest(): ?string
    {
        $this->index();

        return array_key_first($this->files);
    }

    public function count(): int
    {
        $this->index();

        return count($this->files);
    }

    public function read(string $file): ?string
    {
        $body = @file_get_contents($file);

        return $body === false ? null : $body;
    }

    public function delete(string $file): void
    {
        $this->index();
        $this->bytes -= $this->files[$file] ?? 0;
        unset($this->files[$file]);
        @unlink($file);
    }

    private function trim(): void
    {
        while ($this->bytes > $this->maxBytes && ($file = $this->oldest()) !== null) {
            $this->delete($file);
        }
    }

    /**
     * @phpstan-assert array<string, int> $this->files
     */
    private function index(): void
    {
        if ($this->files !== null) {
            return;
        }

        $files = glob($this->path.'/*.json.gz') ?: [];
        sort($files);
        $this->files = [];
        $this->bytes = 0;

        foreach ($files as $file) {
            $this->files[$file] = (int) @filesize($file);
            $this->bytes += $this->files[$file];
        }
    }
}
