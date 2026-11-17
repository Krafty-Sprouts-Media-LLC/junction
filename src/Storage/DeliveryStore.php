<?php

/**
 * Reads and writes Junction's deliveries and their tries.
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
 * The delivery log: one delivery for each event and endpoint, waiting,
 * delivered or failed, and one try for each call Junction made.
 *
 * @since 1.0.0
 */
final class DeliveryStore
{
    /** The deliveries table. @since 1.0.0 */
    public const string TABLE = 'junction_deliveries';

    /** The tries table. @since 1.0.0 */
    public const string ATTEMPTS = 'junction_attempts';

    /** @since 1.0.0 */
    public const string WAITING = 'waiting';

    /** @since 1.0.0 */
    public const string DELIVERED = 'delivered';

    /** @since 1.0.0 */
    public const string FAILED = 'failed';

    /**
     * Adds a delivery, due now, and answers its ID.
     *
     * @param  array<string, mixed>  $payload  exactly what will be sent
     *
     * @since 1.0.0
     */
    public function create(string $endpointId, string $event, string $eventId, array $payload): string
    {
        $id = Ids::make();
        $now = CarbonImmutable::now();

        DB::table(self::TABLE)->insert([
            'id' => $id,
            'endpoint_id' => $endpointId,
            'event' => $event,
            'event_id' => $eventId,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'status' => self::WAITING,
            'attempts' => 0,
            'next_attempt_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $id;
    }

    /**
     * The delivery with this ID, or null.
     *
     * @return array{id: string, endpoint_id: string, event: string, event_id: string, payload: array<string, mixed>, status: string, attempts: int}|null
     *
     * @since 1.0.0
     */
    public function find(string $id): ?array
    {
        $row = DB::table(self::TABLE)->where('id', $id)->first();

        return $row === null ? null : [
            'id' => (string) $row->id,
            'endpoint_id' => (string) $row->endpoint_id,
            'event' => (string) $row->event,
            'event_id' => (string) $row->event_id,
            'payload' => (array) json_decode((string) $row->payload, true),
            'status' => (string) $row->status,
            'attempts' => (int) $row->attempts,
        ];
    }

    /**
     * IDs of waiting deliveries now due, to active endpoints, oldest first.
     *
     * @return list<string>
     *
     * @since 1.0.0
     */
    public function due(int $limit): array
    {
        return array_values(array_map(strval(...), DB::table(self::TABLE.' as d')
            ->join(EndpointStore::TABLE.' as e', 'e.id', '=', 'd.endpoint_id')
            ->where('d.status', self::WAITING)
            ->where('d.next_attempt_at', '<=', CarbonImmutable::now())
            ->where('e.enabled', true)
            ->whereNull('e.paused_at')
            ->orderBy('d.next_attempt_at')
            ->orderBy('d.id')
            ->limit($limit)
            ->pluck('d.id')
            ->all()));
    }

    /**
     * Marks a delivery delivered after this many tries.
     *
     * @since 1.0.0
     */
    public function delivered(string $id, int $attempts): void
    {
        $now = CarbonImmutable::now();
        DB::table(self::TABLE)->where('id', $id)->update(['status' => self::DELIVERED, 'attempts' => $attempts, 'next_attempt_at' => null, 'delivered_at' => $now, 'updated_at' => $now]);
    }

    /**
     * Leaves a delivery waiting for another try, no sooner than $at.
     *
     * @since 1.0.0
     */
    public function retryAt(string $id, int $attempts, CarbonImmutable $at): void
    {
        DB::table(self::TABLE)->where('id', $id)->update(['attempts' => $attempts, 'next_attempt_at' => $at, 'updated_at' => CarbonImmutable::now()]);
    }

    /**
     * Marks a delivery failed for good after this many tries.
     *
     * @since 1.0.0
     */
    public function failed(string $id, int $attempts): void
    {
        DB::table(self::TABLE)->where('id', $id)->update(['status' => self::FAILED, 'attempts' => $attempts, 'next_attempt_at' => null, 'updated_at' => CarbonImmutable::now()]);
    }

    /**
     * Records one try of a delivery.
     *
     * @since 1.0.0
     */
    public function recordAttempt(string $deliveryId, string $endpointId, string $endpointName, string $event, bool $ok, ?int $statusCode, ?string $error, string $reply, int $durationMs): void
    {
        DB::table(self::ATTEMPTS)->insert([
            'delivery_id' => $deliveryId,
            'endpoint_id' => $endpointId,
            'endpoint_name' => mb_substr($endpointName, 0, 80),
            'event' => $event,
            'ok' => $ok,
            'status_code' => $statusCode,
            'error' => $error === null ? null : mb_substr($error, 0, 300),
            'reply' => mb_substr($reply, 0, 500),
            'duration_ms' => max(0, $durationMs),
            'created_at' => CarbonImmutable::now(),
        ]);
    }

    /**
     * Sends the deliveries behind these tries again: each as a new delivery
     * with a new ID, the same event ID and the same payload. Answers the new
     * deliveries' IDs.
     *
     * @param  list<string>  $attemptIds
     * @return list<string>
     *
     * @since 1.0.0
     */
    public function resend(array $attemptIds): array
    {
        $deliveryIds = DB::table(self::ATTEMPTS)->whereIn('id', array_map(intval(...), $attemptIds))->distinct()->pluck('delivery_id')->all();
        $new = [];

        foreach ($deliveryIds as $deliveryId) {
            $delivery = $this->find((string) $deliveryId);

            if ($delivery !== null) {
                $new[] = $this->create($delivery['endpoint_id'], $delivery['event'], $delivery['event_id'], $delivery['payload']);
            }
        }

        return $new;
    }

    /**
     * Deletes tries by ID.
     *
     * @param  list<string>  $attemptIds
     *
     * @since 1.0.0
     */
    public function deleteAttempts(array $attemptIds): int
    {
        return DB::table(self::ATTEMPTS)->whereIn('id', array_map(intval(...), $attemptIds))->delete();
    }

    /**
     * One try with its delivery's payload, for the area's form.
     *
     * @return array{id: int, delivery_id: string, endpoint_name: string, event: string, ok: bool, status_code: int|null, error: string|null, reply: string, duration_ms: int, created_at: CarbonImmutable, payload: array<string, mixed>|null}|null
     *
     * @since 1.0.0
     */
    public function attempt(string $id): ?array
    {
        if (preg_match('/^\d{1,18}$/D', $id) !== 1) {
            return null;
        }

        $row = DB::table(self::ATTEMPTS)->where('id', (int) $id)->first();

        if ($row === null) {
            return null;
        }

        $attempt = $this->attemptRow($row);
        $attempt['payload'] = $this->find($attempt['delivery_id'])['payload'] ?? null;

        return $attempt;
    }

    /**
     * One page of tries for the area's log, newest first unless asked.
     *
     * @return array{rows: list<array{id: int, delivery_id: string, endpoint_name: string, event: string, ok: bool, status_code: int|null, error: string|null, reply: string, duration_ms: int, created_at: CarbonImmutable}>, total: int}
     *
     * @since 1.0.0
     */
    public function attemptsPage(int $page, int $perPage, string $search, string $direction): array
    {
        $query = DB::table(self::ATTEMPTS);

        if ($search !== '') {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $query->where(fn ($where) => $where->where('event', 'ilike', $like)->orWhere('endpoint_name', 'ilike', $like)->orWhere('delivery_id', 'ilike', $like));
        }

        $total = (clone $query)->count();
        $rows = $query->orderBy('id', $direction)->forPage($page, $perPage)->get()->all();

        return ['rows' => array_values(array_map($this->attemptRow(...), $rows)), 'total' => $total];
    }

    /**
     * Counts for the overview: delivered and failed in the last day, and waiting.
     *
     * @return array{delivered: int, failed: int, waiting: int}
     *
     * @since 1.0.0
     */
    public function counts(): array
    {
        $since = CarbonImmutable::now()->subDay();

        return [
            'delivered' => DB::table(self::TABLE)->where('status', self::DELIVERED)->where('delivered_at', '>=', $since)->count(),
            'failed' => DB::table(self::TABLE)->where('status', self::FAILED)->where('updated_at', '>=', $since)->count(),
            'waiting' => DB::table(self::TABLE)->where('status', self::WAITING)->count(),
        ];
    }

    /**
     * Delivered and failed deliveries for each of the last $days days, in
     * the time zone given, oldest first.
     *
     * @return array{labels: list<string>, delivered: list<int>, failed: list<int>}
     *
     * @since 1.0.0
     */
    public function daily(int $days, string $timezone): array
    {
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $start = $today->subDays($days - 1);
        $labels = [];
        $delivered = [];
        $failed = [];

        $rows = DB::table(self::TABLE)
            ->whereIn('status', [self::DELIVERED, self::FAILED])
            ->where('updated_at', '>=', $start->utc())
            ->get(['status', 'updated_at'])
            ->all();

        $tally = [];

        foreach ($rows as $row) {
            $day = CarbonImmutable::parse((string) $row->updated_at)->setTimezone($timezone)->format('Y-m-d');
            $tally[$day][(string) $row->status] = ($tally[$day][(string) $row->status] ?? 0) + 1;
        }

        for ($day = $start; $day <= $today; $day = $day->addDay()) {
            $key = $day->format('Y-m-d');
            $labels[] = $key;
            $delivered[] = $tally[$key][self::DELIVERED] ?? 0;
            $failed[] = $tally[$key][self::FAILED] ?? 0;
        }

        return ['labels' => $labels, 'delivered' => $delivered, 'failed' => $failed];
    }

    /**
     * Removes tries, and finished deliveries, older than $days days.
     *
     * @return array{deliveries: int, attempts: int}
     *
     * @since 1.0.0
     */
    public function prune(int $days): array
    {
        $before = CarbonImmutable::now()->subDays($days);

        return [
            'attempts' => DB::table(self::ATTEMPTS)->where('created_at', '<', $before)->delete(),
            'deliveries' => DB::table(self::TABLE)->whereIn('status', [self::DELIVERED, self::FAILED])->where('updated_at', '<', $before)->delete(),
        ];
    }

    /**
     * Makes a try's array from a row.
     *
     * @return array{id: int, delivery_id: string, endpoint_name: string, event: string, ok: bool, status_code: int|null, error: string|null, reply: string, duration_ms: int, created_at: CarbonImmutable}
     *
     * @since 1.0.0
     */
    private function attemptRow(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'delivery_id' => (string) $row->delivery_id,
            'endpoint_name' => (string) $row->endpoint_name,
            'event' => (string) $row->event,
            'ok' => (bool) $row->ok,
            'status_code' => $row->status_code === null ? null : (int) $row->status_code,
            'error' => $row->error === null ? null : (string) $row->error,
            'reply' => (string) $row->reply,
            'duration_ms' => (int) $row->duration_ms,
            'created_at' => CarbonImmutable::parse((string) $row->created_at)->utc(),
        ];
    }
}
