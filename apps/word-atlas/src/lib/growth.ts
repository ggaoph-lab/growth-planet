/**
 * Bridge to Growth Planet when Word Atlas is embedded as 雅思星球 (/ielts/).
 * The kid is already signed in (Sanctum cookie), so same-origin fetch is enough.
 */
import type { ActiveSession } from "../store/store";

export const inGrowthPlanet = import.meta.env.BASE_URL.includes("/ielts");

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

function readCookie(name: string) {
  const match = document.cookie.split("; ").find((c) => c.startsWith(`${name}=`));
  return match ? decodeURIComponent(match.slice(name.length + 1)) : null;
}

async function ensureCsrf() {
  if (readCookie("XSRF-TOKEN")) return;
  await fetch("/sanctum/csrf-cookie", { credentials: "same-origin", headers: { Accept: "application/json" } });
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
