<?php

declare(strict_types=1);

use ExeQue\LumberjackAgent\Spool;

beforeEach(function () {
    $this->spoolPath = sys_get_temp_dir().'/lumberjack-spool-'.bin2hex(random_bytes(4));
    $this->spool = new Spool($this->spoolPath, 10_000);
});

afterEach(fn () => removeDirectory($this->spoolPath));

it('drops the oldest batch when it is full', function () {
    $spool = new Spool($this->spoolPath, 2500);

    $spool->push(str_repeat('a', 1000));
    $spool->push(str_repeat('b', 1000));
    $spool->push(str_repeat('c', 1000));

    expect($spool->count())->toBe(2)->and($spool->read((string) $spool->oldest()))->toStartWith('b');
});

it('keeps batches readable by the agent\'s user only', function () {
    $this->spool->push('batch');

    expect(fileperms($this->spoolPath) & 0777)->toBe(0700)
        ->and(fileperms((string) $this->spool->oldest()) & 0777)->toBe(0600);
});

it('says once when it cannot write', function () {
    $warnings = [];
    $file = (string) tempnam(sys_get_temp_dir(), 'lj');
    $spool = new Spool($file.'/spool', 10_000, function (string $message) use (&$warnings): void {
        $warnings[] = $message;
    });

    $spool->push('a');
    $spool->push('b');

    expect($warnings)->toHaveCount(1)->and($warnings[0])->toContain('Could not write to the spool');
    unlink($file);
});

it('picks up what an earlier agent left, oldest first, also batches named by an older version', function () {
    mkdir($this->spoolPath, 0700, true);
    // An older version named batches by hrtime(), which starts over at boot.
    file_put_contents($this->spoolPath.'/00000003600000000000-0000aaaa.json.gz', str_repeat('a', 1000));

    $earlier = new Spool($this->spoolPath, 2500);
    $earlier->push(str_repeat('b', 1000));

    // The agent restarts: the next one reads the directory, sizes included.
    $spool = new Spool($this->spoolPath, 2500);
    $spool->push(str_repeat('c', 1000));

    expect($spool->count())->toBe(2)
        ->and($spool->read((string) $spool->oldest()))->toStartWith('b')
        ->and(count((array) glob($this->spoolPath.'/*.json.gz')))->toBe(2);
});
