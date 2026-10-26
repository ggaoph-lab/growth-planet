"""Synthesize collocation + Chinese example audio with ElevenLabs (same Alice/Sarah as words).

EN phrases use the UK Alice voice (matches everyday example sentences).
Chinese uses Alice + language_code=zh on the same turbo model.

Audio is content-addressed: public path audio/phrase/{en|zh}/{fnv1a8}.mp3
so shared phrases are only billed once.

Usage:
  python3 scripts/atlas/gen_phrase_audio.py [--workers 4] [--floor 4000] [--limit 200]
  python3 scripts/atlas/gen_phrase_audio.py --kind zh   # Chinese only
  python3 scripts/atlas/gen_phrase_audio.py --kind en

Requires ELEVENLABS_API_KEY (see common.load_env / ATLAS_SECRETS).
After generation, run: python3 scripts/atlas/build_atlas.py
"""
from __future__ import annotations

import argparse
import concurrent.futures as cf
import json
import threading
import time

import unicodedata

from common import FORGE, eleven_keys, load_env, with_retry
from gen_word_audio import VOICES, credits_left

# Reuse Alice for Chinese so the atlas keeps one house voice; language_code switches locale.
ZH_VOICE = VOICES["uk"]
MODEL_LANG = {"en": "en", "zh": "zh"}


def fnv1a8(text: str) -> str:
    h = 2166136261
    for ch in unicodedata.normalize("NFC", text.strip()):
        h ^= ord(ch)
        h = (h * 16777619) & 0xFFFFFFFF
    return f"{h:08x}"


def collect_jobs(kind: str):
    """Unique (kind, text, outpath) from forged text entries."""
    seen = set()
    jobs = []
    text_dir = FORGE / "text"
    if not text_dir.exists():
        raise SystemExit(f"missing {text_dir}; generate entry text first")
    for p in sorted(text_dir.glob("*.json")):
        try:
            rows = json.loads(p.read_text())
        except Exception:
            continue
        for e in rows:
            if kind in ("en", "all"):
                for col in e.get("col") or []:
                    en = (col.split("｜")[0] if "｜" in col else col.split("|")[0]).strip()
                    if en and ("en", en) not in seen:
                        seen.add(("en", en))
                        out = FORGE / "audio" / "phrase" / "en" / f"{fnv1a8(en)}.mp3"
                        jobs.append(("en", en, out))
            if kind in ("zh", "all"):
                for col in e.get("col") or []:
                    parts = col.replace("|", "｜").split("｜")
                    zh = parts[1].strip() if len(parts) > 1 else ""
                    if zh and ("zh", zh) not in seen:
                        seen.add(("zh", zh))
                        out = FORGE / "audio" / "phrase" / "zh" / f"{fnv1a8(zh)}.mp3"
                        jobs.append(("zh", zh, out))
                for ex in e.get("ex") or []:
                    zh = (ex.get("zh") or "").strip()
                    if zh and ("zh", zh) not in seen:
                        seen.add(("zh", zh))
                        out = FORGE / "audio" / "phrase" / "zh" / f"{fnv1a8(zh)}.mp3"
                        jobs.append(("zh", zh, out))
    return jobs


def synth_lang(key, voice, text, out, lang_code):
    """Like gen_word_audio.synth but with an explicit language_code."""
    import urllib.request

    payload = json.dumps({"text": text, "model_id": "eleven_v4_turbo", "language_code": lang_code}).encode()
    req = urllib.request.Request(
        f"https://api.elevenlabs.io/v1/text-to-speech/{voice}?output_format=mp3_44100_64",
        data=payload,
        headers={"xi-api-key": key, "Content-Type": "application/json"},
    )

    def call():
        with urllib.request.urlopen(req, timeout=60) as r:
            data = r.read()
        if len(data) < 2000:
            raise ValueError("audio too small")
        return data

    data = with_retry(call, tries=5, base=3)
    out.parent.mkdir(parents=True, exist_ok=True)
    tmp = out.with_suffix(".part")
    tmp.write_bytes(data)
    tmp.rename(out)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--workers", type=int, default=4)
    ap.add_argument("--floor", type=int, default=4000)
    ap.add_argument("--limit", type=int, default=0, help="cap new jobs (0 = all missing)")
    ap.add_argument("--kind", choices=("all", "en", "zh"), default="all")
    a = ap.parse_args()
    keys = eleven_keys(load_env())
    if not keys:
        raise SystemExit("no ElevenLabs keys — put elevenlabs.key in ATLAS_SECRETS or set ELEVENLABS_API_KEY")
    jobs = [j for j in collect_jobs(a.kind) if not j[2].exists()]
    if a.limit:
        jobs = jobs[: a.limit]
    print(f"jobs={len(jobs)} credits={credits_left(keys[0])}", flush=True)
    if not jobs:
        print("nothing to do", flush=True)
        return

    for sub in ("en", "zh"):
        (FORGE / "audio" / "phrase" / sub).mkdir(parents=True, exist_ok=True)

    stop = threading.Event()
    stats = {"ok": 0, "fail": 0}
    lock = threading.Lock()
    alive = list(keys)

    def work(job):
        if stop.is_set() or not alive:
            return
        kind, text, out = job
        key = alive[hash(text) % len(alive)]
        voice = VOICES["uk"] if kind == "en" else ZH_VOICE
        # EN phrases: end with a period so Alice reads them as a short utterance.
        spoken = text if kind == "zh" else (text if text.endswith((".", "!", "?")) else text + ".")
        try:
            synth_lang(key, voice, spoken, out, MODEL_LANG[kind])
            with lock:
                stats["ok"] += 1
                n = stats["ok"]
            if n % 100 == 0:
                left = credits_left(key)
                print(f"{time.strftime('%H:%M:%S')} ok={n} fail={stats['fail']} credits={left}", flush=True)
                if left < a.floor:
                    stop.set()
                    print("credit floor reached", flush=True)
        except Exception as ex:
            with lock:
                stats["fail"] += 1
            print("FAIL", text[:40], str(ex)[:120], flush=True)

    with cf.ThreadPoolExecutor(a.workers) as pool:
        list(pool.map(work, jobs))
    print("finished", stats, flush=True)


if __name__ == "__main__":
    main()
