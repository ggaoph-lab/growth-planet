/**
 * Bridge to Growth Planet when Word Atlas is embedded as 雅思星球 (/ielts/).
 * The kid is already signed in (Sanctum cookie), so same-origin fetch is enough.
 */
import type { ActiveSession, Progress, Settings } from "../store/store";

const baseUrl =
  typeof import.meta !== "undefined" &&
  import.meta.env &&
  typeof import.meta.env.BASE_URL === "string"
    ? import.meta.env.BASE_URL
    : "/";
export const inGrowthPlanet = baseUrl.includes("/ielts");

export interface SessionReward {
  enabled: boolean;
  settled: boolean;
  points: number;
  coins: number;
  accuracy: number;
  mastered_points: number;
  mastered_words: number;
  capped: boolean;
  remaining: { points: number; coins: number };
}

export interface CloudLearningState {
  settings: unknown;
  progress: unknown;
  updated_at: string | null;
}

function readCookie(name: string) {
  const match = document.cookie.split("; ").find((c) => c.startsWith(`${name}=`));
  return match ? decodeURIComponent(match.slice(name.length + 1)) : null;
}

async function ensureCsrf() {
  if (readCookie("XSRF-TOKEN")) return;
  await fetch("/sanctum/csrf-cookie", { credentials: "same-origin", headers: { Accept: "application/json" } });
}

async function api<T>(method: string, path: string, body?: unknown): Promise<T | null> {
  if (!inGrowthPlanet) return null;
  try {
    await ensureCsrf();
    const xsrf = readCookie("XSRF-TOKEN");
    const res = await fetch(path, {
      method,
      credentials: "same-origin",
      headers: {
        Accept: "application/json",
        "Content-Type": "application/json",
        "X-Requested-With": "XMLHttpRequest",
        ...(xsrf ? { "X-XSRF-TOKEN": xsrf } : {}),
      },
      body: body === undefined ? undefined : JSON.stringify(body),
    });
    if (!res.ok) return null;
    const json = (await res.json()) as { data?: T };
    return (json.data ?? null) as T | null;
  } catch {
    return null;
  }
}

/**
 * Settle a finished session for points / coins. Idempotent on the server (one payout per session id),
 * so calling again after a reload is safe. Resolves null when not embedded or the request fails.
 */
export async function settleSession(session: ActiveSession): Promise<SessionReward | null> {
  if (!inGrowthPlanet) return null;
  try {
    await ensureCsrf();
    const xsrf = readCookie("XSRF-TOKEN");
    const res = await fetch("/api/v1/kid/ielts/sessions/settle", {
      method: "POST",
      credentials: "same-origin",
      headers: {
        Accept: "application/json",
        "Content-Type": "application/json",
        "X-Requested-With": "XMLHttpRequest",
        "Idempotency-Key": `ielts-${session.id}`,
        ...(xsrf ? { "X-XSRF-TOKEN": xsrf } : {}),
      },
      body: JSON.stringify({
        session_id: session.id,
        graded: session.graded,
        new_ids: session.newIds,
        mastered_ids: session.mastered ?? [],
      }),
    });
    if (!res.ok) return null;
    const body = (await res.json()) as { data?: SessionReward };
    return body.data ?? null;
  } catch {
    return null;
  }
}

export async function pullLearningState(): Promise<CloudLearningState | null> {
  return api<CloudLearningState>("GET", "/api/v1/kid/ielts/state");
}

export async function pushLearningState(settings: Settings, progress: Progress): Promise<boolean> {
  const data = await api<CloudLearningState>("PUT", "/api/v1/kid/ielts/state", {
    settings,
    // Active session is device-local; cloud keeps durable path + card states.
    progress: { ...progress, session: null },
  });
  return data !== null;
}
