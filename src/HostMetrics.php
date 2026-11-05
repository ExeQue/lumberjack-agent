<?php

declare(strict_types=1);

namespace ExeQue\LumberjackAgent;

use Throwable;

/**
 * How busy the agent's host is, for the heartbeat: load, cores, memory and disk. Read
 * from files the kernel provides, never from running a program, and container aware:
 * a cgroup's limits win over the machine's. Anything this system does not tell
 * (macOS, Windows, a locked down container) is null; this never throws.
 */
class HostMetrics
{
    /** cgroup v1 reports "no limit" as a number near PHP_INT_MAX. */
    private const int NO_LIMIT = 1 << 60;

    public function __construct(
        private readonly string $disk,
        private readonly string $root = '',
    ) {
    }

    /**
     * @return array{cpu_load_1m: float|null, cpu_cores: float|null, memory_used_bytes: int|null, memory_total_bytes: int|null, disk_used_bytes: int|null, disk_total_bytes: int|null, php_memory_peak_bytes: int}
     */
    public function measure(): array
    {
        [$memoryUsed, $memoryTotal] = $this->guard(fn (): array => $this->memory(), [null, null]);
        [$diskUsed, $diskTotal] = $this->guard(fn (): array => $this->disk(), [null, null]);

        return [
            'cpu_load_1m' => $this->guard(fn (): ?float => $this->load(), null),
            'cpu_cores' => $this->guard(fn (): ?float => $this->cores(), null),
            'memory_used_bytes' => $memoryUsed,
            'memory_total_bytes' => $memoryTotal,
            'disk_used_bytes' => $diskUsed,
            'disk_total_bytes' => $diskTotal,
            'php_memory_peak_bytes' => memory_get_peak_usage(true),
        ];
    }

    private function load(): ?float
    {
        $load = function_exists('sys_getloadavg') ? @sys_getloadavg() : false;

        return is_array($load) ? round($load[0], 2) : null;
    }

    private function cores(): ?float
    {
        // cgroup v2: "max 100000" is no limit, "150000 100000" is 1.5 cores.
        $max = $this->read('/sys/fs/cgroup/cpu.max');

        if ($max !== null && preg_match('/^(\d+)\s+(\d+)/', $max, $m) === 1 && (int) $m[2] > 0) {
            return round((int) $m[1] / (int) $m[2], 2);
        }

        $cpuinfo = $this->read('/proc/cpuinfo');
        $count = $cpuinfo !== null ? preg_match_all('/^processor\s*:/m', $cpuinfo) : 0;

        return $count > 0 ? (float) $count : null;
    }

    /**
     * @return array{0: int|null, 1: int|null} used and total bytes
     */
    private function memory(): array
    {
        [$machineUsed, $machineTotal] = $this->meminfo();

        // cgroup v2, then v1.
        foreach ([['/sys/fs/cgroup/memory.current', '/sys/fs/cgroup/memory.max'], ['/sys/fs/cgroup/memory/memory.usage_in_bytes', '/sys/fs/cgroup/memory/memory.limit_in_bytes']] as [$current, $limit]) {
            $used = $this->number($current);

            if ($used === null) {
                continue;
            }

            $max = $this->number($limit);

            return [$used, $max !== null && $max < self::NO_LIMIT ? $max : $machineTotal];
        }

        return [$machineUsed, $machineTotal];
    }

    /**
     * @return array{0: int|null, 1: int|null}
     */
    private function meminfo(): array
    {
        $meminfo = $this->read('/proc/meminfo');

        if ($meminfo === null || preg_match('/^MemTotal:\s+(\d+) kB/m', $meminfo, $total) !== 1) {
            return [null, null];
        }

        $available = preg_match('/^MemAvailable:\s+(\d+) kB/m', $meminfo, $m) === 1 ? (int) $m[1] : null;

        return [$available !== null ? ((int) $total[1] - $available) * 1024 : null, (int) $total[1] * 1024];
    }

    /**
     * @return array{0: int|null, 1: int|null}
     */
    private function disk(): array
    {
        $total = @disk_total_space($this->disk);
        $free = @disk_free_space($this->disk);

        if ($total === false || $free === false) {
            return [null, null];
        }

        return [(int) ($total - $free), (int) $total];
    }

    private function number(string $file): ?int
    {
        $value = $this->read($file);

        return $value !== null && ctype_digit($value) ? (int) $value : null;
    }

    private function read(string $file): ?string
    {
        $path = $this->root.$file;
        $contents = @is_readable($path) ? @file_get_contents($path) : false;

        return is_string($contents) ? trim($contents) : null;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $probe
     * @param  T  $fallback
     * @return T
     */
    private function guard(callable $probe, mixed $fallback): mixed
    {
        try {
            return $probe();
        } catch (Throwable) {
            return $fallback;
        }
    }
}
