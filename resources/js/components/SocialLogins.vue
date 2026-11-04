<script setup lang="ts">
/**
 * "Continue with Google" and the like, for the log in and sign up pages. Only the
 * providers set up on the server are passed in (GamePlatform\Social\SocialLogin::available());
 * with none, nothing shows.
 *
 * Styled with Tailwind and shadcn-vue's theme colours: add this folder to the app's
 * Tailwind sources (`@source '../../node_modules/@sdsmith1981/game-platform/resources/js'`).
 */

export type SocialProvider = { id: string; name: string };

const { divider = true, redirectUrl = (id: string) => `/auth/${id}/redirect` } =
    defineProps<{
        providers: SocialProvider[];
        /** Off where something else (the passkey button) follows with its own divider. */
        divider?: boolean;
        /** Where each button goes; the package's route by default. */
        redirectUrl?: (provider: string) => string;
    }>();

/** Each provider's mark, drawn in its own colours as their brand guidelines ask. */
const icons: Record<
    string,
    { viewBox: string; paths: { d: string; fill: string }[] }
> = {
    google: {
        viewBox: '0 0 24 24',
        paths: [
            {
                fill: '#4285F4',
                d: 'M23.5 12.27c0-.85-.08-1.67-.22-2.45H12v4.63h6.45a5.52 5.52 0 0 1-2.4 3.62v3h3.88c2.27-2.09 3.57-5.17 3.57-8.8Z',
            },
            {
                fill: '#34A853',
                d: 'M12 24c3.24 0 5.96-1.07 7.94-2.9l-3.88-3.01c-1.07.72-2.45 1.15-4.06 1.15-3.13 0-5.78-2.11-6.72-4.95H1.27v3.1A12 12 0 0 0 12 24Z',
            },
            {
                fill: '#FBBC05',
                d: 'M5.28 14.29a7.2 7.2 0 0 1 0-4.58v-3.1H1.27a12 12 0 0 0 0 10.78l4.01-3.1Z',
            },
            {
                fill: '#EA4335',
                d: 'M12 4.77c1.76 0 3.34.61 4.59 1.8l3.44-3.44A11.97 11.97 0 0 0 1.27 6.61l4.01 3.1C6.22 6.88 8.87 4.77 12 4.77Z',
            },
        ],
    },
    facebook: {
        viewBox: '0 0 24 24',
        paths: [
            {
                fill: '#1877F2',
                d: 'M24 12a12 12 0 1 0-13.88 11.85v-8.38H7.08V12h3.04V9.36c0-3 1.79-4.67 4.53-4.67 1.31 0 2.68.24 2.68.24v2.95h-1.51c-1.49 0-1.96.93-1.96 1.88V12h3.33l-.53 3.47h-2.8v8.38A12 12 0 0 0 24 12Z',
            },
            {
                fill: '#FFFFFF',
                d: 'm16.67 15.47.53-3.47h-3.33V9.76c0-.95.47-1.88 1.96-1.88h1.51V4.93s-1.37-.24-2.68-.24c-2.74 0-4.53 1.66-4.53 4.67V12H7.08v3.47h3.05v8.38a12.1 12.1 0 0 0 3.75 0v-8.38h2.79Z',
            },
        ],
    },
    github: {
        viewBox: '0 0 24 24',
        paths: [
            {
                fill: 'currentColor',
                d: 'M12 .3a12 12 0 0 0-3.8 23.38c.6.12.83-.26.83-.57l-.02-2.23c-3.34.73-4.04-1.42-4.04-1.42-.55-1.39-1.33-1.76-1.33-1.76-1.09-.74.08-.73.08-.73 1.2.09 1.84 1.24 1.84 1.24 1.07 1.83 2.81 1.3 3.5 1 .1-.78.42-1.31.76-1.61-2.67-.3-5.47-1.33-5.47-5.93 0-1.31.47-2.38 1.24-3.22-.13-.3-.54-1.52.12-3.18 0 0 1-.32 3.3 1.23a11.5 11.5 0 0 1 6 0c2.28-1.55 3.29-1.23 3.29-1.23.66 1.66.24 2.88.12 3.18.77.84 1.23 1.91 1.23 3.22 0 4.61-2.8 5.63-5.48 5.92.43.37.82 1.1.82 2.22l-.01 3.29c0 .32.21.7.82.58A12 12 0 0 0 12 .3Z',
            },
        ],
    },
};
</script>

<template>
    <div v-if="providers.length" :class="divider ? 'mb-6' : 'mb-2'">
        <div class="grid gap-2">
            <!-- A full page load, not an Inertia visit: the provider's page isn't ours. -->
            <a
                v-for="provider in providers"
                :key="provider.id"
                :href="redirectUrl(provider.id)"
                class="inline-flex h-9 items-center justify-center gap-2 rounded-md border border-input bg-transparent px-4 text-sm font-medium shadow-xs transition-colors hover:bg-accent hover:text-accent-foreground focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none dark:bg-input/30 dark:hover:bg-input/50"
                :data-test="`social-login-${provider.id}`"
            >
                <svg
                    v-if="icons[provider.id]"
                    :viewBox="icons[provider.id].viewBox"
                    class="size-4"
                    aria-hidden="true"
                >
                    <path
                        v-for="(path, i) in icons[provider.id].paths"
                        :key="i"
                        :d="path.d"
                        :fill="path.fill"
                    />
                </svg>
                Continue with {{ provider.name }}
            </a>
        </div>
        <div
            v-if="divider"
            class="mt-6 flex items-center gap-2 text-xs text-muted-foreground uppercase"
        >
            <div class="h-px flex-1 bg-border" />
            Or use your email
            <div class="h-px flex-1 bg-border" />
        </div>
    </div>
</template>
