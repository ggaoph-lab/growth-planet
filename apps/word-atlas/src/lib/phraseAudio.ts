/** Content-addressed phrase / Chinese clips (local Kokoro forge → public/atlas/audio/phrase/). */

const BASE = import.meta.env.BASE_URL.replace(/\/$/, "");

/** FNV-1a 32-bit → 8 hex chars; must match scripts/atlas/gen_phrase_audio.py. */
export function phraseKey(text: string) {
  let h = 2166136261;
  const s = text.normalize("NFC").trim();
  for (let i = 0; i < s.length; i++) {
    h ^= s.charCodeAt(i);
    h = Math.imul(h, 16777619);
  }
  return (h >>> 0).toString(16).padStart(8, "0");
}

/** URL for a forged phrase clip; play() falls through to neural/device if the file is missing. */
export function phraseAudioUrl(text: string, kind: "en" | "zh") {
  const t = text.trim();
  if (!t) return null;
  return `${BASE}/atlas/audio/phrase/${kind}/${phraseKey(t)}.mp3?v=1dd9da12`;
}
