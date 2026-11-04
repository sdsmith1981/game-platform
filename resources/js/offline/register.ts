/**
 * Start the service worker that keeps the game for offline play (served at /sw.js by
 * GamePlatform\Offline\ServiceWorker). Not while developing: the dev server's files
 * change as they're edited.
 */
export function registerOfflineWorker(): void {
    if (import.meta.env.DEV || !('serviceWorker' in navigator)) {
        return;
    }

    void navigator.serviceWorker
        .register('/sw.js', { scope: '/' })
        .catch(() => {
            // Offline play just isn't available in this browser.
        });
}
