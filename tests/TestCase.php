<?php

namespace Fanswoo\CloudRunLog\Tests;

use Fanswoo\CloudRunLog\CloudRunLogServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [CloudRunLogServiceProvider::class];
    }
}
