<?php

/**
 * Small stand-ins for the doors Junction's area uses, so its tests can call
 * the area as core would without reaching into Teme.
 *
 * @package   KraftySprouts\Junction
 * @author    Krafty Sprouts Media, LLC
 * @copyright 2026 Krafty Sprouts Media, LLC
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare(strict_types=1);

namespace KraftySprouts\Junction\Tests\Support;

use Teme\Contracts\Jobs\Jobs;
use Teme\Contracts\Jobs\QueueOptions;
use Teme\Contracts\Settings\SettingsReader;
use Teme\Contracts\Site\SiteInfo;

/**
 * Builds the fakes.
 *
 * @since 1.0.0
 */
final class FakeDoors
{
    /**
     * A settings reader answering Junction's group from these values.
     *
     * @param  array<string, mixed>  $values
     *
     * @since 1.0.0
     */
    public static function settings(array $values): SettingsReader
    {
        return new class($values) implements SettingsReader
        {
            /** @param array<string, mixed> $values */
            public function __construct(private array $values) {}

            public function group(string $group): array
            {
                return $this->values;
            }

            public function get(string $group, string $key): mixed
            {
                return $this->values[$key] ?? null;
            }

            public function string(string $group, string $key): string
            {
                return (string) ($this->values[$key] ?? '');
            }

            public function int(string $group, string $key): int
            {
                return (int) ($this->values[$key] ?? 0);
            }

            public function bool(string $group, string $key): bool
            {
                return (bool) ($this->values[$key] ?? false);
            }

            public function stringList(string $group, string $key): array
            {
                return array_values(array_map(strval(...), (array) ($this->values[$key] ?? [])));
            }
        };
    }

    /**
     * Site facts for a site at https://site.example.
     *
     * @since 1.0.0
     */
    public static function site(): SiteInfo
    {
        return new class implements SiteInfo
        {
            public function address(): string
            {
                return 'https://site.example';
            }

            public function name(): string
            {
                return 'Example site';
            }

            public function language(): string
            {
                return 'en-US';
            }

            public function home(): string
            {
                return 'https://site.example/';
            }

            public function feeds(): array
            {
                return ['https://site.example/feed'];
            }
        };
    }

    /**
     * A jobs door that records what was queued.
     *
     * @since 1.0.0
     */
    public static function jobs(): RecordingJobs
    {
        return new RecordingJobs;
    }
}

/**
 * A Jobs door that keeps each queue() call instead of running it.
 *
 * @since 1.0.0
 */
final class RecordingJobs implements Jobs
{
    /** @var list<array{key: string, payload: array<string, mixed>}> */
    public array $queued = [];

    /** @since 1.0.0 */
    public function queue(string $key, array $payload = [], ?string $label = null, ?QueueOptions $options = null): string
    {
        $this->queued[] = ['key' => $key, 'payload' => $payload];

        return 'job-'.count($this->queued);
    }
}
