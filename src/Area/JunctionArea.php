<?php

/**
 * The data behind Junction's area in the admin: its overview, its endpoints
 * and its delivery log, and their writes.
 *
 * @package   KraftySprouts\Junction
 * @author    Krafty Sprouts Media, LLC
 * @copyright 2026 Krafty Sprouts Media, LLC
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare(strict_types=1);

namespace KraftySprouts\Junction\Area;

use Carbon\CarbonImmutable;
use KraftySprouts\Junction\Endpoints\Endpoint;
use KraftySprouts\Junction\Endpoints\SecretBox;
use KraftySprouts\Junction\Events\EventNames;
use KraftySprouts\Junction\Junction;
use KraftySprouts\Junction\Storage\DeliveryStore;
use KraftySprouts\Junction\Storage\EndpointStore;
use KraftySprouts\Junction\Storage\EventStore;
use Teme\Contracts\Admin\AreaContext;
use Teme\Contracts\Admin\Chart;
use Teme\Contracts\Admin\Figure;
use Teme\Contracts\Admin\PluginArea;
use Teme\Contracts\Admin\Row;
use Teme\Contracts\Admin\RowPage;
use Teme\Contracts\Admin\Saved;
use Teme\Contracts\Admin\TableQuery;
use Teme\Contracts\Content\WriteRefused;
use Teme\Contracts\Jobs\Jobs;
use Teme\Contracts\Jobs\QueueOptions;
use Teme\Contracts\Lists\Cell;
use Teme\Contracts\Settings\SettingsReader;

/**
 * Core draws every screen; this class hands it rows, figures and values,
 * and stores what core has checked. An endpoint's address must name a host
 * already agreed to under Settings › Junction. A delivery try is read-only:
 * duplicating one sends its delivery again.
 *
 * @since 1.0.0
 */
