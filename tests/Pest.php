<?php

declare(strict_types=1);

/**
 * Removes a directory the tests made, with everything in it.
 */
function removeDirectory(string $path): void
{
    if (! is_dir($path)) {
        return;
    }

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }

    rmdir($path);
}

/**
 * A port nothing listens on, for now.
 */
function freePort(): int
{
    $server = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr((string) strrchr((string) stream_socket_get_name($server, false), ':'), 1);
    fclose($server);

    return $port;
}
