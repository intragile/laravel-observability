<?php

declare(strict_types=1);

namespace IntrAgile\Observability\Tests\Unit;

use IntrAgile\Observability\Support\RootExecutionGuard;
use IntrAgile\Observability\Tests\TestCase;

final class RootExecutionGuardTest extends TestCase
{
    public function test_root_in_guarded_environment_is_blocked(): void
    {
        $this->assertTrue(RootExecutionGuard::shouldBlock(0, 'production', ['production'], false));
    }

    public function test_explicit_override_lets_root_through(): void
    {
        $this->assertFalse(RootExecutionGuard::shouldBlock(0, 'production', ['production'], true));
    }

    public function test_non_root_user_is_never_blocked(): void
    {
        $this->assertFalse(RootExecutionGuard::shouldBlock(1000, 'production', ['production'], false));
    }

    public function test_root_outside_guarded_environments_is_allowed(): void
    {
        $this->assertFalse(RootExecutionGuard::shouldBlock(0, 'local', ['production'], false));
    }

    public function test_missing_posix_extension_never_blocks(): void
    {
        $this->assertFalse(RootExecutionGuard::shouldBlock(null, 'production', ['production'], false));
    }

    public function test_empty_environment_list_never_blocks(): void
    {
        $this->assertFalse(RootExecutionGuard::shouldBlock(0, 'production', [], false));
    }

    public function test_message_explains_the_override(): void
    {
        $message = RootExecutionGuard::message('ARTISAN_ALLOW_ROOT');

        $this->assertStringContainsString('ARTISAN_ALLOW_ROOT=1', $message);
        $this->assertStringContainsString('php artisan', $message);
    }

    public function test_message_uses_the_given_env_name(): void
    {
        $message = RootExecutionGuard::message('MY_ALLOW');

        $this->assertStringContainsString('MY_ALLOW=1', $message);
        $this->assertStringNotContainsString('ARTISAN_ALLOW_ROOT', $message);
    }

    public function test_current_euid_matches_posix_when_available(): void
    {
        $expected = function_exists('posix_geteuid') ? posix_geteuid() : null;

        $this->assertSame($expected, RootExecutionGuard::currentEuid());
    }
}
