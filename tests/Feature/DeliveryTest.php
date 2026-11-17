<?php

/**
 * From a hook to a signed delivery: what is recorded, what is sent, and
 * how it is signed.
 *
 * @package   KraftySprouts\Junction
 * @author    Krafty Sprouts Media, LLC
 * @copyright 2026 Krafty Sprouts Media, LLC
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use KraftySprouts\Junction\Delivery\Signer;
use KraftySprouts\Junction\Storage\DeliveryStore;
use KraftySprouts\Junction\Storage\EventStore;
use KraftySprouts\Junction\Tests\Support\Junctions;
use Teme\Testing\SentRequest;
use Teme\Testing\TemeTestCase;

uses(TemeTestCase::class);

beforeEach(function (): void {
    $this->junction = Junctions::agree($this->plugin(junctionFolder())->enable());
    $this->fake = $this->outbound();
});

it('records an event an endpoint takes, and sends nothing while the page loads', function (): void {
    Junctions::endpoint();

    $this->publish('Hello, Junction');

    expect((new EventStore)->count())->toBe(1);
    $this->fake->assertNothingSent();
});

it('records nothing when no endpoint takes the event', function (): void {
    Junctions::endpoint(['events' => ['item.trashed']]);

    $this->publish('Nobody listens');

    expect((new EventStore)->count())->toBe(0);
});

it('sends a published item, signed, when the deliver job runs', function (): void {
    Junctions::endpoint();
    $item = $this->publish('Hello, Junction');

    expect($this->junction->runJob('deliver')->state)->toBe('finished');

    $sent = $this->fake->sent(Junctions::HOST);
    expect($sent)->toHaveCount(1);

    /** @var SentRequest $request */
    $request = $sent[0];
    $body = $request->json();

    expect($body['event'])->toBe('item.published')
        ->and($body['event_id'])->toMatch('/^[0-9a-z]{26}$/')
        ->and($body['data']['item']['id'])->toBe($item->id)
        ->and($body['data']['item']['title'])->toBe('Hello, Junction')
        ->and($body['data']['item']['status'])->toBe('published')
        ->and($body['data']['item']['url'])->toStartWith('http')
        ->and($body['data']['item'])->toHaveKeys(['published_at', 'author', 'excerpt', 'featured_image', 'terms'])
        ->and($body['site'])->toHaveKeys(['name', 'url']);

    $header = fn (string $name): string => $request->headers[$name][0] ?? '';
    $timestamp = (int) $header(Signer::TIMESTAMP_HEADER);

    expect($header(Signer::EVENT_HEADER))->toBe('item.published')
        ->and($header(Signer::DELIVERY_HEADER))->toMatch('/^[0-9a-z]{26}$/')
        ->and(Signer::verify(Junctions::SECRET, $timestamp, $request->body, $header(Signer::SIGNATURE_HEADER), time()))->toBeTrue();

    expect((new EventStore)->count())->toBe(0)
        ->and(DB::table(DeliveryStore::TABLE)->value('status'))->toBe('delivered')
        ->and(DB::table(DeliveryStore::ATTEMPTS)->count())->toBe(1);
});

it('tells a first publish from an update', function (): void {
    Junctions::endpoint();
    $item = $this->publish('First');
    $this->publish($item);

    $this->junction->runJob('deliver');

    $events = array_map(fn (SentRequest $request): string => $request->json()['event'], $this->fake->sent(Junctions::HOST));
    expect($events)->toBe(['item.published', 'item.updated']);
});

it('sends an unpublished item without its address or content', function (): void {
    Junctions::endpoint();
    $item = $this->publish('Going away');
    $this->junction->runJob('deliver');

    $this->unpublish($item);
    $this->junction->runJob('deliver');

    $last = $this->fake->sent(Junctions::HOST)[1]->json();
    expect($last['event'])->toBe('item.unpublished')
        ->and($last['data']['item']['url'])->toBeNull()
        ->and($last['data']['item'])->not->toHaveKeys(['excerpt', 'author', 'terms'])
        ->and($last['data']['former_url'])->toStartWith('http');
});

it('sends a trashed item', function (): void {
    Junctions::endpoint();
    $item = $this->publish('To the bin');
    $this->trash($item);

    $this->junction->runJob('deliver');

    $events = array_map(fn (SentRequest $request): string => $request->json()['event'], $this->fake->sent(Junctions::HOST));
    expect($events)->toContain('item.trashed');
});

it('keeps to an endpoint\'s content types', function (): void {
    Junctions::endpoint(['types' => ['page']]);
    $this->publish('A post, not a page');

    $this->junction->runJob('deliver');

    $this->fake->assertNothingSent();
});

it('drops an item when a category filter is set and the item has none of them', function (): void {
    Junctions::endpoint(['categories' => ['news']]);
    $this->publish('Uncategorised');

    $this->junction->runJob('deliver');

    $this->fake->assertNothingSent();
});

it('sends only the item fields an endpoint picked', function (): void {
    Junctions::endpoint(['fields' => ['title', 'url']]);
    $this->publish('Picked');

    $this->junction->runJob('deliver');

    expect(array_keys($this->fake->sent(Junctions::HOST)[0]->json()['data']['item']))->toBe(['title', 'url']);
});

it('sends the names of changed settings, never their values', function (): void {
    Junctions::endpoint(['events' => ['settings.changed']]);
    $this->junction->changeSettings(['retention_days' => 12]);

    $this->junction->runJob('deliver');

    $body = $this->fake->sent(Junctions::HOST)[0]->json();
    expect($body['event'])->toBe('settings.changed')
        ->and($body['data']['settings'])->toBe(['group' => 'plugin.junction', 'keys' => ['retention_days']])
        ->and($this->fake->sent(Junctions::HOST)[0]->body)->not->toContain('12');
});

it('sends a chat app its own simple message', function (string $format, string $key): void {
    Junctions::endpoint(['format' => $format]);
    $this->publish('Chatty');

    $this->junction->runJob('deliver');

    $body = $this->fake->sent(Junctions::HOST)[0]->json();
    $text = $format === 'teams' ? $body['attachments'][0]['content']['body'][0]['text'] : $body[$key];
    expect($text)->toContain('Chatty');
})->with([
    'Slack' => ['slack', 'text'],
    'Discord' => ['discord', 'content'],
    'Google Chat' => ['google_chat', 'text'],
    'Microsoft Teams' => ['teams', 'attachments'],
]);
