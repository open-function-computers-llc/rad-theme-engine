<?php

namespace ofc\tests;

use ofc\Site;
use WP_UnitTestCase;

class RadTestCase extends WP_UnitTestCase
{
    protected function tearDown(): void
    {
        parent::tearDown();
        $reflection = new \ReflectionClass(Site::class);
        $instance = $reflection->getProperty('instance');
        $instance->setAccessible(true);
        $instance->setValue(null, null);
    }
}