final readonly class JunctionArea implements PluginArea
{
    /** The endpoint record's id. @since 1.0.0 */
    public const string ENDPOINT = 'endpoint';

    /** The delivery try record's id. @since 1.0.0 */
    public const string ATTEMPT = 'attempt';

    /** @since 1.0.0 */
    public function __construct(
        private EndpointStore $endpoints,
        private DeliveryStore $deliveries,
        private EventStore $events,
        private SettingsReader $settings,
        private Jobs $jobs,
    ) {}

    /**
     * The overview's figures.
     *
     * @since 1.0.0
     */
    public function figures(string $page, string $block, AreaContext $context): array
    {
        $deliveries = $this->deliveries->counts();
        $endpoints = $this->endpoints->counts();

        return [
            'delivered' => Figure::number($deliveries['delivered']),
            'failed' => Figure::number($deliveries['failed']),
            'waiting' => Figure::number($deliveries['waiting'] + $this->events->count()),
            'endpoints' => Figure::number($endpoints['active']),
            'paused' => Figure::number($endpoints['paused']),
        ];
    }

    /**
     * Junction declares no chart in 1.0.0 (ROADMAP.md), so core never asks.
     *
     * @since 1.0.0
     */
    public function chart(string $page, string $block, AreaContext $context): Chart
    {
        return new Chart([], []);
    }

    /**
     * A page of endpoints, or of delivery tries.
     *
     * @since 1.0.0
     */
    public function rows(string $record, TableQuery $query, AreaContext $context): RowPage
    {
        if ($record === self::ENDPOINT) {
            $page = $this->endpoints->page($query->page, $query->perPage, $query->search, $query->sort, $query->direction);

            return new RowPage(array_map($this->endpointRow(...), $page['rows']), $page['total']);
        }

        $page = $this->deliveries->attemptsPage($query->page, $query->perPage, $query->search, $query->sort === 'time' ? $query->direction : 'desc');

        return new RowPage(array_map(fn (array $attempt): Row => $this->attemptRow($attempt, $context), $page['rows']), $page['total']);
    }

    /**
     * An endpoint's or a try's values for its form.
     *
     * @since 1.0.0
     */
    public function values(string $record, string $id, AreaContext $context): ?array
    {
        if ($record === self::ENDPOINT) {
            $endpoint = $this->endpoints->find($id);

            return $endpoint === null ? null : [
                'name' => $endpoint->name,
                'url' => $endpoint->url,
                'enabled' => $endpoint->enabled,
                'timeout' => $endpoint->timeout,
                'events' => $endpoint->events,
                'types' => $endpoint->types,
                'secret' => $endpoint->secret,
                'send_test' => false,
            ];
        }

        $attempt = $this->deliveries->attempt($id);

        return $attempt === null ? null : [
            'event' => $attempt['event'],
            'endpoint' => $attempt['endpoint_name'],
            'delivery' => $attempt['delivery_id'],
            'result' => $this->result($attempt, $context),
            'sent' => $attempt['payload'] === null
                ? __('The delivery was removed from the log.')
                : (string) json_encode($attempt['payload'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'reply' => $attempt['reply'],
        ];
    }

    /**
     * Stores an endpoint; a try can't be changed.
     *
     * @since 1.0.0
     */
    public function save(string $record, ?string $id, array $values, AreaContext $context): Saved
    {
        if ($record !== self::ENDPOINT) {
            throw new WriteRefused(__('A delivery try can’t be changed. Duplicate it to send the delivery again.'));
        }

        $current = $id === null ? null : $this->endpoints->find($id);

        if ($id !== null && $current === null) {
            throw new WriteRefused(__('That endpoint no longer exists.'));
        }

        $checked = $this->checked($values, $current);
        $saved = $this->endpoints->save($id, $checked);

        if (($values['send_test'] ?? false) === true) {
            $this->jobs->queue('send-test', ['endpoint' => $saved], __('Junction: send a test event to :name', ['name' => $checked['name']]));
        }

        return new Saved($saved);
    }

    /**
     * Runs a row action on endpoints, or on tries.
     *
     * @since 1.0.0
     */
    public function act(string $record, string $action, array $ids, AreaContext $context): Saved
    {
        $ids = array_slice($ids, 0, 100);

        if ($ids === []) {
            throw new WriteRefused(__('Choose at least one row.'));
        }

        if ($record === self::ENDPOINT) {
            $changed = match ($action) {
                'enable' => $this->endpoints->switch($ids, true),
                'disable' => $this->endpoints->switch($ids, false),
                'duplicate' => count($this->endpoints->duplicate($ids, __('(copy)'))),
                'delete' => $this->endpoints->delete($ids),
                default => throw new WriteRefused(__('Junction can’t do that to an endpoint.')),
            };

            return new Saved($ids[0], $changed > 0);
        }

        $changed = match ($action) {
            'duplicate' => $this->resend($ids),
            'delete' => $this->deliveries->deleteAttempts($ids),
            default => throw new WriteRefused(__('Junction can’t do that to a delivery try.')),
        };

        return new Saved($ids[0], $changed > 0);
    }

    /**
     * Sends again the deliveries behind these tries, and queues a run.
     *
     * @param  list<string>  $attemptIds
     *
     * @since 1.0.0
     */
    private function resend(array $attemptIds): int
    {
        $new = $this->deliveries->resend($attemptIds);

        if ($new !== []) {
            $this->jobs->queue('deliver', [], null, new QueueOptions(uniqueKey: 'resend'));
        }

        return count($new);
    }

    /**
     * Checks and tidies an endpoint's form, beyond what core checked.
     *
     * @param  array<string, mixed>  $values
     * @return array{name: string, url: string, enabled: bool, format: string, timeout: int, events: list<string>, other_events: list<string>, types: list<string>, categories: list<string>, tags: list<string>, fields: list<string>, secret: string}
     *
     * @throws WriteRefused with one plain sentence
     *
     * @since 1.0.0
     */
    private function checked(array $values, ?Endpoint $current): array
    {
        $name = trim((string) ($values['name'] ?? $current->name ?? ''));
        $url = trim((string) ($values['url'] ?? $current->url ?? ''));

        if ($name === '') {
            throw new WriteRefused(__('Give the endpoint a name.'));
        }

        if (strtolower((string) parse_url($url, PHP_URL_SCHEME)) !== 'https') {
            throw new WriteRefused(__('Junction sends only to https addresses.'));
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if ($host === '' || ! in_array($host, $this->agreedHosts(), true)) {
            throw new WriteRefused(__('List :host under Settings › Junction › Endpoint addresses and agree to it first.', ['host' => $host]));
        }

        $events = array_values(array_intersect(array_map(strval(...), (array) ($values['events'] ?? $current->events ?? [])), EventNames::builtIn()));
        $other = $current->otherEvents ?? [];

        if ($events === []) {
            throw new WriteRefused(__('Choose at least one event.'));
        }

        $secret = trim((string) ($values['secret'] ?? $current->secret ?? ''));

        return [
            'name' => mb_substr($name, 0, 80),
            'url' => $url,
            'enabled' => (bool) ($values['enabled'] ?? $current->enabled ?? true),
            // 1.0.0 sends JSON only; chat apps' formats are planned for 1.1 (ROADMAP.md).
            'format' => 'json',
            'timeout' => max(1, min(10, (int) ($values['timeout'] ?? $current->timeout ?? 10))),
            'events' => $events,
            // Other plugins' events arrive in 1.2 (ROADMAP.md); a row keeps what it has.
            'other_events' => $other,
            'types' => array_values(array_map(strval(...), (array) ($values['types'] ?? $current->types ?? []))),
            // The form offers no field picker or category and tag filters until 1.1 (ROADMAP.md); the engine keeps them.
            'categories' => $current->categories ?? [],
            'tags' => $current->tags ?? [],
            'fields' => $current->fields ?? [],
            'secret' => $secret === '' ? SecretBox::generate() : mb_substr($secret, 0, 128),
        ];
    }

    /**
     * The hosts the administrator agreed to: those named in Junction's
     * endpoint addresses setting. The manifest's placeholder host is never
     * one of them.
     *
     * @return list<string>
     *
     * @since 1.0.0
     */
    private function agreedHosts(): array
    {
        $hosts = [];

        foreach ($this->settings->stringList(Junction::SETTINGS, 'endpoint_urls') as $url) {
            $host = strtolower((string) parse_url(trim($url), PHP_URL_HOST));

            if ($host !== '') {
                $hosts[] = $host;
            }
        }

        return array_values(array_unique($hosts));
    }

    /**
     * An endpoint's row.
     *
     * @since 1.0.0
     */
    private function endpointRow(Endpoint $endpoint): Row
    {
        $state = match (true) {
            $endpoint->pausedAt !== null => Cell::badge(__('Paused'), 'warn'),
            $endpoint->enabled => Cell::badge(__('On'), 'ok'),
            default => Cell::badge(__('Off'), 'neutral'),
        };

        return new Row($endpoint->id, [
            'name' => Cell::text($endpoint->name),
            'host' => Cell::text($endpoint->host()),
            'events' => Cell::number(count($endpoint->events) + count($endpoint->otherEvents)),
            'state' => $state,
            'failures' => Cell::number($endpoint->failures),
        ], $endpoint->enabled && $endpoint->pausedAt === null ? ['enable'] : ($endpoint->enabled ? [] : ['disable']));
    }

    /**
     * A try's row.
     *
     * @param  array{id: int, delivery_id: string, endpoint_name: string, event: string, ok: bool, status_code: int|null, error: string|null, reply: string, duration_ms: int, created_at: CarbonImmutable}  $attempt
     *
     * @since 1.0.0
     */
    private function attemptRow(array $attempt, AreaContext $context): Row
    {
        return new Row((string) $attempt['id'], [
            'time' => Cell::text($attempt['created_at']->setTimezone($context->timezone)->format('Y-m-d H:i:s')),
            'event' => Cell::text($attempt['event']),
            'endpoint' => Cell::text($attempt['endpoint_name']),
            'result' => $attempt['ok'] ? Cell::badge(__('Delivered'), 'ok') : Cell::badge(__('Failed'), 'danger'),
            'code' => Cell::number($attempt['status_code'] ?? 0),
            'reply' => Cell::text($attempt['error'] ?? $attempt['reply']),
        ]);
    }

    /**
     * A try's result in words.
     *
     * @param  array{ok: bool, status_code: int|null, error: string|null, duration_ms: int, created_at: CarbonImmutable}  $attempt
     *
     * @since 1.0.0
     */
    private function result(array $attempt, AreaContext $context): string
    {
        $when = $attempt['created_at']->setTimezone($context->timezone)->format('Y-m-d H:i:s');

        if ($attempt['ok']) {
            return __('Delivered at :when: status :code in :ms ms.', ['when' => $when, 'code' => (string) $attempt['status_code'], 'ms' => $attempt['duration_ms']]);
        }

        return $attempt['status_code'] === null
            ? __('Failed at :when: :error', ['when' => $when, 'error' => (string) $attempt['error']])
            : __('Failed at :when: status :code in :ms ms.', ['when' => $when, 'code' => (string) $attempt['status_code'], 'ms' => $attempt['duration_ms']]);
    }
}
