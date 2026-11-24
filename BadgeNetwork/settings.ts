import { definePluginSettings } from "@api/Settings";
import { OptionType } from "@utils/types";

// ⚠️ Remplace par l'URL HTTPS de TON serveur (sans slash final).
export const DEFAULT_API_BASE = "https://badges.example.com";

export const settings = definePluginSettings({
    apiBase: {
        type: OptionType.STRING,
        description: "URL de l'API badges",
        default: DEFAULT_API_BASE,
    },
    apiToken: {
        type: OptionType.STRING,
        description: "Token API (via Se connecter Discord dans le panneau ci-dessous)",
        default: "",
        placeholder: "Colle ton JWT ici",
    },
});
