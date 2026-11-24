import { Button } from "@components/Button";
import { ErrorCard } from "@components/ErrorCard";
import { Heading } from "@components/Heading";
import { Paragraph } from "@components/Paragraph";
import { Margins } from "@utils/margins";
import { promptToRestart } from "@utils/promptToRestart";
import { useAwaiter, useForceUpdater } from "@utils/react";
import { showToast, TextInput, useEffect, useState } from "@webpack/common";

import { fetchMe, fetchMyBadges, saveMyBadges, uploadBadgeIcon, type ServerBadge } from "./api";
import { invalidateCache, setCachedBadges } from "./cache";
import { DEFAULT_API_BASE, settings } from "./settings";

function newId() {
    return crypto.randomUUID();
}

function notify(text: string, variant?: "success" | "critical") {
    showToast(variant ? { text, variant } : { text });
}

async function isApiAllowed(apiBase: string) {
    if (IS_WEB) return true;
    return VencordNative.csp.isDomainAllowed(apiBase, ["connect-src", "img-src"]);
}

export function SettingsPanel() {
    const { apiBase, apiToken } = settings.use(["apiBase", "apiToken"]);
    const [badges, setBadges] = useState<ServerBadge[]>([]);
    const [username, setUsername] = useState<string | null>(null);
    const [name, setName] = useState("");
    const [file, setFile] = useState<File | null>(null);
    const [busy, setBusy] = useState(false);
    const [status, setStatus] = useState("");
    const [cspSignal, bumpCsp] = useForceUpdater(true);

    const base = (apiBase || DEFAULT_API_BASE).replace(/\/$/, "");

    const [cspAllowed] = useAwaiter(
        async () => isApiAllowed(base),
        { fallbackValue: false, deps: [base, cspSignal] }
    );

    async function allowApiDomain() {
        const res = await VencordNative.csp.requestAddOverride(
            base,
            ["connect-src", "img-src"],
            "BadgeNetwork"
        );
        if (res !== "ok") {
            notify(
                res === "cancelled" || res === "unchecked"
                    ? "Autorisation CSP annulée"
                    : `CSP: ${res}`,
                "critical"
            );
            return;
        }
        bumpCsp();
        promptToRestart("relaunch");
    }

    async function refresh() {
        if (!apiToken) {
            setBadges([]);
            setUsername(null);
            return;
        }
        if (!cspAllowed) {
            setStatus("Autorise d'abord le domaine API (bouton ci-dessus), puis redémarre Discord.");
            return;
        }
        setBusy(true);
        try {
            const me = await fetchMe(base, apiToken);
            setUsername(me.username);
            const list = await fetchMyBadges(base, apiToken);
            setBadges(list);
            setCachedBadges(me.id, list.filter(b => b.enabled));
            setStatus("Bibliothèque synchronisée depuis le serveur.");
        } catch (e: any) {
            setStatus(e?.message || "Erreur sync");
            notify("Token invalide ou API injoignable", "critical");
        } finally {
            setBusy(false);
        }
    }

    useEffect(() => {
        void refresh();
    }, [apiToken, apiBase, cspAllowed]);

    async function persist(next: ServerBadge[], message = "Badges enregistrés sur le serveur") {
        if (!apiToken) {
            notify("Connecte-toi d'abord (token API)", "critical");
            return;
        }
        setBusy(true);
        try {
            const saved = await saveMyBadges(base, apiToken, next);
            setBadges(saved);
            const me = await fetchMe(base, apiToken);
            invalidateCache();
            setCachedBadges(me.id, saved.filter(b => b.enabled));
            notify(message, "success");
            setStatus(message);
        } catch (e: any) {
            notify(e?.message || "Erreur sauvegarde", "critical");
            setStatus(e?.message || "Erreur sauvegarde");
        } finally {
            setBusy(false);
        }
    }

    async function createBadge() {
        if (!cspAllowed) {
            notify("Autorise le domaine API d'abord", "critical");
            return;
        }
        if (!apiToken) {
            notify("Colle ton token API d'abord", "critical");
            return;
        }
        const trimmed = name.trim();
        if (!trimmed) {
            notify("Donne un nom au badge", "critical");
            return;
        }
        if (!file) {
            notify("Choisis une image / gif", "critical");
            return;
        }
        if (badges.length >= 25) {
            notify("Maximum 25 badges en bibliothèque", "critical");
            return;
        }

        setBusy(true);
        try {
            const iconUrl = await uploadBadgeIcon(base, apiToken, file);
            const next: ServerBadge[] = [
                ...badges,
                { id: newId(), name: trimmed, iconUrl, enabled: false },
            ];
            await persist(next, "Badge créé et stocké sur le serveur");
            setName("");
            setFile(null);
        } catch (e: any) {
            notify(e?.message || "Création échouée", "critical");
        } finally {
            setBusy(false);
        }
    }

    function toggleBadge(id: string) {
        const current = badges.find(b => b.id === id);
        if (!current) return;
        const enabling = !current.enabled;
        const enabledCount = badges.filter(b => b.enabled).length;
        if (enabling && enabledCount >= 10) {
            notify("Maximum 10 badges affichés", "critical");
            return;
        }
        const next = badges.map(b => b.id === id ? { ...b, enabled: enabling } : b);
        void persist(next, enabling ? "Badge affiché sur ton profil" : "Badge retiré du profil");
    }

    function removeBadge(id: string) {
        const next = badges.filter(b => b.id !== id);
        void persist(next, "Badge supprimé du serveur");
    }

    return (
        <div style={{ display: "flex", flexDirection: "column", gap: 12 }}>
            <Heading>Badge Network</Heading>
            <Paragraph>
                1) Autorise le domaine → 2) Connecte-toi → 3) colle le token → 4) crée tes badges.
                Stockage : <code>{base}</code>
            </Paragraph>

            {!cspAllowed && (
                <ErrorCard>
                    <Heading className={Margins.bottom8}>Domaine API bloqué (CSP)</Heading>
                    <Paragraph>
                        Discord bloque les requêtes vers <code>{base}</code>. Clique Autoriser,
                        coche la case de confiance, puis <b>ferme Discord complètement</b> et rouvre-le.
                    </Paragraph>
                    <Button className={Margins.top8} onClick={() => void allowApiDomain()}>
                        Autoriser {new URL(base).host}
                    </Button>
                </ErrorCard>
            )}

            <div style={{ display: "flex", gap: 8, flexWrap: "wrap" }}>
                <Button onClick={() => VencordNative.native.openExternal(`${base}/auth/discord`)}>
                    Se connecter (Discord)
                </Button>
                <Button variant="secondary" disabled={busy || !apiToken || !cspAllowed} onClick={() => void refresh()}>
                    Recharger depuis le serveur
                </Button>
            </div>

            {username && <Paragraph>Connecté : <strong>{username}</strong></Paragraph>}
            {status && <Paragraph>{status}</Paragraph>}

            <Heading>Créer un badge</Heading>
            <Paragraph>Nom (tooltip au hover)</Paragraph>
            <TextInput
                value={name}
                placeholder="Ex: Owner Pokedox"
                onChange={setName}
            />

            <Paragraph>Icône / GIF</Paragraph>
            <input
                type="file"
                accept="image/png,image/jpeg,image/gif,image/webp"
                onChange={e => setFile(e.target.files?.[0] ?? null)}
            />

            <Button variant="positive" disabled={busy || !cspAllowed} onClick={() => void createBadge()}>
                Créer & uploader sur le serveur
            </Button>

            <Heading>Mes badges ({badges.length})</Heading>
            <Paragraph>Choisis ceux à afficher sur ton profil (max 10).</Paragraph>

            <div style={{ display: "flex", flexDirection: "column", gap: 10 }}>
                {badges.length === 0 && <Paragraph>Aucun badge pour l’instant.</Paragraph>}
                {badges.map(badge => (
                    <div
                        key={badge.id}
                        style={{
                            display: "flex",
                            alignItems: "center",
                            gap: 10,
                            padding: 8,
                            borderRadius: 8,
                            background: "var(--background-secondary)",
                        }}
                    >
                        <img
                            src={badge.iconUrl}
                            alt={badge.name}
                            width={24}
                            height={24}
                            style={{ borderRadius: 4, objectFit: "cover" }}
                        />
                        <div style={{ flex: 1 }}>
                            <div style={{ fontWeight: 600 }}>{badge.name}</div>
                            <div style={{ opacity: 0.7, fontSize: 12 }}>
                                {badge.enabled ? "Affiché sur le profil" : "Masqué"}
                            </div>
                        </div>
                        <Button
                            size="small"
                            variant={badge.enabled ? "secondary" : "positive"}
                            disabled={busy}
                            onClick={() => toggleBadge(badge.id)}
                        >
                            {badge.enabled ? "Masquer" : "Afficher"}
                        </Button>
                        <Button
                            size="small"
                            variant="dangerPrimary"
                            disabled={busy}
                            onClick={() => removeBadge(badge.id)}
                        >
                            Suppr.
                        </Button>
                    </div>
                ))}
            </div>
        </div>
    );
}
