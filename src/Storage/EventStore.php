<?php

/**
 * Reads and writes Junction's table of events waiting to be sent.
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
use Illuminate\Support\Facades\DB;
use KraftySprouts\Junction\Support\Ids;

/**
 * The outbox: a hook listener writes one small row here and returns at once,
 * and the deliver job turns each row into deliveries, then removes it.
 *
 * @since 1.0.0
 */
final class EventStore
{
    /** The table. @since 1.0.0 */
    public const string TABLE = 'junction_events';

    /**
     * Records an event that happened now, and answers its ID.
     *
     * @param  array<string, mixed>  $data
     *
     * @since 1.0.0
     */
    public function record(string $event, array $data): string
    {
        $id = Ids::make();

        DB::table(self::TABLE)->insert([
            'event_id' => $id,
            'event' => $event,
            'data' => json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'occurred_at' => CarbonImmutable::now(),
        ]);

        return $id;
    }

    /**
     * The oldest waiting events, at most $limit.
     *
     * @return list<array{row: int, id: string, event: string, data: array<string, mixed>, occurred_at: CarbonImmutable}>
     *
     * @since 1.0.0
     */
    public function oldest(int $limit): array
    {
        $rows = DB::table(self::TABLE)->orderBy('id')->limit($limit)->get()->all();

        return array_values(array_map(fn (object $row): array => [
            'row' => (int) $row->id,
            'id' => (string) $row->event_id,
            'event' => (string) $row->event,
            'data' => (array) json_decode((string) $row->data, true),
            'occurred_at' => CarbonImmutable::parse((string) $row->occurred_at)->utc(),
        ], $rows));
    }

    /**
     * Removes an event once its deliveries are made.
     *
     * @since 1.0.0
     */
    public function forget(int $row): void
    {
        DB::table(self::TABLE)->where('id', $row)->delete();
    }

    /**
     * How many events wait.
     *
     * @since 1.0.0
     */
    public function count(): int
    {
        return DB::table(self::TABLE)->count();
    }
}
