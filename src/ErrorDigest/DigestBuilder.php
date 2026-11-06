<?php

declare(strict_types=1);

namespace IntrAgile\Observability\ErrorDigest;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Groups error reports and renders them as a Slack Block Kit message.
 */
final class DigestBuilder
{
    private const MESSAGE_LIMIT = 180;

    private const HEADER_LIMIT = 150;

    private const LABEL_LIMIT = 75;

    /** Slack allows at most 25 elements in one actions block. */
    private const MAX_BUTTONS = 25;

    /** Keeps the message well below Slack's limit of 50 blocks. */
    private const MAX_GROUPS = 45;

    /**
     * Groups rows by fingerprint, most frequent first, keeping at most $maxGroups groups.
     *
     * @param  Collection<int, ErrorReport>  $rows
     * @return list<array{type: string, message: string, count: int, last_24h: int}>
     */
    public function build(Collection $rows, int $maxGroups): array
    {
        $limit = min(max(1, $maxGroups), self::MAX_GROUPS);

        /** @var array<string, array{type: string, message: string, count: int}> $groups */
        $groups = [];

        foreach ($rows as $row) {
            if (isset($groups[$row->fingerprint])) {
                $groups[$row->fingerprint]['count']++;

                continue;
            }

            $groups[$row->fingerprint] = [
                'type' => $row->exception_type,
                'message' => $row->message,
                'count' => 1,
            ];
        }

        uasort($groups, static fn (array $a, array $b): int => [$b['count'], $a['type'], $a['message']]
            <=> [$a['count'], $b['type'], $b['message']]);

        $shown = array_slice($groups, 0, $limit, true);
        $recent = $this->recentCounts(array_map('strval', array_keys($shown)));

        $result = [];
        foreach ($shown as $fingerprint => $group) {
            $result[] = $group + ['last_24h' => $recent[(string) $fingerprint] ?? 0];
        }

        return $result;
    }

    /**
     * Number of distinct error groups in the rows, before any limit is applied.
     *
     * @param  Collection<int, ErrorReport>  $rows
     */
    public function groupCount(Collection $rows): int
    {
        return $rows->pluck('fingerprint')->unique()->count();
    }

    /**
     * Renders the Slack Block Kit payload.
     *
     * @param  list<array{type: string, message: string, count: int, last_24h: int}>  $groups
     * @param  array<array-key, mixed>  $links  label => URL; only http(s) URLs become buttons
     * @param  int  $hiddenGroups  groups left out by the limit, shown as a "+N" line
     * @return array{text: string, blocks: list<array<string, mixed>>}
     */
    public function payload(array $groups, string $instance, array $links, int $hiddenGroups = 0): array
    {
        $hiddenGroups = max(0, $hiddenGroups);
        $types = count($groups) + $hiddenGroups;
        $summary = $instance.': '.$types.' error type(s)';

        $blocks = [[
            'type' => 'header',
            'text' => [
                'type' => 'plain_text',
                'text' => Str::limit(':rotating_light: '.$summary, self::HEADER_LIMIT),
                'emoji' => true,
            ],
        ]];

        foreach ($groups as $group) {
            $blocks[] = [
                'type' => 'section',
                'text' => ['type' => 'mrkdwn', 'text' => $this->line($group)],
            ];
        }

        if ($hiddenGroups > 0) {
            $blocks[] = [
                'type' => 'context',
                'elements' => [['type' => 'mrkdwn', 'text' => '_+'.$hiddenGroups.' further error type(s)_']],
            ];
        }

        $buttons = $this->buttons($links);
        if ($buttons !== []) {
            $blocks[] = ['type' => 'actions', 'elements' => $buttons];
        }

        return ['text' => $summary, 'blocks' => $blocks];
    }

    /**
     * Counts reports per fingerprint created in the last 24 hours, in one query.
     *
     * @param  list<string>  $fingerprints
     * @return array<string, int>
     */
    private function recentCounts(array $fingerprints): array
    {
        if ($fingerprints === []) {
            return [];
        }

        $counts = [];
        $rows = ErrorReport::query()
            ->selectRaw('fingerprint, count(*) as aggregate')
            ->whereIn('fingerprint', $fingerprints)
            ->where('created_at', '>=', Carbon::now()->subDay())
            ->groupBy('fingerprint')
            ->toBase()
            ->get();

        foreach ($rows as $row) {
            if (isset($row->fingerprint, $row->aggregate) && is_scalar($row->fingerprint) && is_numeric($row->aggregate)) {
                $counts[(string) $row->fingerprint] = (int) $row->aggregate;
            }
        }

        return $counts;
    }

    /**
     * A single group line, e.g. "• *3×* (24h: 17) *message* _(QueryException)_".
     *
     * @param  array{type: string, message: string, count: int, last_24h: int}  $group
     */
    private function line(array $group): string
    {
        $message = trim((string) preg_replace('/\s+/u', ' ', $group['message']));
        $message = $message !== '' ? Str::limit($message, self::MESSAGE_LIMIT) : 'no message';

        return '• *'.$group['count'].'×* (24h: '.$group['last_24h'].') *'.$this->escape($message).'*'
            .' _('.$this->escape(class_basename($group['type'])).')_';
    }

    /**
     * @param  array<array-key, mixed>  $links
     * @return list<array{type: string, text: array{type: string, text: string}, url: string}>
     */
    private function buttons(array $links): array
    {
        $buttons = [];

        foreach ($links as $label => $url) {
            if (! is_string($label) || trim($label) === '' || ! is_string($url) || preg_match('#^https?://\S+$#i', $url) !== 1) {
                continue;
            }

            $buttons[] = [
                'type' => 'button',
                'text' => ['type' => 'plain_text', 'text' => Str::limit($label, self::LABEL_LIMIT)],
                'url' => $url,
            ];

            if (count($buttons) === self::MAX_BUTTONS) {
                break;
            }
        }

        return $buttons;
    }

    private function escape(string $text): string
    {
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text);
    }
}
