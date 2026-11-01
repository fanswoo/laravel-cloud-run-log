<?php

namespace Fanswoo\CloudRunLog\Tests\Support;

use RuntimeException;

/**
 * An exception that writes itself in a text of its own, as some libraries'
 * exceptions do, in place of PHP's text with its stack trace.
 */
class ExceptionWithATextOfItsOwn extends RuntimeException
{
    public function __toString(): string
    {
        return json_encode(['error' => $this->getMessage()], JSON_THROW_ON_ERROR);
    }
}
