<?php

declare(strict_types=1);

namespace IntrAgile\Observability\Tests\Feature\Health;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use IntrAgile\Observability\Health\CheckFailedNotification;
use IntrAgile\Observability\Tests\TestCase;
use Spatie\Health\Notifications\Notifiable;

final class CheckFailedNotificationTest extends TestCase
{
    private const THROTTLE_KEY = 'health:latestNotificationSentAt:mail';

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('cache.default', 'array');
    }

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'health.notifications.enabled' => true,
            'health.notifications.throttle_notifications_for_minutes' => 60,
            'health.notifications.throttle_notifications_key' => 'health:latestNotificationSentAt:',
            'observability.health.notifications.environments' => ['production'],
            'observability.health.notifications.override' => null,
            'observability.health.notifications.instance' => 'shop',
            'observability.deploy_id' => null,
        ]);

        Cache::flush();
    }

    public function test_staging_without_override_is_suppressed(): void
    {
        $this->setEnvironment('staging');

        self::assertFalse($this->notification()->shouldSend($this->notifiable(), 'mail'));
    }

    public function test_staging_with_override_true_defers_to_parent(): void
    {
        $this->setEnvironment('staging');
        config(['observability.health.notifications.override' => true]);

        self::assertTrue($this->notification()->shouldSend($this->notifiable(), 'mail'));
        self::assertTrue(Cache::has(self::THROTTLE_KEY));

        // The second call is throttled by the parent implementation.
        self::assertFalse($this->notification()->shouldSend($this->notifiable(), 'mail'));
    }

    public function test_production_with_override_false_is_suppressed(): void
    {
        $this->setEnvironment('production');
        config(['observability.health.notifications.override' => 'false']);

        self::assertFalse($this->notification()->shouldSend($this->notifiable(), 'mail'));
    }

    public function test_production_default_sends(): void
    {
        $this->setEnvironment('production');

        self::assertTrue($this->notification()->shouldSend($this->notifiable(), 'mail'));
    }

    public function test_suppression_leaves_throttle_cache_untouched(): void
    {
        $this->setEnvironment('staging');

        self::assertFalse($this->notification()->shouldSend($this->notifiable(), 'mail'));
        self::assertFalse(Cache::has(self::THROTTLE_KEY));

        // The policy runs before the parent, so even an unthrottled setup stays suppressed.
        config(['health.notifications.throttle_notifications_for_minutes' => 0]);

        self::assertFalse($this->notification()->shouldSend($this->notifiable(), 'mail'));
        self::assertFalse(Cache::has(self::THROTTLE_KEY));
    }

    public function test_trans_parameters_in_production_without_deploy_id(): void
    {
        $this->setEnvironment('production');

        self::assertSame(
            ['application_name' => 'shop', 'env_name' => 'production'],
            $this->notification()->transParameters(),
        );
    }

    public function test_trans_parameters_in_production_with_deploy_id(): void
    {
        $this->setEnvironment('production');
        config(['observability.deploy_id' => 'abc123']);

        self::assertSame(
            ['application_name' => 'shop [deploy: abc123]', 'env_name' => 'production'],
            $this->notification()->transParameters(),
        );
    }

    public function test_trans_parameters_in_staging_without_deploy_id(): void
    {
        $this->setEnvironment('staging');

        self::assertSame(
            ['application_name' => '[staging] shop', 'env_name' => 'staging'],
            $this->notification()->transParameters(),
        );
    }

    public function test_trans_parameters_in_staging_with_deploy_id(): void
    {
        $this->setEnvironment('staging');
        config(['observability.deploy_id' => 'abc123']);

        self::assertSame(
            ['application_name' => '[staging] shop [deploy: abc123]', 'env_name' => 'staging'],
            $this->notification()->transParameters(),
        );
    }

    public function test_instance_falls_back_to_app_name(): void
    {
        $this->setEnvironment('production');
        config(['observability.health.notifications.instance' => null, 'app.name' => 'Fallback']);

        self::assertSame('Fallback', $this->notification()->transParameters()['application_name']);
    }

    public function test_instance_falls_back_to_default_label(): void
    {
        $this->setEnvironment('production');
        config(['observability.health.notifications.instance' => '', 'app.name' => null]);

        self::assertSame('Laravel application', $this->notification()->transParameters()['application_name']);
    }

    public function test_empty_deploy_id_adds_no_suffix(): void
    {
        $this->setEnvironment('production');
        config(['observability.deploy_id' => '']);

        self::assertSame('shop', $this->notification()->transParameters()['application_name']);
    }

    public function test_via_reads_channels_for_this_class(): void
    {
        config(['health.notifications.notifications' => [CheckFailedNotification::class => ['mail']]]);

        self::assertSame(['mail'], $this->notification()->via());
    }

    public function test_notification_fake_respects_policy(): void
    {
        config(['health.notifications.notifications' => [CheckFailedNotification::class => ['mail']]]);
        Notification::fake();

        $this->setEnvironment('production');
        $this->notifiable()->notify($this->notification());
        Notification::assertSentTo($this->notifiable(), CheckFailedNotification::class);

        Notification::fake();
        Cache::flush();

        $this->setEnvironment('staging');
        $this->notifiable()->notify($this->notification());
        Notification::assertNothingSent();
    }

    private function setEnvironment(string $environment): void
    {
        app()->detectEnvironment(static fn (): string => $environment);
    }

    private function notification(): CheckFailedNotification
    {
        return new CheckFailedNotification([]);
    }

    private function notifiable(): Notifiable
    {
        return new Notifiable;
    }
}
