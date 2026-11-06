<?php

declare(strict_types=1);

namespace IntrAgile\Observability\Tests\Feature;

use IntrAgile\Observability\Tests\TestCase;

final class ServiceProviderTest extends TestCase
{
    public function test_config_is_merged(): void
    {
        $this->assertSame(10, config('observability.error_digest.max_groups'));
    }
}
