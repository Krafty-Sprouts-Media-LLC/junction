<?php

/**
 * Turns a payload into the simple message a chat app's incoming webhook
 * takes: Slack, Discord, Microsoft Teams or Google Chat.
 *
 * @package   KraftySprouts\Junction
 * @author    Krafty Sprouts Media, LLC
 * @copyright 2026 Krafty Sprouts Media, LLC
 * @license   GPL-2.0-or-later
 * @since     1.0.0
 */

declare(strict_types=1);

namespace KraftySprouts\Junction\Payload;

use KraftySprouts\Junction\Events\EventNames;

/**
 * One line of plain text for each event, wrapped in the shape each chat app
 * reads. JSON, the default format, is sent as the payload itself.
 *
 * @since 1.0.0
 */
final class ChatFormat
{
    /** @since 1.0.0 */
    public const string JSON = 'json';

    /** @since 1.0.0 */
    public const string SLACK = 'slack';

    /** @since 1.0.0 */
    public const string DISCORD = 'discord';

    /** @since 1.0.0 */
    public const string TEAMS = 'teams';

    /** @since 1.0.0 */
    public const string GOOGLE_CHAT = 'google_chat';

    /**
     * Every format an endpoint may choose.
     *
     * @since 1.0.0
     */
    public const array ALL = [self::JSON, self::SLACK, self::DISCORD, self::TEAMS, self::GOOGLE_CHAT];

    /** Discord's limit on a message's characters. @since 1.0.0 */
    private const int DISCORD_LIMIT = 2000;

    /**
     * The body for this format: the payload itself for JSON, else a message.
     *
     * @param  array{event: string, event_id: string, occurred_at: string, site: array{name: string, url: string}, data: array<string, mixed>}  $payload
     * @return array<string, mixed>
     *
     * @since 1.0.0
     */
    public static function body(array $payload, string $format): array
    {
        if ($format === self::JSON || ! in_array($format, self::ALL, true)) {
            return $payload;
        }

        $text = self::line($payload);

        return match ($format) {
            self::SLACK => ['text' => $text],
            self::DISCORD => ['content' => mb_substr($text, 0, self::DISCORD_LIMIT), 'allowed_mentions' => ['parse' => []]],
            self::GOOGLE_CHAT => ['text' => $text],
            self::TEAMS => [
                'type' => 'message',
                'attachments' => [[
                    'contentType' => 'application/vnd.microsoft.card.adaptive',
                    'content' => [
                        '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
                        'type' => 'AdaptiveCard',
                        'version' => '1.4',
                        'body' => [['type' => 'TextBlock', 'text' => $text, 'wrap' => true]],
                    ],
                ]],
            ],
        };
    }

    /**
     * The event in one line of plain words.
     *
     * @param  array{event: string, event_id: string, occurred_at: string, site: array{name: string, url: string}, data: array<string, mixed>}  $payload
     *
     * @since 1.0.0
     */
    public static function line(array $payload): string
    {
        $data = $payload['data'];
        $item = is_array($data['item'] ?? null) ? $data['item'] : [];
        $site = $payload['site']['name'];
        $title = (string) ($item['title'] ?? '');
        $url = (string) ($item['url'] ?? $data['former_url'] ?? '');
        $with = fn (string $line): string => $url === '' ? $line : $line.' '.$url;

        return match ($payload['event']) {
            EventNames::ITEM_PUBLISHED => $with(__('New on :site: “:title”', ['site' => $site, 'title' => $title])),
            EventNames::ITEM_UPDATED => $with(__('Updated on :site: “:title”', ['site' => $site, 'title' => $title])),
            EventNames::ITEM_UNPUBLISHED => __('Unpublished on :site: “:title”', ['site' => $site, 'title' => $title]),
            EventNames::ITEM_SCHEDULED => __('Scheduled on :site: “:title” for :when', ['site' => $site, 'title' => $title, 'when' => (string) ($item['scheduled_for'] ?? '')]),
            EventNames::ITEM_TRASHED => __('Moved to the trash on :site: “:title”', ['site' => $site, 'title' => $title]),
            EventNames::ITEM_RESTORED => __('Restored from the trash on :site: “:title”', ['site' => $site, 'title' => $title]),
            EventNames::ITEM_DELETED => __('Deleted for good on :site: “:title”', ['site' => $site, 'title' => $title]),
            EventNames::COMMENT_RECEIVED => $with(__('New comment on :site, on “:title”', ['site' => $site, 'title' => $title])),
            EventNames::COMMENT_APPROVED => $with(__('Comment approved on :site, on “:title”', ['site' => $site, 'title' => $title])),
            EventNames::MEDIA_UPLOADED => __('New media on :site: “:title”', ['site' => $site, 'title' => (string) ($data['media']['title'] ?? '')]),
            EventNames::SETTINGS_CHANGED => __('Settings changed on :site: :group (:keys)', ['site' => $site, 'group' => (string) ($data['settings']['group'] ?? ''), 'keys' => implode(', ', (array) ($data['settings']['keys'] ?? []))]),
            EventNames::TEST => __('Junction test from :site: this endpoint works.', ['site' => $site]),
            default => __('Event :event on :site', ['event' => $payload['event'], 'site' => $site]),
        };
    }
}
