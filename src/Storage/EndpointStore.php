<?php

/**
 * Reads and writes Junction's endpoints table.
 *
 * @package   KraftySprouts\Junction
 * @author    Krafty Sprouts Media, LLC
 * @copyright 2026 Krafty Sprouts Media, LLC
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare(strict_types=1);

namespace KraftySprouts\Junction\Storage;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use KraftySprouts\Junction\Endpoints\Endpoint;
use KraftySprouts\Junction\Endpoints\SecretBox;
use KraftySprouts\Junction\Support\Ids;

/**
 * The one place Junction's endpoints are read and written, through Laravel's
 * query builder on Junction's own table, never Teme's.
 *
 * @since 1.0.0
 */
final class EndpointStore
{
    /** The table. @since 1.0.0 */
    public const string TABLE = 'junction_endpoints';

    /**
     * The endpoint with this ID, or null.
     *
     * @since 1.0.0
     */
    public function find(string $id): ?Endpoint
    {
        $row = DB::table(self::TABLE)->where('id', $id)->first();

        return $row === null ? null : $this->endpoint($row);
    }

    /**
     * Every endpoint that is on and not paused, oldest first.
     *
     * @return list<Endpoint>
     *
     * @since 1.0.0
     */
    public function active(): array
    {
        return array_values(array_map(
            $this->endpoint(...),
            DB::table(self::TABLE)->where('enabled', true)->whereNull('paused_at')->orderBy('created_at')->get()->all(),
        ));
    }

