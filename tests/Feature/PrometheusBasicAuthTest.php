<?php

declare(strict_types=1);

namespace IntrAgile\Observability\Tests\Feature;

use Illuminate\Routing\Router;
use IntrAgile\Observability\Http\Middleware\PrometheusBasicAuth;
use IntrAgile\Observability\Tests\TestCase;

final class PrometheusBasicAuthTest extends TestCase
{
    private string $tempDir;

    /**
     * @param  Router  $router
     */
    protected function defineRoutes($router): void
    {
        $router->get('/_probe', fn (): string => 'ok')->middleware(PrometheusBasicAuth::class);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir().'/obs-'.bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0700, true);

        $this->configure('prometheus', 's3cret', null);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);

        parent::tearDown();
    }

    public function test_accepts_correct_credentials(): void
    {
        $this->withBasicAuth('prometheus', 's3cret')
            ->get('/_probe')
            ->assertOk()
            ->assertSee('ok');
    }

    public function test_rejects_wrong_password(): void
    {
        $this->withBasicAuth('prometheus', 'wrong')
            ->get('/_probe')
            ->assertUnauthorized()
            ->assertHeader('WWW-Authenticate', 'Basic realm="Prometheus"');
    }

    public function test_rejects_wrong_username(): void
    {
        $this->withBasicAuth('intruder', 's3cret')
            ->get('/_probe')
            ->assertUnauthorized()
            ->assertHeader('WWW-Authenticate', 'Basic realm="Prometheus"');
    }

    public function test_rejects_request_without_credentials(): void
    {
        $this->get('/_probe')
            ->assertUnauthorized()
            ->assertHeader('WWW-Authenticate', 'Basic realm="Prometheus"');
    }

    public function test_rejects_everything_when_no_password_configured(): void
    {
        foreach ([null, ''] as $password) {
            $this->configure('prometheus', $password, null);

            $this->withBasicAuth('', '')
                ->get('/_probe')
                ->assertUnauthorized()
                ->assertHeader('WWW-Authenticate', 'Basic realm="Prometheus"');

            $this->withBasicAuth('prometheus', '')
                ->get('/_probe')
                ->assertUnauthorized();

            $this->get('/_probe')->assertUnauthorized();
        }
    }

    public function test_password_file_is_trimmed(): void
    {
        $file = $this->tempDir.'/password';
        file_put_contents($file, "s3cret\n");
        $this->configure('prometheus', null, $file);

        $this->withBasicAuth('prometheus', 's3cret')
            ->get('/_probe')
            ->assertOk();

        $this->withBasicAuth('prometheus', "s3cret\n")
            ->get('/_probe')
            ->assertUnauthorized();
    }

    public function test_rejects_when_password_file_is_missing(): void
    {
        $this->configure('prometheus', null, $this->tempDir.'/does-not-exist');

        $this->withBasicAuth('prometheus', '')
            ->get('/_probe')
            ->assertUnauthorized();

        $this->withBasicAuth('prometheus', 'anything')
            ->get('/_probe')
            ->assertUnauthorized();
    }

    public function test_rejects_everything_when_password_file_is_blank(): void
    {
        $file = $this->tempDir.'/password';
        file_put_contents($file, "  \n");
        $this->configure('prometheus', null, $file);

        $this->withBasicAuth('prometheus', '')
            ->get('/_probe')
            ->assertUnauthorized();

        $this->withBasicAuth('prometheus', '  ')
            ->get('/_probe')
            ->assertUnauthorized();
    }

    public function test_config_password_takes_precedence_over_file(): void
    {
        $file = $this->tempDir.'/password';
        file_put_contents($file, "from-file\n");
        $this->configure('prometheus', 'from-config', $file);

        $this->withBasicAuth('prometheus', 'from-config')
            ->get('/_probe')
            ->assertOk();

        $this->withBasicAuth('prometheus', 'from-file')
            ->get('/_probe')
            ->assertUnauthorized();
    }

    private function configure(?string $username, ?string $password, ?string $passwordFile): void
    {
        config()->set('observability.prometheus.basic_auth', [
            'enabled' => true,
            'username' => $username,
            'password' => $password,
            'password_file' => $passwordFile,
        ]);
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory.'/'.$entry;
            is_dir($path) && ! is_link($path) ? $this->removeDirectory($path) : unlink($path);
        }

        rmdir($directory);
    }
}
