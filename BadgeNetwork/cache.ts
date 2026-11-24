import { FluxDispatcher } from "@webpack/common";

import { fetchPublicBadges, type ServerBadge } from "./api";

type CacheEntry = {
    badges: ServerBadge[];
    loadedAt: number;
    loading?: Promise<ServerBadge[]>;
};

const cache = new Map<string, CacheEntry>();
const TTL_MS = 60_000;

export function getCachedBadges(userId: string): ServerBadge[] | null {
    const entry = cache.get(userId);
    if (!entry) return null;
    if (Date.now() - entry.loadedAt > TTL_MS) return null;
    return entry.badges;
}

export function setCachedBadges(userId: string, badges: ServerBadge[]) {
    cache.set(userId, { badges, loadedAt: Date.now() });
}

export function invalidateCache(userId?: string) {
    if (userId) cache.delete(userId);
    else cache.clear();
}

function bumpProfileRender(userId: string) {
    try {
        FluxDispatcher.dispatch({
            type: "USER_PROFILE_FETCH_SUCCESS",
            userId,
            // minimal noop-ish refresh signal used by several client mods
        } as any);
    } catch {
        // ignore
    }
}

export function ensureBadgesLoaded(apiBase: string, userId: string): ServerBadge[] {
    const hit = getCachedBadges(userId);
    if (hit) return hit;

    const existing = cache.get(userId);
    if (!existing?.loading) {
        const loading = fetchPublicBadges(apiBase, userId)
            .then(badges => {
                setCachedBadges(userId, badges);
                if (badges.length) bumpProfileRender(userId);
                return badges;
            })
            .catch(() => {
                setCachedBadges(userId, []);
                return [] as ServerBadge[];
            });
        cache.set(userId, { badges: existing?.badges ?? [], loadedAt: 0, loading });
    }

    return existing?.badges ?? [];
}
