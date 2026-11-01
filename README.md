# Laravel Cloud Run Log

[![Tests](https://github.com/fanswoo/laravel-cloud-run-log/actions/workflows/tests.yml/badge.svg)](https://github.com/fanswoo/laravel-cloud-run-log/actions/workflows/tests.yml)

`fanswoo/laravel-cloud-run-log` writes the logs of a Laravel application on Google Cloud Run in the shape that Cloud Logging reads: one entry for each record, with its severity, filed under the trace of the request that wrote it, and with every reported exception in the format that Error Reporting groups.

- [The problem](#the-problem)
- [What the package does](#what-the-package-does)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Reading the logs](#reading-the-logs)
- [Laravel Octane](#laravel-octane)
- [Limits](#limits)
- [Testing](#testing)
- [Changelog](#changelog)
- [License](#license)

## The problem

Cloud Run stores every line that a container writes to standard output or standard error as a log entry of its own. Laravel's `stderr` channel writes Monolog's line format, which leads to three problems:

1. **One error becomes many entries.** The stack trace of an exception follows its record on separate lines, so a trace of 50 frames becomes 50 entries, mixed with the lines of other requests.
2. **No entry carries a severity.** A plain-text line has no `severity` field, so the severity filter of Logs Explorer does not apply to the application's logs.
3. **No entry names its request.** Logs Explorer cannot show which request wrote which entry.

## What the package does

The package registers a log channel named `cloud-run`. Set `LOG_CHANNEL=cloud-run`, and every record is written as follows.

- **One JSON line per record.** Cloud Run parses a JSON line as a structured entry ([Structured logging](https://docs.cloud.google.com/logging/docs/structured-logging)). `severity`, `time` and `message` become the entry's own fields; the record's context, the stack trace of an exception included, stays inside the one entry. The formatter extends Monolog's `GoogleCloudLoggingFormatter`.
- **The trace of the request.** Cloud Run's front end gives every request a trace, in `X-Cloud-Trace-Context` and in `traceparent`, and files the request's own entry under it. The package names that trace in `logging.googleapis.com/trace`, as `projects/<project>/traces/<trace>`, so Logs Explorer shows each record under the request that wrote it. A queued job that Cloud Tasks delivers over HTTP runs inside that delivery's request, so its records sit under the delivery.
- **Errors for Error Reporting.** A record at the `error` level or above that carries an exception in its context, as every exception that Laravel reports does, writes its stack trace in `stack_trace`: the prefix `PHP Fatal error: ` followed by the exception in the text that PHP writes for it, the format that [Error Reporting](https://docs.cloud.google.com/error-reporting/docs/formatting-error-messages) reads for PHP. Error Reporting then groups the occurrences of the same error. The text is built from the exception's frames without their arguments: PHP's own text names the start of each string argument, such as a password, and an exception class may write itself in a text of its own. The exception in the record's context then names its class, message, code and file without its list of frames, so the entry holds the stack trace once.
- **Cloud Logging fields from processors.** An extra of a record whose name starts with `logging.googleapis.com/`, such as `logging.googleapis.com/labels` added by a processor of your own, is written as a field of the entry.

A record written during a request, with an exception, looks like this (shortened):

```json
{"message":"The import failed","context":{"exception":{"class":"RuntimeException","message":"The import failed","code":0,"file":"/app/app/Imports/RunImport.php:42"}},"channel":"production","severity":"ERROR","time":"2026-11-01T10:15:30.123+00:00","logging.googleapis.com/trace":"projects/acme-prod/traces/105445aa7843bc8bf206b12000100000","stack_trace":"PHP Fatal error: RuntimeException: The import failed in /app/app/Imports/RunImport.php:42\nStack trace:\n#0 ..."}
```

## Requirements

- PHP 8.3 or later
- Laravel 12 or 13
- Monolog 3.6 or later

## Installation

The package is not on Packagist. Add its repository to `composer.json`, then require it:

```json
"repositories": [
    {
        "name": "laravel-cloud-run-log",
        "type": "vcs",
        "url": "https://github.com/fanswoo/laravel-cloud-run-log"
    }
]
```

```bash
composer require fanswoo/laravel-cloud-run-log:dev-main
```

Laravel discovers the service provider automatically. Then, on every Cloud Run service and job:

```bash
LOG_CHANNEL=cloud-run
CLOUD_RUN_LOG_PROJECT=<the ID of the Google Cloud project>
```

Cloud Run does not set the project ID in the container, and the trace field needs it: without a project, no entry names a trace, and everything else still applies.

## Configuration

Publish the configuration file if you want to change the defaults:

```bash
php artisan vendor:publish --tag=cloud-run-log-config
```

| Key | Environment variable | Default | Effect |
| --- | --- | --- | --- |
| `project` | `CLOUD_RUN_LOG_PROJECT`, then `GOOGLE_CLOUD_PROJECT` | `null` | The Google Cloud project that the trace field names. `null` names no trace. |
| `level` | `CLOUD_RUN_LOG_LEVEL`, then `LOG_LEVEL` | `debug` | The lowest level that the channel writes. |
| `stream` | `CLOUD_RUN_LOG_STREAM` | `php://stderr` | Where the channel writes its lines. Cloud Run reads standard output and standard error alike. |

`GOOGLE_CLOUD_PROJECT` is also the default project of the Google client libraries. Set `CLOUD_RUN_LOG_PROJECT` when the application must not change their default.

An application that defines a channel named `cloud-run` in its own `config/logging.php` keeps that channel, and the package registers none. To change one part of the package's channel, build it with the package and change that part:

```php
use Fanswoo\CloudRunLog\CloudRunLogServiceProvider;

'channels' => [
    'cloud-run' => [
        ...CloudRunLogServiceProvider::channel([
            'project' => env('CLOUD_RUN_LOG_PROJECT'),
            'level' => env('LOG_LEVEL', 'debug'),
        ]),
        'tap' => [App\Logging\AddTenantLabel::class],
    ],
],
```

## Reading the logs

Open **Logging → Logs Explorer** in the Google Cloud console.

- **Severity.** `severity>=ERROR` selects the errors and worse.
- **One service.** `resource.labels.service_name="<service>"` selects a service, and `resource.labels.job_name="<job>"` a Cloud Run job.
- **Fields.** The record's context is in `jsonPayload.context`, and an exception in `jsonPayload.context.exception`, for example `jsonPayload.context.exception.class="RuntimeException"`. The stack trace of an error is in `jsonPayload.stack_trace`, and that of a warning in `jsonPayload.context.exception.trace`.
- **One request.** An entry that a request wrote is shown under the request's own entry.
- **Errors.** Every record with an exception at the `error` level or above appears in **Error Reporting**, grouped with the other occurrences of the same stack trace.

## Laravel Octane

Laravel builds the processors of a channel once and keeps them for the life of the process, which under Laravel Octane serves many requests. The trace processor holds nothing of a request: it reads the request from the current container for each record, as Laravel's own `ContextLogProcessor` reads the context. A record never names the trace of an earlier request.

The trace is not kept in Laravel's Context, because Laravel copies the Context into the jobs that a request queues: the records of a job would be filed under the request that queued it.

## Limits

- **A client can name its own trace.** Cloud Run uses a valid trace header that a request arrives with, for its own request entry and for these records alike. The trace ID is 128 random bits, so a client cannot file its records under another person's request without knowing that request's trace.
- **The trace, not the span.** An entry names the trace of its request, which is what Logs Explorer groups by. It names neither `logging.googleapis.com/spanId` nor `logging.googleapis.com/trace_sampled`.
- **Only records of Laravel's logger.** A line that PHP or the web server writes itself, such as a fatal error outside Laravel's error handler, or a line written with `error_log()`, keeps its own format.
- **Records with an exception at `error` or above reach Error Reporting.** A warning that carries an exception keeps its stack trace in its context only, and an error that carries no exception has no stack trace to group by.
- **Cloud Logging limits the size of an entry** to 256 KB ([Quotas and limits](https://docs.cloud.google.com/logging/quotas)). Keep large data out of a record's context.

## Testing

```bash
composer test
```

The feature tests run on Orchestra Testbench. They write the channel to a file and read each line back as the JSON object that Cloud Logging reads. They send requests with each trace header through the test application, and report exceptions through Laravel's exception handler.

Check the code style with Laravel Pint:

```bash
composer test:lint
```

## Changelog

See [CHANGELOG.md](CHANGELOG.md).

## License

The MIT License. See [LICENSE](LICENSE).