    /**
     * Whether any active endpoint takes this event.
     *
     * @since 1.0.0
     */
    public function anyTakes(string $event): bool
    {
        foreach ($this->active() as $endpoint) {
            if ($endpoint->takes($event)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Stores an endpoint, new when $id is null, and answers its ID. The
     * secret is encrypted; saving clears a pause and the failure count.
     *
     * @param  array{name: string, url: string, enabled: bool, format: string, timeout: int, events: list<string>, other_events: list<string>, types: list<string>, categories: list<string>, tags: list<string>, fields: list<string>, secret: string}  $values
     *
     * @since 1.0.0
     */
    public function save(?string $id, array $values): string
    {
        $now = CarbonImmutable::now();
        $row = [
            'name' => $values['name'],
            'url' => $values['url'],
            'enabled' => $values['enabled'],
            'format' => $values['format'],
            'timeout' => $values['timeout'],
            'events' => json_encode($values['events'], JSON_THROW_ON_ERROR),
            'other_events' => json_encode($values['other_events'], JSON_THROW_ON_ERROR),
            'types' => json_encode($values['types'], JSON_THROW_ON_ERROR),
            'categories' => json_encode($values['categories'], JSON_THROW_ON_ERROR),
            'tags' => json_encode($values['tags'], JSON_THROW_ON_ERROR),
            'fields' => json_encode($values['fields'], JSON_THROW_ON_ERROR),
            'secret' => SecretBox::lock($values['secret']),
            'consecutive_failures' => 0,
            'paused_at' => null,
            'updated_at' => $now,
        ];

        if ($id === null) {
            $id = Ids::make();
            DB::table(self::TABLE)->insert(['id' => $id, 'created_at' => $now, ...$row]);
        } else {
            DB::table(self::TABLE)->where('id', $id)->update($row);
        }

        return $id;
    }

    /**
     * Turns endpoints on or off; turning one on clears its pause.
     *
     * @param  list<string>  $ids
     *
     * @since 1.0.0
     */
    public function switch(array $ids, bool $on): int
    {
        $changes = ['enabled' => $on, 'updated_at' => CarbonImmutable::now()];

        if ($on) {
            $changes += ['paused_at' => null, 'consecutive_failures' => 0];
        }

        return DB::table(self::TABLE)->whereIn('id', $ids)->update($changes);
    }

    /**
     * Copies endpoints, each off, named as a copy, with a new secret.
     *
     * @param  list<string>  $ids
     * @return list<string> the copies' IDs
     *
     * @since 1.0.0
     */
    public function duplicate(array $ids, string $copySuffix): array
    {
        $copies = [];

        foreach ($ids as $id) {
            $endpoint = $this->find($id);

            if ($endpoint === null) {
                continue;
            }

            $copies[] = $this->save(null, [
                'name' => mb_substr($endpoint->name.' '.$copySuffix, 0, 80),
                'url' => $endpoint->url,
                'enabled' => false,
                'format' => $endpoint->format,
                'timeout' => $endpoint->timeout,
                'events' => $endpoint->events,
                'other_events' => $endpoint->otherEvents,
                'types' => $endpoint->types,
                'categories' => $endpoint->categories,
                'tags' => $endpoint->tags,
                'fields' => $endpoint->fields,
                'secret' => SecretBox::generate(),
            ]);
        }

        return $copies;
    }

    /**
     * Deletes endpoints, and their deliveries that still wait.
     *
     * @param  list<string>  $ids
     *
     * @since 1.0.0
     */
    public function delete(array $ids): int
    {
        DB::table(DeliveryStore::TABLE)->whereIn('endpoint_id', $ids)->where('status', DeliveryStore::WAITING)->delete();

        return DB::table(self::TABLE)->whereIn('id', $ids)->delete();
    }

    /**
     * Counts one more failure in a row, and answers the new count.
     *
     * @since 1.0.0
     */
    public function recordFailure(string $id): int
    {
        DB::table(self::TABLE)->where('id', $id)->increment('consecutive_failures');

        return (int) DB::table(self::TABLE)->where('id', $id)->value('consecutive_failures');
    }

    /**
     * Clears the failure count after a delivery got through.
     *
     * @since 1.0.0
     */
    public function recordSuccess(string $id): void
    {
        DB::table(self::TABLE)->where('id', $id)->where('consecutive_failures', '>', 0)->update(['consecutive_failures' => 0]);
    }

    /**
     * Pauses an endpoint; answers false when it was already paused.
     *
     * @since 1.0.0
     */
    public function pause(string $id): bool
    {
        return DB::table(self::TABLE)->where('id', $id)->whereNull('paused_at')->update(['paused_at' => CarbonImmutable::now()]) === 1;
    }

    /**
     * One page of endpoints for the area's table, and how many match.
     *
     * @return array{rows: list<Endpoint>, total: int}
     *
     * @since 1.0.0
     */
    public function page(int $page, int $perPage, string $search, ?string $sort, string $direction): array
    {
        $query = DB::table(self::TABLE);

        if ($search !== '') {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $query->where(fn ($where) => $where->where('name', 'ilike', $like)->orWhere('url', 'ilike', $like));
        }

        $total = (clone $query)->count();
        $column = match ($sort) {
            'failures' => 'consecutive_failures',
            'name' => 'name',
            default => 'created_at',
        };
        $rows = $query->orderBy($column, $direction)->orderBy('id')->forPage($page, $perPage)->get()->all();

        return ['rows' => array_values(array_map($this->endpoint(...), $rows)), 'total' => $total];
    }

    /**
     * How many endpoints are active, and how many are paused.
     *
     * @return array{active: int, paused: int}
     *
     * @since 1.0.0
     */
    public function counts(): array
    {
        return [
            'active' => DB::table(self::TABLE)->where('enabled', true)->whereNull('paused_at')->count(),
            'paused' => DB::table(self::TABLE)->whereNotNull('paused_at')->count(),
        ];
    }

    /**
     * Makes an Endpoint from a row.
     *
     * @since 1.0.0
     */
    private function endpoint(object $row): Endpoint
    {
        $list = fn (string $column): array => array_values(array_map(strval(...), (array) json_decode((string) $row->{$column}, true)));

        return new Endpoint(
            id: (string) $row->id,
            name: (string) $row->name,
            url: (string) $row->url,
            enabled: (bool) $row->enabled,
            format: (string) $row->format,
            timeout: (int) $row->timeout,
            events: $list('events'),
            otherEvents: $list('other_events'),
            types: $list('types'),
            categories: $list('categories'),
            tags: $list('tags'),
            fields: $list('fields'),
            secret: SecretBox::unlock((string) $row->secret),
            failures: (int) $row->consecutive_failures,
            pausedAt: $row->paused_at === null ? null : new DateTimeImmutable((string) $row->paused_at),
        );
    }
}
