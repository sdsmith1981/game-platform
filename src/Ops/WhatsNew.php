<?php

namespace GamePlatform\Ops;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;

/**
 * Release notes shown once to each player after a deploy that adds one. Releases live
 * in the app's config/whats-new.php (`releases`), newest first; the user model keeps
 * the last one each player saw in `whats_new_seen`.
 */
class WhatsNew
{
    /** Players who have never seen the notes get at most this many releases. */
    public const MAX_SHOWN = 3;

    /**
     * @return list<array{id: string, date: string, title: string, items: list<string>}>
     */
    public static function releases(): array
    {
        /** @var list<array{id: string, date: string, title: string, items: list<string>}> $releases */
        $releases = Config::array('whats-new.releases', []);

        return $releases;
    }

    public static function latestId(): ?string
    {
        return self::releases()[0]['id'] ?? null;
    }

    /**
     * Releases newer than the last one this user saw, newest first.
     *
     * @return list<array{id: string, date: string, title: string, items: list<string>}>
     */
    public static function unseenBy(Model $user): array
    {
        $seen = $user->getAttribute('whats_new_seen');
        $unseen = [];

        foreach (self::releases() as $release) {
            if ($release['id'] === $seen) {
                break;
            }
            $unseen[] = $release;
        }

        return array_slice($unseen, 0, self::MAX_SHOWN);
    }

    public static function markSeen(Model $user): void
    {
        $user->forceFill(['whats_new_seen' => self::latestId()])->save();
    }
}
