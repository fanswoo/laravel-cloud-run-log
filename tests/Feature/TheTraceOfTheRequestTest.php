<?php

use Fanswoo\CloudRunLog\AddTheTraceOfTheRequest;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

/*
 * An entry that names the trace of its request is shown under that request's
 * own entry in Logs Explorer.
 */

/** A route that writes one entry through the `cloud-run` channel. */
function addTheCloudRunLogProbe(): void
{
    Route::get('/cloud-run-log-probe', function () {
        Log::channel('cloud-run')->info('Probed');

        return response()->noContent();
    });
}

/** Names the Google Cloud project to the `cloud-run` channel; null names none. */
function nameTheCloudRunLogProject(?string $project): void
{
    config(['logging.channels.cloud-run.processors' => array_map(
        fn (string|array $processor): string|array => ($processor['processor'] ?? null) === AddTheTraceOfTheRequest::class
            ? ['processor' => AddTheTraceOfTheRequest::class, 'with' => ['project' => $project]]
            : $processor,
        config('logging.channels.cloud-run.processors'),
    )]);
}

it('names the trace of the request that wrote the entry, from either trace header', function () {
    $log = sendTheCloudRunLogToAFile();
    nameTheCloudRunLogProject('acme-prod');
    addTheCloudRunLogProbe();

    $this->get('/cloud-run-log-probe', ['X-Cloud-Trace-Context' => '105445AA7843BC8BF206B12000100000/2108;o=1'])->assertNoContent();
    $this->get('/cloud-run-log-probe', ['traceparent' => '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01'])->assertNoContent();
    $this->get('/cloud-run-log-probe', ['traceparent' => '01-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01-later-field'])->assertNoContent();

    expect(array_column(cloudRunLogEntries($log), 'logging.googleapis.com/trace'))->toBe([
        'projects/acme-prod/traces/105445aa7843bc8bf206b12000100000',
        'projects/acme-prod/traces/0af7651916cd43dd8448eb211c80319c',
        'projects/acme-prod/traces/4bf92f3577b34da6a3ce929d0e0e4736',
    ])->and(cloudRunLogEntries($log)[0])->not->toHaveKey('extra');
});

it('names no trace for a request that carries none, or none that is valid, after one that did', function () {
    $log = sendTheCloudRunLogToAFile();
    nameTheCloudRunLogProject('acme-prod');
    addTheCloudRunLogProbe();

    $this->get('/cloud-run-log-probe', ['X-Cloud-Trace-Context' => '105445aa7843bc8bf206b12000100000/2108;o=1'])->assertNoContent();
    $this->get('/cloud-run-log-probe')->assertNoContent();
    $this->get('/cloud-run-log-probe', [
        'X-Cloud-Trace-Context' => 'not-a-trace',
        'traceparent' => '00-00000000000000000000000000000000-b7ad6b7169203331-01',
    ])->assertNoContent();

    expect(cloudRunLogEntries($log))->sequence(
        fn ($entry) => $entry->toHaveKey('logging.googleapis.com/trace'),
        fn ($entry) => $entry->not->toHaveKey('logging.googleapis.com/trace'),
        fn ($entry) => $entry->not->toHaveKey('logging.googleapis.com/trace'),
    );
});

it('names no trace for a traceparent that the Trace Context rules refuse', function (string $traceparent) {
    $log = sendTheCloudRunLogToAFile();
    nameTheCloudRunLogProject('acme-prod');
    addTheCloudRunLogProbe();

    $this->get('/cloud-run-log-probe', ['traceparent' => $traceparent])->assertNoContent();

    expect(cloudRunLogEntries($log)[0])->not->toHaveKey('logging.googleapis.com/trace');
})->with([
    'version ff' => ['ff-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01'],
    'a parent ID of zeros' => ['00-0af7651916cd43dd8448eb211c80319c-0000000000000000-01'],
    'a further field after version 00' => ['00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01-extra'],
    'uppercase hex' => ['00-0AF7651916CD43DD8448EB211C80319C-B7AD6B7169203331-01'],
]);

it('names no trace while the project is unknown, which the trace field needs', function () {
    $log = sendTheCloudRunLogToAFile();
    nameTheCloudRunLogProject(null);
    addTheCloudRunLogProbe();

    $this->get('/cloud-run-log-probe', ['X-Cloud-Trace-Context' => '105445aa7843bc8bf206b12000100000/2108;o=1'])->assertNoContent();

    expect(cloudRunLogEntries($log)[0])->not->toHaveKeys(['logging.googleapis.com/trace', 'extra']);
});

it('names no trace for a record that a console command writes', function () {
    $log = sendTheCloudRunLogToAFile();
    nameTheCloudRunLogProject('acme-prod');

    Log::channel('cloud-run')->info('Imported');

    expect(cloudRunLogEntries($log)[0])->not->toHaveKey('logging.googleapis.com/trace');
});
