/** How much a player may still play; see GamePlatform\Billing\PlayTime::status(). */
export interface PlayTimeStatus {
    canPlay: boolean;
    /** Playing something free (a tutorial, say), which uses no play-time. */
    free?: boolean;
    unlimited: boolean;
    unlimitedUntil: string | null;
    remainingSeconds: number;
    usedSeconds: number;
    freeSeconds: number;
    warnSeconds: number;
    heartbeatSeconds: number;
}

/** Something on sale; prices are in pence. */
export interface Product {
    id: string;
    name: string;
    description: string;
    price: number;
}

export function pounds(pence: number): string {
    return `£${(pence / 100).toFixed(pence % 100 === 0 ? 0 : 2)}`;
}

/** "30 days 2 hr", "1 hr 25 min", "40 min" or "under a minute". */
export function duration(seconds: number): string {
    const minutes = Math.floor(seconds / 60);
    const hours = Math.floor(minutes / 60);
    const rest = minutes % 60;

    // Past two days, days and hours read better than "722 hr".
    if (hours >= 48) {
        const days = Math.floor(hours / 24);

        return hours % 24 > 0
            ? `${days} days ${hours % 24} hr`
            : `${days} days`;
    }

    if (hours > 0) {
        return rest > 0 ? `${hours} hr ${rest} min` : `${hours} hr`;
    }

    return minutes > 0 ? `${minutes} min` : 'under a minute';
}

export function dateOf(iso: string): string {
    return new Date(iso).toLocaleDateString('en-GB', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}
