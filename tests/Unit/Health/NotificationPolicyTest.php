<?php

declare(strict_types=1);

namespace IntrAgile\Observability\Tests\Unit\Health;

use IntrAgile\Observability\Health\NotificationPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NotificationPolicyTest extends TestCase
{
    /**
     * @return iterable<string, array{string, array<array-key, mixed>, mixed, bool}>
     */
    public static function cases(): iterable
    {
        yield 'production listed, no override' => ['production', ['production'], null, true];
        yield 'staging not listed, no override' => ['staging', ['production'], null, false];
        yield 'empty string is not an override' => ['staging', ['production'], '', false];
        yield 'bool true override' => ['staging', ['production'], true, true];
        yield 'string true override' => ['staging', ['production'], 'true', true];
        yield 'string 1 override' => ['staging', ['production'], '1', true];
        yield 'string on override' => ['staging', ['production'], 'on', true];
        yield 'string yes override' => ['staging', ['production'], 'yes', true];
        yield 'string false override' => ['production', ['production'], 'false', false];
        yield 'string 0 override' => ['production', ['production'], '0', false];
        yield 'string off override' => ['production', ['production'], 'off', false];
        yield 'bool false override' => ['production', ['production'], false, false];
        yield 'unparseable override falls back to the list in production' => ['production', ['production'], 'garbage', true];
        yield 'unparseable override falls back to the list in staging' => ['staging', ['production'], 'ture', false];
        yield 'staging listed' => ['staging', ['production', 'staging'], null, true];
        yield 'empty environment list' => ['production', [], null, false];
        yield 'strict comparison' => ['1', [1], null, false];
    }

    /**
     * @param  array<array-key, mixed>  $environments
     */
    #[DataProvider('cases')]
    public function test_resolves_enabled_state(string $environment, array $environments, mixed $override, bool $expected): void
    {
        self::assertSame($expected, NotificationPolicy::enabled($environment, $environments, $override));
    }
}
