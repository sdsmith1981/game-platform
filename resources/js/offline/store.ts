/**
 * Games kept in the browser for offline play, in IndexedDB. Each holds the latest save
 * and which server revision it carries on from, plus whatever else the game needs to
 * play it (its map, say). A game played or saved offline is `dirty` until it's
 * uploaded (see sync.ts).
 */

/** What every kept game has; games add their own fields. */
export interface StoredGame {
    /** `${userId}:${gameId}`, so two players on one device keep their games apart. */
    key: string;
    userId: number;
    id: string;
    /** The latest save. */
    state: unknown;
    /** The server's revision this save carries on from. */
    revision: number;
    /** Saved on this device since it was last uploaded. */
    dirty: boolean;
    /** When it was last played or saved here (ms since 1970), for tidying up. */
    playedAt: number;
    /** Started on this device with no connection, and not on the server yet. */
    pending?: unknown;
}

export interface OfflinePlayer {
    id: number;
    name: string;
}

export interface OfflineStore<G extends StoredGame> {
    /** Games kept per player; the ones played longest ago go first, if they're uploaded. */
    keepGames: number;
    getGame: (userId: number, gameId: string) => Promise<G | undefined>;
    gamesOf: (userId: number) => Promise<G[]>;
    /** Keep a game, then tidy the player's oldest uploaded games beyond keepGames. */
    putGame: (game: G) => Promise<void>;
    deleteGame: (userId: number, gameId: string) => Promise<void>;
    /**
     * The player whose games the offline page shows: whoever last opened a game here
     * while signed in, until they sign out.
     */
    getPlayer: () => Promise<OfflinePlayer | null>;
    setPlayer: (player: OfflinePlayer | null) => Promise<void>;
}

const GAMES = 'games';
const META = 'meta';

/** Whether this browser can keep games at all. */
export function offlineStorageAvailable(): boolean {
    return typeof indexedDB !== 'undefined';
}

export const keyOf = (userId: number, gameId: string) => `${userId}:${gameId}`;

/** Ask the browser not to clear kept games when it's short of space. */
export function keepStorage(): void {
    void navigator.storage?.persist?.().catch(() => false);
}

/**
 * A store in its own IndexedDB database. Keep `dbName` the same for the life of a
 * game: players' kept games are in it.
 */
export function createOfflineStore<G extends StoredGame>(options: {
    dbName: string;
    keepGames?: number;
}): OfflineStore<G> {
    const keepGames = options.keepGames ?? 8;
    let opening: Promise<IDBDatabase> | undefined;

    function open(): Promise<IDBDatabase> {
        opening ??= new Promise((resolve, reject) => {
            const request = indexedDB.open(options.dbName, 1);
            request.onupgradeneeded = () => {
                const db = request.result;
                db.createObjectStore(GAMES, { keyPath: 'key' }).createIndex(
                    'userId',
                    'userId',
                );
                db.createObjectStore(META);
            };
            request.onsuccess = () => resolve(request.result);
            request.onerror = () => {
                opening = undefined;
                reject(
                    request.error ??
                        new Error('Could not open offline storage'),
                );
            };
        });

        return opening;
    }

    async function run<T>(
        store: string,
        mode: IDBTransactionMode,
        work: (store: IDBObjectStore) => IDBRequest<T>,
    ): Promise<T> {
        const db = await open();

        return new Promise((resolve, reject) => {
            const tx = db.transaction(store, mode);
            const request = work(tx.objectStore(store));
            tx.oncomplete = () => resolve(request.result);
            tx.onerror = () => reject(tx.error ?? request.error);
            tx.onabort = () =>
                reject(
                    tx.error ??
                        new Error('Offline storage write was cancelled'),
                );
        });
    }

    const getGame = (userId: number, gameId: string) =>
        run(GAMES, 'readonly', (s) => s.get(keyOf(userId, gameId))) as Promise<
            G | undefined
        >;

    const gamesOf = (userId: number) =>
        run(GAMES, 'readonly', (s) =>
            s.index('userId').getAll(userId),
        ) as Promise<G[]>;

    const deleteGame = async (userId: number, gameId: string) => {
        await run(GAMES, 'readwrite', (s) => s.delete(keyOf(userId, gameId)));
    };

    async function putGame(game: G): Promise<void> {
        await run(GAMES, 'readwrite', (s) => s.put(game));

        const games = await gamesOf(game.userId);
        const spare = games
            .filter((g) => !g.dirty && g.key !== game.key)
            .sort((a, b) => a.playedAt - b.playedAt);
        for (const old of spare.slice(
            0,
            Math.max(0, games.length - keepGames),
        )) {
            await deleteGame(old.userId, old.id);
        }
    }

    return {
        keepGames,
        getGame,
        gamesOf,
        putGame,
        deleteGame,
        getPlayer: async () =>
            ((await run(META, 'readonly', (s) => s.get('player'))) ??
                null) as OfflinePlayer | null,
        setPlayer: async (player) => {
            await run(META, 'readwrite', (s) => s.put(player, 'player'));
        },
    };
}
