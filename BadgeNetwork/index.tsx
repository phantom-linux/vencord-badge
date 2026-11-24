import { addProfileBadge, BadgePosition, removeProfileBadge } from "@api/Badges";
import definePlugin from "@utils/types";

import { ensureBadgesLoaded } from "./cache";
import { SettingsPanel } from "./SettingsPanel";
import { DEFAULT_API_BASE, settings } from "./settings";

function apiBase() {
    return (settings.store.apiBase || DEFAULT_API_BASE).replace(/\/$/, "");
}

const networkBadge = {
    id: "badge-network",
    description: "Badge Network",
    position: BadgePosition.START,
    getBadges({ userId }: { userId: string; }) {
        const badges = ensureBadgesLoaded(apiBase(), userId);
        return badges.map(badge => ({
            id: `badge-network-${badge.id}`,
            description: badge.name,
            iconSrc: badge.iconUrl,
            link: apiBase(),
        }));
    },
};

export default definePlugin({
    name: "BadgeNetwork",
    description: "Crée des badges custom stockés sur ton serveur ; visibles par les users qui ont ce plugin.",
    authors: [{ name: "Linux Phantom", id: 1222261705720205372n }],
    settings,
    settingsAboutComponent: SettingsPanel,

    start() {
        addProfileBadge(networkBadge);
    },

    stop() {
        removeProfileBadge(networkBadge);
    },
});
