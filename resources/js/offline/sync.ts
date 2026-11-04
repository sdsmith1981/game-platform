import { jsonBody, SaveError, saveToServer } from '../lib/saving';
import { xsrfToken } from '../lib/xsrf';
import type { OfflineStore, StoredGame } from './store';
import { keyOf } from './store';

/**
 * Uploading games played offline. A save goes up saying which revision it carries on
 * from; if the game was saved somewhere else meanwhile, that's a conflict and the
 * player chooses which copy to keep (see resolve()).
 */

export type SyncResult =
    /** Uploaded, or nothing to upload. */
    | 'synced'
    /** Saved somewhere else since: the player must choose. */
    | 'conflict'
    /** Signed out, or the session expired: sign in, then it can go up. */
    | 'signed-out'
    /** No connection: try later. */
    | 'offline'
    /** The server refused it for another reason. */
    | 'error';

export interface SyncSummary<G> {
    synced: G[];
    conflicts: G[];
    /** Couldn't be uploaded now; they stay on the device to try again. */
    waiting: G[];
}

export interface SyncOptions<G> {
    /** Where a game's save goes (PUT, see RevisionedSave). */
    saveUrl: (gameId: string) => string;
    /** A page to touch for a fresh security token when the session's has expired. */
    refreshUrl: (gameId: string) => string;
    /**
     * Where a game started offline goes (POST). The server makes it, keeps its save,
     * and replies with its `id` and `revision`.
     */
    importUrl: () => string;
    /** What to send for a game started offline, besides its save (`state`). */
    newGameBody: (game: G) => Record<string, unknown>;
}

export interface OfflineSync<G> {
    upload: (game: G, options?: { replace?: boolean }) => Promise<SyncResult>;
    /** Upload every game this player played offline. */
    uploadAll: (userId: number) => Promise<SyncSummary<G>>;
    /**
     * Settle a conflict: keep this device's copy (it replaces the server's), or keep the
     * server's (this device's copy is dropped).
     */
    resolve: (game: G, keep: 'device' | 'server') => Promise<SyncResult>;
}

export function createOfflineSync<G extends StoredGame>(
    store: OfflineStore<G>,
    options: SyncOptions<G>,
): OfflineSync<G> {
    async function upload(
        game: G,
        { replace = false }: { replace?: boolean } = {},
    ): Promise<SyncResult> {
        if (game.pending) {
            return uploadNew(game);
        }
        if (!game.dirty) {
            return 'synced';
        }

        try {
            const revision = await saveToServer(
                options.saveUrl(game.id),
                game.state,
                options.refreshUrl(game.id),
                replace ? undefined : game.revision,
            );
            await store.putGame({ ...game, revision, dirty: false });

            return 'synced';
        } catch (error) {
            const status = error instanceof SaveError ? error.status : -1;
            if (status === 0) {
                return 'offline';
            }
            if (status === 409) {
                return 'conflict';
            }
            if (status === 401 || status === 419) {
                return 'signed-out';
            }

            return 'error';
        }
    }

    /** A game started on this device: kept here under the server's id from then on. */
    async function uploadNew(game: G): Promise<SyncResult> {
        let response: Response;
        try {
            const body = await jsonBody({
                ...options.newGameBody(game),
                state: game.state,
            });
            response = await fetch(options.importUrl(), {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': xsrfToken(),
                    ...(body.gzipped ? { 'Content-Encoding': 'gzip' } : {}),
                },
                body: body.data,
            });
        } catch {
            return 'offline';
        }
        if (response.status === 401 || response.status === 419) {
            return 'signed-out';
        }
        if (!response.ok) {
            return 'error';
        }

        const { id, revision } = (await response.json()) as {
            id: string;
            revision: number;
        };
        await store.deleteGame(game.userId, game.id);
        await store.putGame({
            ...game,
            key: keyOf(game.userId, id),
            id,
            revision,
            dirty: false,
            pending: undefined,
        });

        return 'synced';
    }

    async function uploadAll(userId: number): Promise<SyncSummary<G>> {
        const summary: SyncSummary<G> = {
            synced: [],
            conflicts: [],
            waiting: [],
        };

        for (const game of await store.gamesOf(userId)) {
            if (!game.dirty && !game.pending) {
                continue;
            }

            const result = await upload(game);
            if (result === 'synced') {
                summary.synced.push(game);
            } else if (result === 'conflict') {
                summary.conflicts.push(game);
            } else {
                summary.waiting.push(game);
            }
        }

        return summary;
    }

    async function resolve(
        game: G,
        keep: 'device' | 'server',
    ): Promise<SyncResult> {
        if (keep === 'device') {
            return upload(game, { replace: true });
        }

        await store.deleteGame(game.userId, game.id);

        return 'synced';
    }

    return { upload, uploadAll, resolve };
}
