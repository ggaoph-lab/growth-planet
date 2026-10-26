import { duck } from "./sound";

export type SpeakAccent = "uk" | "us" | "zh";

let current: HTMLAudioElement | null = null;
let token = 0;

/** In-memory neural clips so the same phrase does not hit /kid/tts twice. */
const neuralCache = new Map<string, string>();
/** Avoid HEAD-ing the same missing ElevenLabs phrase file on every tap. */
const missingUrls = new Set<string>();
const presentUrls = new Set<string>();

export function stopAudio() {
  token++;
  current?.pause();
  current = null;
  if ("speechSynthesis" in window) window.speechSynthesis.cancel();
}

function langOf(accent: SpeakAccent) {
  if (accent === "zh") return "zh-CN";
  if (accent === "uk") return "en-GB";
  return "en-US";
}

/** Kid TTS API only accepts zh-CN / en-US. */
function apiLangOf(accent: SpeakAccent) {
  return accent === "zh" ? "zh-CN" : "en-US";
}

function readCookie(name: string) {
  const match = document.cookie.split("; ").find((c) => c.startsWith(`${name}=`));
  return match ? decodeURIComponent(match.slice(name.length + 1)) : null;
}

async function ensureCsrf() {
  if (readCookie("XSRF-TOKEN")) return;
  await fetch("/sanctum/csrf-cookie", { credentials: "same-origin", headers: { Accept: "application/json" } });
}

async function resolveRecording(url: string | null | undefined): Promise<string | null> {
  if (!url) return null;
  if (missingUrls.has(url)) return null;
  if (presentUrls.has(url)) return url;
  // Word / sentence clips are flagged in the index — trust them.
  if (!url.includes("/audio/phrase/")) {
    presentUrls.add(url);
    return url;
  }
  try {
    const res = await fetch(url, { method: "HEAD", cache: "force-cache" });
    if (res.ok) {
      presentUrls.add(url);
      return url;
    }
  } catch {
    /* fall through */
  }
  missingUrls.add(url);
  return null;
}

/**
 * When Word Atlas is embedded as 雅思星球, reuse Growth Planet's neural TTS
 * (Qwen) — much closer to ElevenLabs than the browser voice until phrase clips are forged.
 */
async function fetchNeuralDataUrl(text: string, accent: SpeakAccent): Promise<string | null> {
  if (!import.meta.env.BASE_URL.includes("/ielts")) return null;
  const key = `${apiLangOf(accent)}:${text}`;
  const hit = neuralCache.get(key);
  if (hit) return hit;
  try {
    await ensureCsrf();
    const xsrf = readCookie("XSRF-TOKEN");
    const res = await fetch("/api/v1/kid/tts", {
      method: "POST",
      credentials: "same-origin",
      headers: {
        Accept: "application/json",
        "Content-Type": "application/json",
        "X-Requested-With": "XMLHttpRequest",
        ...(xsrf ? { "X-XSRF-TOKEN": xsrf } : {}),
      },
      body: JSON.stringify({ text, lang: apiLangOf(accent) }),
    });
    if (!res.ok) return null;
    const body = (await res.json()) as {
      data?: { available?: boolean; audio_base64?: string | null; mime?: string | null };
    };
    const data = body.data;
    if (!data?.available || !data.audio_base64 || !data.mime) return null;
    const url = `data:${data.mime};base64,${data.audio_base64}`;
    neuralCache.set(key, url);
    return url;
  } catch {
    return null;
  }
}

function pickVoice(lang: string) {
  const voices = speechSynthesis.getVoices();
  if (!voices.length) return null;
  const want = lang.replace("_", "-").toLowerCase();
  const prefix = want.slice(0, 2);
  const matches = voices.filter((v) => {
    const l = v.lang.replace("_", "-").toLowerCase();
    return l === want || l.startsWith(`${prefix}-`) || l === prefix;
  });
  const pool = matches.length ? matches : voices;
  return (
    pool.find((v) => /premium|enhanced|natural|google|siri|tingting|xiaoxiao|yaoyao|huihui/i.test(v.name)) ||
    pool[0] ||
    null
  );
}

async function playElement(src: string, rate: number, mine: number) {
  const a = new Audio(src);
  a.playbackRate = rate;
  current = a;
  await a.play();
  await new Promise<void>((r) => {
    a.onended = () => r();
    a.onpause = () => r();
    a.onerror = () => r();
  });
  return mine === token ? ("played" as const) : ("stopped" as const);
}

/** Play a recording, then neural TTS (雅思星球), then the device voice. */
export async function play(url: string | null | undefined, text: string, accent: SpeakAccent = "uk", rate = 1) {
  duck(true);
  try {
    return await speak(url, text, accent, rate);
  } finally {
    duck(false);
  }
}

async function speak(url: string | null | undefined, text: string, accent: SpeakAccent, rate: number) {
  stopAudio();
  const mine = ++token;

  const recording = await resolveRecording(url);
  if (mine !== token) return "stopped" as const;
  if (recording) {
    try {
      const result = await playElement(recording, rate, mine);
      if (result === "stopped") return "stopped" as const;
      return "recorded" as const;
    } catch {
      if (mine !== token) return "stopped" as const;
      missingUrls.add(recording);
      presentUrls.delete(recording);
      current = null;
    }
  }

  if (text.trim()) {
    const neural = await fetchNeuralDataUrl(text.trim(), accent);
    if (mine !== token) return "stopped" as const;
    if (neural) {
      try {
        await playElement(neural, rate, mine);
        return "neural" as const;
      } catch {
        if (mine !== token) return "stopped" as const;
        current = null;
      }
    }
  }

  if (!("speechSynthesis" in window)) return "unsupported" as const;
  const u = new SpeechSynthesisUtterance(text);
  u.lang = langOf(accent);
  u.rate = rate * (accent === "zh" ? 1 : 0.95);
  const best = pickVoice(u.lang);
  if (best) u.voice = best;
  await new Promise<void>((r) => {
    u.onend = () => r();
    u.onerror = () => r();
    speechSynthesis.speak(u);
  });
  return "device" as const;
}
