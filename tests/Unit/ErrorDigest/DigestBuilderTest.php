<?php

declare(strict_types=1);

namespace IntrAgile\Observability\Tests\Unit\ErrorDigest;

use Illuminate\Support\Carbon;
use IntrAgile\Observability\ErrorDigest\DigestBuilder;
use IntrAgile\Observability\ErrorDigest\ErrorReport;
use IntrAgile\Observability\Tests\TestCase;
use LogicException;
use RuntimeException;

final class DigestBuilderTest extends TestCase
{
    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../../database/migrations');
    }

    private function report(string $type, string $message, ?Carbon $createdAt = null, ?Carbon $sentAt = null): ErrorReport
    {
        $report = new ErrorReport;
        $report->exception_type = $type;
        $report->message = $message;
        $report->code = null;
        $report->fingerprint = sha1($type."\n".$message);
        $report->sent_at = $sentAt;
        $report->created_at = $createdAt ?? Carbon::now();
        $report->save();

        return $report;
    }

    private function builder(): DigestBuilder
    {
        return new DigestBuilder;
    }

    public function test_groups_rows_by_type_and_message_with_counts(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->report(RuntimeException::class, 'runtime failure');
        }
        for ($i = 0; $i < 5; $i++) {
            $this->report(LogicException::class, 'logic failure');
        }
        $this->report(RuntimeException::class, 'other failure');

        $groups = $this->builder()->build(ErrorReport::query()->get(), 10);

        $this->assertCount(3, $groups);
        $this->assertSame(
            [
                [LogicException::class, 'logic failure', 5],
                [RuntimeException::class, 'runtime failure', 3],
                [RuntimeException::class, 'other failure', 1],
            ],
            array_map(fn (array $group): array => [$group['type'], $group['message'], $group['count']], $groups),
        );
    }

    public function test_last_24h_counts_only_recent_rows(): void
    {
        $current = $this->report(RuntimeException::class, 'boom');
        $this->report(RuntimeException::class, 'boom', Carbon::now()->subHours(3), Carbon::now()->subHours(2));
        $this->report(RuntimeException::class, 'boom', Carbon::now()->subHours(30), Carbon::now()->subHours(29));

        $groups = $this->builder()->build(ErrorReport::query()->whereKey($current->id)->get(), 10);

        $this->assertCount(1, $groups);
        $this->assertSame(1, $groups[0]['count']);
        $this->assertSame(2, $groups[0]['last_24h']);
    }

    public function test_limits_groups_and_reports_hidden_count(): void
    {
        for ($i = 1; $i <= 12; $i++) {
            $this->report(RuntimeException::class, 'failure number '.$i);
        }

        $rows = ErrorReport::query()->get();
        $builder = $this->builder();
        $groups = $builder->build($rows, 10);

        $this->assertCount(10, $groups);
        $this->assertSame(12, $builder->groupCount($rows));

        $hidden = $builder->groupCount($rows) - count($groups);
        $json = (string) json_encode($builder->payload($groups, 'demo', [], $hidden));

        $this->assertStringContainsString('+2', $json);
    }

    public function test_links_are_rendered_as_buttons(): void
    {
        $this->report(RuntimeException::class, 'boom');
        $builder = $this->builder();
        $groups = $builder->build(ErrorReport::query()->get(), 10);

        $payload = $builder->payload($groups, 'demo', [
            'Grafana' => 'https://grafana.example.com/d/errors',
            'Portainer' => 'http://portainer.example.com/',
            'Broken' => 'javascript:alert(1)',
        ]);

        $buttons = [];
        foreach ($payload['blocks'] as $block) {
            if ($block['type'] !== 'actions' || ! is_array($block['elements'])) {
                continue;
            }

            foreach ($block['elements'] as $element) {
                $this->assertIsArray($element);
                $this->assertIsArray($element['text']);
                $buttons[] = [$element['type'], $element['text']['text'], $element['url']];
            }
        }

        $this->assertSame(
            [
                ['button', 'Grafana', 'https://grafana.example.com/d/errors'],
                ['button', 'Portainer', 'http://portainer.example.com/'],
            ],
            $buttons,
        );
        $this->assertCount(1, array_filter($payload['blocks'], fn (array $block): bool => $block['type'] === 'actions'));
    }

    public function test_no_actions_block_without_links(): void
    {
        $this->report(RuntimeException::class, 'boom');
        $builder = $this->builder();

        $payload = $builder->payload($builder->build(ErrorReport::query()->get(), 10), 'demo', []);

        $this->assertSame([], array_filter($payload['blocks'], fn (array $block): bool => $block['type'] === 'actions'));
        $this->assertStringContainsString('demo', $payload['text']);
    }

    public function test_message_is_escaped_and_truncated(): void
    {
        $this->report(RuntimeException::class, "a <b> & c\n\n".str_repeat('x', 400));
        $builder = $this->builder();

        $json = (string) json_encode($builder->payload($builder->build(ErrorReport::query()->get(), 10), 'demo', []));

        $this->assertStringContainsString('a &lt;b&gt; &amp; c x', $json);
        $this->assertStringNotContainsString(str_repeat('x', 200), $json);
        $this->assertStringContainsString('RuntimeException', $json);
    }
}
