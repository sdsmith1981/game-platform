import { xsrfToken } from './xsrf';

/**
 * Sending saves to the server and loading them back, as gzipped JSON. The server side
 * is GamePlatform\Saves\RevisionedSave (behind the DecompressGzipRequest middleware).
 */

interface Body {
    data: BodyInit;
    gzipped: boolean;
}

/**
 * The save as JSON, gzipped where the browser can (saves are mostly repetitive
 * numbers, so they shrink a lot). Older browsers send it as it is.
 */
export async function saveBody(
    state: unknown,
    baseRevision?: number,
): Promise<Body> {
    return jsonBody(
        baseRevision === undefined
            ? { state }
            : { base_revision: baseRevision, state },
    );
}

/** Anything as JSON, gzipped where the browser can. */
export async function jsonBody(payload: unknown): Promise<Body> {
    const json = JSON.stringify(payload);
    if (typeof CompressionStream === 'undefined') {
        return { data: json, gzipped: false };
    }

    const stream = new Blob([json])
        .stream()
        .pipeThrough(new CompressionStream('gzip'));

    return { data: await new Response(stream).arrayBuffer(), gzipped: true };
}

function put(url: string, body: Body): Promise<Response> {
    return fetch(url, {
        method: 'PUT',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-XSRF-TOKEN': xsrfToken(),
            ...(body.gzipped ? { 'Content-Encoding': 'gzip' } : {}),
        },
        body: body.data,
    });
}

/**
 * A save that didn't go through. `status` is the server's reply, or 0 when it couldn't
 * be reached at all.
 */
export class SaveError extends Error {
    constructor(
        message: string,
        readonly status: number,
    ) {
        super(message);
    }
}

/** Explain a failed save in the player's terms. */
function describe(status: number, message: string | undefined): string {
    switch (status) {
        case 401:
            return "you've been signed out. Sign in again in another tab, then press Save";
        case 403:
            return 'this game belongs to another account';
        case 409:
            return 'this game was saved from another tab or device since you opened it. Reload to carry on from that save';
        case 413:
            return 'the save is too large';
        case 419:
            return 'your session expired. Reload the page and try again';
        case 422:
            return `the server rejected the save (${message ?? 'invalid data'})`;
        default:
            return status >= 500
                ? 'the server had a problem. Try again in a moment'
                : (message ?? `server replied ${status}`);
    }
}

/**
 * Send a save to the server, saying which revision it carries on from, and get back
 * the new revision. If the session's security token has gone stale (419), fetch a
 * fresh one by touching the server and try once more.
 */
export async function saveToServer(
    url: string,
    state: unknown,
    refreshUrl: string,
    /** Leave out to replace whatever the server has (the player chose this copy). */
    baseRevision?: number,
): Promise<number> {
    let response: Response;
    const body = await saveBody(state, baseRevision);

    try {
        response = await put(url, body);
        if (response.status === 419) {
            await fetch(refreshUrl, {
                method: 'HEAD',
                credentials: 'same-origin',
            });
            response = await put(url, body);
        }
    } catch {
        throw new SaveError(
            "couldn't reach the server. Check your connection",
            0,
        );
    }

    const reply = (await response.json().catch(() => ({}))) as {
        message?: string;
        revision?: number;
    };
    if (!response.ok) {
        throw new SaveError(
            describe(response.status, reply.message),
            response.status,
        );
    }

    return reply.revision ?? (baseRevision ?? 0) + 1;
}

/**
 * Fetch a save: gzipped JSON, straight from object storage or from the app. Cookies go
 * only to the app; a storage link carries its own permission.
 */
export async function loadSave<T = unknown>(url: string): Promise<T> {
    const response = await fetch(url, { credentials: 'same-origin' });
    if (!response.ok || !response.body) {
        throw new Error(`Could not load the save (${response.status})`);
    }

    const json = await new Response(
        response.body.pipeThrough(new DecompressionStream('gzip')),
    ).text();

    return JSON.parse(json) as T;
}
