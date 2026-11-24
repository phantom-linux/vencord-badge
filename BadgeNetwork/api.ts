export type ServerBadge = {
    id: string;
    name: string;
    iconUrl: string;
    enabled: boolean;
};

export type BadgesResponse = {
    userId: string;
    updatedAt: string | null;
    badges: ServerBadge[];
};

function authHeaders(token: string): HeadersInit {
    return {
        Authorization: `Bearer ${token}`,
    };
}

export async function fetchPublicBadges(apiBase: string, userId: string): Promise<ServerBadge[]> {
    const res = await fetch(`${apiBase}/badges/${userId}`);
    if (!res.ok) throw new Error(`GET badges failed (${res.status})`);
    const data = await res.json() as BadgesResponse;
    return data.badges ?? [];
}

export async function fetchMyBadges(apiBase: string, token: string): Promise<ServerBadge[]> {
    const res = await fetch(`${apiBase}/badges/me`, {
        headers: authHeaders(token),
    });
    if (!res.ok) throw new Error(`GET /badges/me failed (${res.status})`);
    const data = await res.json() as BadgesResponse;
    return data.badges ?? [];
}

export async function saveMyBadges(apiBase: string, token: string, badges: ServerBadge[]): Promise<ServerBadge[]> {
    const res = await fetch(`${apiBase}/badges`, {
        method: "PUT",
        headers: {
            ...authHeaders(token),
            "Content-Type": "application/json",
        },
        body: JSON.stringify({ badges }),
    });
    if (!res.ok) {
        const err = await res.json().catch(() => ({})) as { error?: string };
        throw new Error(err.error || `PUT /badges failed (${res.status})`);
    }
    const data = await res.json() as BadgesResponse;
    return data.badges ?? [];
}

export async function uploadBadgeIcon(apiBase: string, token: string, file: File): Promise<string> {
    // Corps brut (et non multipart) : le serveur lit le flux et peut limiter le débit (10 Mo/s).
    const res = await fetch(`${apiBase}/badges/upload`, {
        method: "POST",
        headers: {
            ...authHeaders(token),
            "Content-Type": file.type || "application/octet-stream",
        },
        body: file,
    });
    if (!res.ok) {
        const err = await res.json().catch(() => ({})) as { error?: string };
        throw new Error(err.error || `Upload failed (${res.status})`);
    }
    const data = await res.json() as { iconUrl: string };
    return data.iconUrl;
}

export async function fetchMe(apiBase: string, token: string): Promise<{ id: string; username: string; }> {
    const res = await fetch(`${apiBase}/auth/me`, {
        headers: authHeaders(token),
    });
    if (!res.ok) throw new Error("Token invalide");
    const data = await res.json() as { user: { id: string; username: string; }; };
    return data.user;
}
