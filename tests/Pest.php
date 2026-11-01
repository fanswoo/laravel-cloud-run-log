<?php

use Fanswoo\CloudRunLog\Tests\TestCase;

uses(TestCase::class)->in('Feature');

/**
 * Sends the `cloud-run` channel to a file of the test's own, in place of
 * standard error, and returns the file's path.
 */
function sendTheCloudRunLogToAFile(): string
{
    $path = (string) tempnam(sys_get_temp_dir(), 'cloud-run-log-');
    config(['logging.channels.cloud-run.handler_with.stream' => $path]);

    register_shutdown_function(static function () use ($path): void {
        if (is_file($path)) {
            unlink($path);
        }
    });

    return $path;
}

/**
 * The entries that the `cloud-run` channel wrote to the file, each line
 * decoded as the JSON object that Cloud Logging reads.
 *
 * @return list<array<string, mixed>>
 */
function cloudRunLogEntries(string $path): array
{
    return array_map(
        fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
        file($path, FILE_IGNORE_NEW_LINES) ?: [],
    );
}
