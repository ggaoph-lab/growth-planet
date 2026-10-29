"""Generate static IELTS phrase MP3s locally with Apache-2.0 Kokoro-82M-v1.1-zh.

Run from apps/word-atlas with kokoro, misaki[zh] and soundfile installed:
  python scripts/atlas/gen_phrase_audio_local.py --limit 4 --output /tmp/atlas-voice-check
  python scripts/atlas/gen_phrase_audio_local.py
The full run resumes missing clips in public/atlas/audio/phrase without paid APIs.
"""
from __future__ import annotations
import argparse
import hashlib
import os
import pathlib
import time
from gen_phrase_audio import OUTPUT, collect_jobs

MODEL = 'hexgrad/Kokoro-82M-v1.1-zh'
VOICES = {'zh': 'zf_001', 'en': 'af_maple'}

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--kind', choices=('all', 'zh', 'en'), default='all')
    parser.add_argument('--limit', type=int, default=0)
    parser.add_argument('--output', type=pathlib.Path, default=OUTPUT)
    parser.add_argument('--shard-index', type=int, default=0)
    parser.add_argument('--shard-count', type=int, default=1)
    parser.add_argument('--threads', type=int, default=2)
    parser.add_argument('--device', choices=('cpu', 'mps'), default='cpu')
    args = parser.parse_args()
    if args.shard_count < 1 or not 0 <= args.shard_index < args.shard_count:
        parser.error('shard-index must be between 0 and shard-count - 1')
    jobs = []
    for kind, word, original in collect_jobs(args.kind):
        shard = int.from_bytes(hashlib.sha256(f'{kind}\0{word}'.encode()).digest()[:8], 'big') % args.shard_count
        if shard != args.shard_index:
            continue
        target = args.output / kind / original.name
        if not target.exists(): jobs.append((kind, word, target))
    print(f'pending={len(jobs)} output={args.output}', flush=True)
    if args.limit: jobs = jobs[:args.limit]
    if not jobs: return

    from kokoro import KModel, KPipeline
    import numpy as np
    import soundfile as sf
    import torch
    torch.set_num_threads(max(1, args.threads))
    if args.device == 'mps' and not torch.backends.mps.is_available():
        raise SystemExit('Apple GPU is unavailable to this process')
    model = KModel(repo_id=MODEL).to(args.device).eval()
    en = KPipeline(lang_code='a', repo_id=MODEL, model=model)
    def en_phonemes(word): return next(en(word, voice=VOICES['en'])).phonemes
    zh = KPipeline(lang_code='z', repo_id=MODEL, model=model, en_callable=en_phonemes)
    pipelines = {'zh': zh, 'en': en}
    done = failed = 0
    started = time.monotonic()
    for kind, word, target in jobs:
        try:
            target.parent.mkdir(parents=True, exist_ok=True)
            chunks = [audio for _, _, audio in pipelines[kind](word, voice=VOICES[kind])]
            if not chunks: raise RuntimeError('No audio returned')
            part = target.with_suffix('.mp3.part')
            sf.write(part, np.concatenate(chunks), 24000, format='MP3')
            if part.stat().st_size < 2000: raise RuntimeError('MP3 too small')
            part.replace(target)
            done += 1
            if done % 100 == 0: print(f'done={done} failed={failed} elapsed={time.monotonic()-started:.0f}s', flush=True)
        except Exception as error:
            failed += 1
            print(f'FAIL {kind} {target.name}: {error}', flush=True)
            if failed >= 10: break
    print(f'finished done={done} failed={failed} elapsed={time.monotonic()-started:.0f}s', flush=True)
    if failed:
        raise SystemExit(1)

if __name__ == '__main__': main()
