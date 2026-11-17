<?php

/**
 * The payload's envelope, the field picker, what is never sent, the excerpt
 * and endpoint filters.
 *
 * @package   KraftySprouts\Junction
 * @author    Krafty Sprouts Media, LLC
 * @copyright 2026 Krafty Sprouts Media, LLC
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare(strict_types=1);

use Carbon\CarbonImmutable;
use KraftySprouts\Junction\Endpoints\Endpoint;
use KraftySprouts\Junction\Events\EventNames;
use KraftySprouts\Junction\Payload\ItemDetails;
use KraftySprouts\Junction\Payload\PayloadBuilder;
use Teme\Contracts\Content\ItemRef;
use Teme\Contracts\Content\ItemSnapshot;

function junctionItemData(): array
{
    return [
        'item' => ['id' => 'i1', 'type' => 'post', 'title' => 'T', 'status' => 'published', 'url' => 'https://site.example/t/', 'language' => 'en-US'],
        'revision_id' => 'r1',
        'published_at' => '2026-11-17T10:00:00Z',
    ];
}

function junctionEndpoint(array $overrides = []): Endpoint
{
    return new Endpoint(...[
        'id' => 'e1', 'name' => 'E', 'url' => 'https://hooks.example.com/x', 'enabled' => true, 'format' => 'json', 'timeout' => 10,
        'events' => ['item.published'], 'otherEvents' => [], 'types' => [], 'categories' => [], 'tags' => [], 'fields' => [], 'secret' => 's',
        ...$overrides,
    ]);
}

it('wraps every event in the same envelope, and never sends the revision', function (): void {
    $payload = (new PayloadBuilder)->build('item.published', 'ev1', CarbonImmutable::parse('2026-11-17 10:00:01', 'UTC'), junctionItemData(), 'Site', 'https://site.example', ['author' => 'Ada', 'excerpt' => 'Hi', 'terms' => ['category' => ['news']]]);

    expect(array_keys($payload))->toBe(['event', 'event_id', 'occurred_at', 'site', 'data'])
        ->and($payload['occurred_at'])->toBe('2026-11-17T10:00:01Z')
        ->and(array_keys($payload['data']['item']))->toBe(['id', 'type', 'title', 'url', 'status', 'language', 'published_at', 'author', 'excerpt', 'featured_image', 'terms'])
        ->and(json_encode($payload))->not->toContain('r1');
});

it('sends no excerpt, author or terms for an item that is not public', function (): void {
    $payload = (new PayloadBuilder)->build('item.unpublished', 'ev1', CarbonImmutable::now(), junctionItemData(), 'Site', 'https://site.example', ['author' => 'Ada', 'excerpt' => 'Secret draft words', 'terms' => []]);

    expect($payload['data']['item'])->not->toHaveKeys(['excerpt', 'author', 'terms']);
});

it('keeps only the picked item fields, and every one when none was picked', function (): void {
    $payload = (new PayloadBuilder)->build('item.published', 'ev1', CarbonImmutable::now(), junctionItemData(), 'Site', 'https://site.example');

    expect(array_keys(PayloadBuilder::pick($payload, ['url', 'title'])['data']['item']))->toBe(['title', 'url'])
        ->and(PayloadBuilder::pick($payload, []))->toBe($payload);
});

it('makes an excerpt from the excerpt field, or the body\'s first 55 words', function (): void {
    $item = new ItemRef('i1', 'post', 'T', 'published', null, 'en-US');
    $words = implode(' ', array_fill(0, 60, 'word'));
    $body = ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $words]]]]];

    $fromBody = ItemDetails::excerpt(new ItemSnapshot($item, 'r1', 't', $body, [], [], [], []));
    $fromField = ItemDetails::excerpt(new ItemSnapshot($item, 'r1', 't', $body, ['excerpt' => '<b>Short</b> one'], [], [], []));

    expect(str_word_count(rtrim($fromBody, '…')))->toBe(55)
        ->and($fromBody)->toEndWith('…')
        ->and($fromField)->toBe('Short one');
});

it('matches events, other plugins\' events and filters', function (): void {
    $endpoint = junctionEndpoint(['otherEvents' => ['shop.order_placed'], 'types' => ['post'], 'categories' => ['news']]);

    expect($endpoint->takes('item.published'))->toBeTrue()
        ->and($endpoint->takes('item.trashed'))->toBeFalse()
        ->and($endpoint->takes(EventNames::TEST))->toBeTrue()
        ->and($endpoint->takes('shop.order_placed'))->toBeTrue()
        ->and($endpoint->takes('shop.refunded'))->toBeFalse()
        ->and(junctionEndpoint(['otherEvents' => ['*']])->takes('shop.refunded'))->toBeTrue()
        ->and(junctionEndpoint(['otherEvents' => ['*']])->takes('item.deleted'))->toBeFalse()
        ->and($endpoint->acceptsItem('post', ['category' => ['news']]))->toBeTrue()
        ->and($endpoint->acceptsItem('page', ['category' => ['news']]))->toBeFalse()
        ->and($endpoint->acceptsItem('post', ['category' => ['sport']]))->toBeFalse()
        ->and($endpoint->acceptsItem('post', null))->toBeFalse()
        ->and($endpoint->acceptsItem(null, null))->toBeTrue();
});

it('refuses other plugins\' event names that would pass for Junction\'s own', function (): void {
    expect(EventNames::isOtherPluginEvent('shop.order_placed'))->toBeTrue()
        ->and(EventNames::isOtherPluginEvent('item.published'))->toBeFalse()
        ->and(EventNames::isOtherPluginEvent('junction.test'))->toBeFalse()
        ->and(EventNames::isOtherPluginEvent('Shop.Order'))->toBeFalse()
        ->and(EventNames::isOtherPluginEvent('noprefix'))->toBeFalse();
});
