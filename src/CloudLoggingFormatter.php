<?php

namespace Fanswoo\CloudRunLog;

use Monolog\Formatter\GoogleCloudLoggingFormatter;
use Monolog\Level;
use Monolog\LogRecord;
use Throwable;

/**
 * Writes each log record as the one JSON line that Cloud Logging reads from a
 * container's standard error as one entry: `severity`, `time` and `message`
 * become the entry's own fields, and the stack trace of an exception stays
 * inside the entry. The `cloud-run` channel that `CloudRunLogServiceProvider`
 * registers uses it.
 *
 * An extra of the record named with Cloud Logging's prefix,
 * `logging.googleapis.com/`, such as the trace that `AddTheTraceOfTheRequest`
 * names, is written beside `message`, where Cloud Logging reads it as a field
 * of the entry.
 *
 * A record of an error or worse that carries an exception, as every exception
 * that Laravel reports does, writes the exception's stack trace in
 * `stack_trace`, which Error Reporting reads before `message`, and then groups
 * the entry with the other errors of the same stack trace (`PhpStackTrace`).
 * The exception in its context then names its class, message, code and file
 * without the list of frames, so the entry holds the stack trace once. A
 * warning that carries an exception keeps that list in its context, so Error
 * Reporting does not count it.
 */
final class CloudLoggingFormatter extends GoogleCloudLoggingFormatter
{
    /** The prefix of the fields that Cloud Logging reads by their name. */
    private const string CLOUD_LOGGING_FIELD_PREFIX = 'logging.googleapis.com/';

    public function __construct()
    {
        parent::__construct(ignoreEmptyContextAndExtra: true, includeStacktraces: true);
    }

    /**
     * @return array<array<mixed>|bool|float|int|\stdClass|string|null>
     */
    protected function normalizeRecord(LogRecord $record): array
    {
        $cloudLoggingFields = array_filter(
            $record->extra,
            fn (int|string $key): bool => str_starts_with((string) $key, self::CLOUD_LOGGING_FIELD_PREFIX),
            ARRAY_FILTER_USE_KEY,
        );

        $exception = $record->context['exception'] ?? null;
        $forErrorReporting = $exception instanceof Throwable && $record->level->isHigherThan(Level::Warning);

        // The formatter serves one record at a time, so the setting is put
        // back before the next record whatever happens.
        $this->includeStacktraces(! $forErrorReporting);

        try {
            $normalized = [
                ...parent::normalizeRecord($record->with(extra: array_diff_key($record->extra, $cloudLoggingFields))),
                ...$cloudLoggingFields,
            ];
        } finally {
            $this->includeStacktraces();
        }

        if ($forErrorReporting) {
            $normalized['stack_trace'] = PhpStackTrace::forErrorReporting($exception);
        }

        return $normalized;
    }
}
