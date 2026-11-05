<?php

declare(strict_types=1);

use ExeQue\LumberjackAgent\HostMetrics;

beforeEach(function () {
    $this->root = sys_get_temp_dir().'/lumberjack-host-'.bin2hex(random_bytes(4));
    $this->file = function (string $path, string $contents): void {
        is_dir(dirname($this->root.$path)) || mkdir(dirname($this->root.$path), 0777, true);
        file_put_contents($this->root.$path, $contents);
    };
    ($this->file)('/proc/meminfo', "MemTotal:       16384000 kB\nMemFree:         1000000 kB\nMemAvailable:    4096000 kB\n");
    ($this->file)('/proc/cpuinfo', "processor\t: 0\nmodel name\t: x\n\nprocessor\t: 1\n\nprocessor\t: 2\n\nprocessor\t: 3\n");
});

afterEach(fn () => removeDirectory($this->root));

it('reads memory and cores of the machine without a cgroup', function () {
    $metrics = (new HostMetrics(sys_get_temp_dir(), $this->root))->measure();

    expect($metrics['memory_total_bytes'])->toBe(16384000 * 1024)
        ->and($metrics['memory_used_bytes'])->toBe((16384000 - 4096000) * 1024)
        ->and($metrics['cpu_cores'])->toBe(4.0)
        ->and($metrics['disk_total_bytes'])->toBeGreaterThan(0)
        ->and($metrics['disk_used_bytes'])->toBeLessThanOrEqual($metrics['disk_total_bytes'])
        ->and($metrics['php_memory_peak_bytes'])->toBeGreaterThan(0);
});

it('prefers the container limits of cgroup v2', function () {
    ($this->file)('/sys/fs/cgroup/memory.current', "268435456\n");
    ($this->file)('/sys/fs/cgroup/memory.max', "536870912\n");
    ($this->file)('/sys/fs/cgroup/cpu.max', "150000 100000\n");

    $metrics = (new HostMetrics(sys_get_temp_dir(), $this->root))->measure();

    expect($metrics)->toMatchArray(['memory_used_bytes' => 268435456, 'memory_total_bytes' => 536870912, 'cpu_cores' => 1.5]);
});

it('falls back to the machine when the cgroup sets no limit', function () {
    ($this->file)('/sys/fs/cgroup/memory.current', '1024');
    ($this->file)('/sys/fs/cgroup/memory.max', 'max');
    ($this->file)('/sys/fs/cgroup/cpu.max', 'max 100000');

    $metrics = (new HostMetrics(sys_get_temp_dir(), $this->root))->measure();

    expect($metrics)->toMatchArray(['memory_used_bytes' => 1024, 'memory_total_bytes' => 16384000 * 1024, 'cpu_cores' => 4.0]);
});

it('is null where the system tells nothing', function () {
    $metrics = (new HostMetrics('/does/not/exist', $this->root.'/nothing'))->measure();

    expect($metrics)->toMatchArray(['memory_used_bytes' => null, 'memory_total_bytes' => null, 'cpu_cores' => null, 'disk_used_bytes' => null, 'disk_total_bytes' => null]);
});
