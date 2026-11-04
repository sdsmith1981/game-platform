import { xsrfToken } from './xsrf';

/** The last few errors in this browser tab, sent with bug reports. */
const MAX = 10;
const recent: string[] = [];

/** Errors sent to the server (and on to Nightwatch) per page load, at most. */
const MAX_REPORTS = 5;
const reported = new Set<string>();

/** Where errors go (GamePlatform\Ops\ClientErrorController). */
let reportUrl = '/client-errors';

function remember(message: string): void {
    recent.push(`${new Date().toISOString()} ${message}`.slice(0, 2000));
    recent.splice(0, Math.max(0, recent.length - MAX));
}

export type BrowserError = {
    message: string;
    source?: string;
    line?: number;
    column?: number;
    stack?: string;
};

/**
 * Whether an error is worth sending: one from our own scripts, not a browser
 * extension's or another site's (those arrive as a bare "Script error."), and not one
 * already sent from this page.
 */
export function worthReporting(error: BrowserError, origin: string): boolean {
    if (reported.size >= MAX_REPORTS || reported.has(error.message)) {
        return false;
    }
    if (
        error.message === 'Script error.' ||
        error.message.startsWith('ResizeObserver loop')
    ) {
        return false;
    }

    return !error.source || error.source.startsWith(origin);
}

function send(error: BrowserError): void {
    if (import.meta.env.DEV || !worthReporting(error, window.location.origin)) {
        return;
    }
    reported.add(error.message);

    // Best effort: a report that fails is simply dropped.
    void fetch(reportUrl, {
        method: 'POST',
        credentials: 'same-origin',
        keepalive: true,
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-XSRF-TOKEN': xsrfToken(),
        },
        body: JSON.stringify({
            ...error,
            stack: error.stack?.slice(0, 8000),
            message: error.message.slice(0, 2000),
            page: window.location.pathname,
        }),
    }).catch(() => {});
}

/** Start listening for uncaught errors and rejected promises. */
export function installErrorLog(url = '/client-errors'): void {
    if (typeof window === 'undefined') {
        return;
    }
    reportUrl = url;

    window.addEventListener('error', (event) => {
        const where = event.filename
            ? ` (${event.filename}:${event.lineno}:${event.colno})`
            : '';
        const stack =
            event.error instanceof Error ? event.error.stack : undefined;
        remember(`${event.message}${where}${stack ? `\n${stack}` : ''}`);
        send({
            message: event.message,
            source: event.filename || undefined,
            line: event.lineno || undefined,
            column: event.colno || undefined,
            stack,
        });
    });
    window.addEventListener('unhandledrejection', (event) => {
        const reason = event.reason;
        const message =
            reason instanceof Error ? reason.message : String(reason);
        remember(
            `Unhandled rejection: ${reason instanceof Error ? `${reason.message}\n${reason.stack ?? ''}` : String(reason)}`,
        );
        send({
            message: `Unhandled rejection: ${message}`,
            stack: reason instanceof Error ? reason.stack : undefined,
        });
    });
}

export function recentErrors(): string[] {
    return [...recent];
}
