<?php

namespace Fanswoo\CloudRunLog;

use Throwable;

/**
 * An exception as Error Reporting reads it for PHP: one of the prefixes
 * `PHP Notice: `, `PHP Parse error: `, `PHP Fatal error: ` and `PHP Warning: `,
 * followed by the exception in the text that PHP writes for it, the earliest
 * exception of its chain first and each later one after `Next`.
 *
 * The text is built here rather than taken from `(string) $exception`, for two
 * reasons. PHP's own text names the start of every string argument of every
 * frame, such as a password handed to a database connection, and Error
 * Reporting shows the text to everybody who may read it; here each frame names
 * its function only, as PHP does with `zend.exception_ignore_args`. And an
 * exception class may write itself in a text of its own, which Error Reporting
 * could not read.
 */
final class PhpStackTrace
{
    /** The prefix that marks a stack trace as PHP's for Error Reporting. */
    private const string ERROR_REPORTING_PREFIX = 'PHP Fatal error: ';

    public static function forErrorReporting(Throwable $exception): string
    {
        $chain = [];

        for ($link = $exception; $link !== null; $link = $link->getPrevious()) {
            $chain[] = $link;
        }

        return self::ERROR_REPORTING_PREFIX.implode("\n\nNext ", array_map(self::textOf(...), array_reverse($chain)));
    }

    /**
     * One exception as PHP writes it, with no argument in any frame.
     */
    private static function textOf(Throwable $exception): string
    {
        $frames = [];

        foreach ($exception->getTrace() as $index => $frame) {
            $place = isset($frame['file']) ? "{$frame['file']}(".($frame['line'] ?? 0).')' : '[internal function]';
            $frames[] = "#{$index} {$place}: ".($frame['class'] ?? '').($frame['type'] ?? '')."{$frame['function']}()";
        }

        $frames[] = '#'.count($frames).' {main}';
        $message = $exception->getMessage() === '' ? '' : ': '.$exception->getMessage();

        return $exception::class."{$message} in {$exception->getFile()}:{$exception->getLine()}\nStack trace:\n".implode("\n", $frames);
    }
}
