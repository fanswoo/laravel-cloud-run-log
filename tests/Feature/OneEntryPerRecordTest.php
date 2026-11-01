<?php

use Illuminate\Support\Facades\Log;
use Monolog\LogRecord;

/*
 * Each record is one JSON line, which Cloud Logging reads from a container's
 * standard output or standard error as one entry: its `severity` the entry's
 * severity, its `message` the entry's summary.
 */

it('writes each record as one JSON line that names its severity, its time and its message', function () {
    $log = sendTheCloudRunLogToAFile();

    Log::channel('cloud-run')->warning("The import stopped\nat row 7", ['row' => 7]);
    Log::channel('cloud-run')->info('Imported {count} rows', ['count' => 3]);

    expect(file($log))->toHaveCount(2)
        ->and(cloudRunLogEntries($log))->sequence(
            fn ($entry) => $entry->toMatchArray([
                'severity' => 'WARNING',
                'message' => "The import stopped\nat row 7",
                'context' => ['row' => 7],
            ])->time->toMatch('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}[+-]\d\d:\d\d$/'),
            fn ($entry) => $entry->toMatchArray(['severity' => 'INFO', 'message' => 'Imported 3 rows'])
                ->not->toHaveKeys(['extra', 'level', 'level_name', 'datetime']),
        );
});

it('writes no empty context and no empty extra', function () {
    $log = sendTheCloudRunLogToAFile();

    Log::channel('cloud-run')->info('Imported');

    expect(cloudRunLogEntries($log)[0])->not->toHaveKeys(['context', 'extra']);
});

it('keeps the stack trace of an exception inside the entry of its record', function () {
    $log = sendTheCloudRunLogToAFile();

    Log::channel('cloud-run')->warning('Retrying the import', ['exception' => new RuntimeException('Timed out')]);

    $entries = cloudRunLogEntries($log);

    expect(file($log))->toHaveCount(1)
        ->and($entries[0]['context']['exception'])->toMatchArray([
            'class' => RuntimeException::class,
            'message' => 'Timed out',
        ])
        ->and($entries[0]['context']['exception']['trace'] ?? [])->not->toBeEmpty();
});

it('writes an extra that is named as a Cloud Logging field as a field of the entry', function () {
    $log = sendTheCloudRunLogToAFile();

    Log::channel('cloud-run')->pushProcessor(fn (LogRecord $record): LogRecord => $record->with(extra: [
        ...$record->extra,
        'logging.googleapis.com/labels' => ['tenant' => 'acme'],
        'request_id' => 'r-1',
    ]));
    Log::channel('cloud-run')->info('Imported');

    expect(cloudRunLogEntries($log)[0])->toMatchArray([
        'logging.googleapis.com/labels' => ['tenant' => 'acme'],
        'extra' => ['request_id' => 'r-1'],
    ]);
});
