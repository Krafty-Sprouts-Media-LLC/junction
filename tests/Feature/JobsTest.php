<?php

/**
 * Junction's schedules, its test event and its log pruning.
 *
 * @package   KraftySprouts\Junction
 * @author    Krafty Sprouts Media, LLC
 * @copyright 2026 Krafty Sprouts Media, LLC
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use KraftySprouts\Junction\Storage\DeliveryStore;
use KraftySprouts\Junction\Tests\Support\Junctions;
use Teme\Testing\TemeTestCase;

uses(TemeTestCase::class);

beforeEach(function (): void {
    $this->junction = $this->plugin(junctionFolder())->enable();
    $this->fake = $this->outbound();
});

it('runs the deliver job every minute and the prune job every day', function (): void {
    expect($this->junction->runSchedules())->toBe(2)
        ->and($this->junction->runSchedules(now()->addMinute()))->toBe(1)
        ->and($this->junction->runSchedules(now()->addDay()->addMinute()))->toBe(2);
});

it('sends a scheduled publish to the endpoint as the administrator who enabled Junction', function (): void {
    Junctions::endpoint();
    $this->publish('By the schedule');

    $this->junction->runSchedules();

    expect($this->fake->sent(Junctions::HOST))->toHaveCount(1);
});

it('sends a signed test event to each endpoint that is on', function (): void {
    Junctions::endpoint(['name' => 'One']);
    Junctions::endpoint(['name' => 'Two']);
    Junctions::endpoint(['name' => 'Off', 'enabled' => false]);

    expect($this->junction->runJob('send-test')->state)->toBe('finished');

    $sent = $this->fake->sent(Junctions::HOST);
    expect($sent)->toHaveCount(2)
        ->and($sent[0]->json()['event'])->toBe('junction.test')
        ->and($sent[0]->headers['X-Junction-Signature'][0])->toStartWith('sha256=')
        ->and($sent[1]->json()['data']['message'])->toContain('this endpoint works');
});

it('sends a test to one endpoint when the job names it', function (): void {
    Junctions::endpoint(['name' => 'One']);
    $two = Junctions::endpoint(['name' => 'Two']);

    $this->junction->runJob('send-test', ['endpoint' => $two->id]);

    expect($this->fake->sent(Junctions::HOST))->toHaveCount(1)
        ->and($this->fake->sent(Junctions::HOST)[0]->json()['data']['endpoint'])->toBe('Two');
});

it('removes log entries older than the period kept, but never what still waits', function (): void {
    $this->junction->changeSettings(['retention_days' => 7]);
    $endpoint = Junctions::endpoint();
    $store = new DeliveryStore;
    $old = $store->create($endpoint->id, 'junction.test', 'event00000000000000000003', []);
    $store->recordAttempt($old, $endpoint->id, $endpoint->name, 'junction.test', true, 200, null, '', 5);
    $store->delivered($old, 1);
    $waiting = $store->create($endpoint->id, 'junction.test', 'event00000000000000000004', []);

    $this->travel(8)->days();
    $fresh = $store->create($endpoint->id, 'junction.test', 'event00000000000000000005', []);
    $store->delivered($fresh, 1);
    DB::table(DeliveryStore::TABLE)->where('id', $waiting)->update(['next_attempt_at' => now()->addYear()]);

    $this->junction->runJob('prune');

    expect(DB::table(DeliveryStore::TABLE)->pluck('id')->sort()->values()->all())->toBe(collect([$waiting, $fresh])->sort()->values()->all())
        ->and(DB::table(DeliveryStore::ATTEMPTS)->count())->toBe(0);
});
