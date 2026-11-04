import type { PlayTimeStatus } from './billing';
import { duration } from './billing';
import { xsrfToken } from './xsrf';

/** What the play-time checks need from the game being played. */
export interface PlayTimeHost {
    /** Whether the game is running (not paused). */
    isRunning(): boolean;
    /** Stop play when play-time runs out, and allow it again once there's more. */
    setLocked(locked: boolean): void;
    /** Tell the player play-time is running low. */
    warn(message: string): void;
}

/**
 * Keep the server told how long the game is being played, and lock the game when
 * play-time runs out. Only time with the game running in a visible tab counts; the
 * server does the measuring. Returns a function that stops the checks.
 */
export function trackPlayTime(
    host: PlayTimeHost,
    url: string,
    initial: PlayTimeStatus,
    onChange: (status: PlayTimeStatus) => void,
    /** What's being played, so the server can tell free play (a tutorial, say). */
    gameId?: string,
): () => void {
    let playing = false;
    let warned = false;

    const apply = (status: PlayTimeStatus) => {
        onChange(status);
        host.setLocked(!status.canPlay);

        const low =
            !status.unlimited &&
            !status.free &&
            status.canPlay &&
            status.remainingSeconds <= status.warnSeconds;
        if (low && !warned) {
            warned = true;
            host.warn(`${duration(status.remainingSeconds)} of play-time left.`);
        }
    };

    const beat = async () => {
        const running =
            host.isRunning() && document.visibilityState === 'visible';
        // A check-in after a break starts the clock again rather than counting the break.
        const resumed = !playing;
        playing = running;
        if (!running) {
            return;
        }

        try {
            const response = await fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-XSRF-TOKEN': xsrfToken(),
                },
                body: JSON.stringify({ resumed, game: gameId }),
            });
            if (response.ok) {
                apply((await response.json()) as PlayTimeStatus);
            }
        } catch {
            // Offline for a moment: try again next time.
            playing = false;
        }
    };

    apply(initial);
    const timer = window.setInterval(
        () => void beat(),
        initial.heartbeatSeconds * 1000,
    );
    // Start the clock as soon as the game is running, not a minute later.
    const kick = window.setTimeout(() => void beat(), 2000);

    return () => {
        window.clearInterval(timer);
        window.clearTimeout(kick);
    };
}
