<?php

namespace Fanswoo\CloudRunLog;

use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Names in each log record the trace of the request that wrote it, in Cloud
 * Logging's own field, `logging.googleapis.com/trace`, which
 * `CloudLoggingFormatter` writes as a field of the entry. Logs Explorer then
 * shows the record under that request's own entry.
 *
 * Cloud Run's front end hands every request it serves a trace, in
 * `X-Cloud-Trace-Context` (`TRACE_ID/SPAN_ID;o=1`) and in the W3C
 * `traceparent` (`00-TRACE_ID-SPAN_ID-01`), and files the request's own entry
 * under it. A request that arrives with either header keeps the trace it
 * names, for Cloud Run's entry and for these records alike. A queued job that
 * Cloud Tasks delivers over HTTP runs inside that delivery's request, so its
 * records sit under the delivery that ran it.
 *
 * Laravel builds the processors of a channel once and keeps them for as long
 * as the process runs, which under Laravel Octane is for many requests. This
 * processor therefore holds nothing of a request: it reads the request from
 * the current container for each record, as Laravel's own
 * `ContextLogProcessor` reads the context. Laravel's Context is not used,
 * because Laravel copies it into the jobs a request queues, which would file
 * the records of a job under the request that queued it. A record that a
 * console command writes names no trace: its request carries neither header.
 */
final class AddTheTraceOfTheRequest implements ProcessorInterface
{
    /**
     * @param  string|null  $project  The ID of the Google Cloud project, which
     *                                the trace field names; null outside Google
     *                                Cloud, where no record names a trace.
     */
    public function __construct(private readonly ?string $project = null) {}

    public function __invoke(LogRecord $record): LogRecord
    {
        $container = Container::getInstance();

        if (blank($this->project) || ! $container->bound('request')) {
            return $record;
        }

        $traceId = self::traceIdOf($container->make('request'));

        return $traceId === null ? $record : $record->with(extra: [
            ...$record->extra,
            'logging.googleapis.com/trace' => "projects/{$this->project}/traces/{$traceId}",
        ]);
    }

    /**
     * The trace ID that the request carries, in lowercase, or null when it
     * carries none that is valid. `X-Cloud-Trace-Context` comes first.
     */
    private static function traceIdOf(Request $request): ?string
    {
        return self::traceIdOfCloudTraceContext(trim((string) $request->header('X-Cloud-Trace-Context')))
            ?? self::traceIdOfTraceparent(trim((string) $request->header('traceparent')));
    }

    /**
     * The trace ID of `TRACE_ID/SPAN_ID;o=OPTIONS`, whose span and options may
     * be missing. An ID of zeros is no trace.
     */
    private static function traceIdOfCloudTraceContext(string $header): ?string
    {
        if (preg_match('/^([0-9a-f]{32})(?:[\/;]|$)/i', $header, $match) !== 1 || self::isZero($match[1])) {
            return null;
        }

        return strtolower($match[1]);
    }

    /**
     * The trace ID of a W3C `traceparent`, `VERSION-TRACE_ID-PARENT_ID-FLAGS`,
     * by the rules of Trace Context: lowercase hex, no version `ff`, no trace
     * or parent ID of zeros, and further fields only after a version above
     * `00`.
     */
    private static function traceIdOfTraceparent(string $header): ?string
    {
        if (preg_match('/^([0-9a-f]{2})-([0-9a-f]{32})-([0-9a-f]{16})-[0-9a-f]{2}(-.*)?$/', $header, $match) !== 1) {
            return null;
        }

        [, $version, $traceId, $parentId] = $match;
        $hasFurtherFields = isset($match[4]);

        if ($version === 'ff' || ($version === '00' && $hasFurtherFields) || self::isZero($traceId) || self::isZero($parentId)) {
            return null;
        }

        return $traceId;
    }

    private static function isZero(string $id): bool
    {
        return trim($id, '0') === '';
    }
}
