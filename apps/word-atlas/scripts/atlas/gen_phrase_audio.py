"""Generate static IELTS collocation and Chinese example MP3s from public entries.

English uses ElevenLabs Sarah; Chinese uses Qwen Cherry (Mandarin) via the backend.

Run from apps/word-atlas: SSL_CERT_FILE=/etc/ssl/cert.pem python3 scripts/atlas/gen_phrase_audio.py --limit 4
Then rerun without --limit as credits permit. Existing clips are skipped. No key is stored in the output.
"""
from __future__ import annotations

import argparse
import json
import os
import pathlib
import subprocess
import unicodedata
import urllib.request

from common import ROOT, load_env, with_retry
from gen_word_audio import VOICES

ENTRIES = ROOT / 'public' / 'atlas' / 'entries'
OUTPUT = ROOT / 'public' / 'atlas' / 'audio' / 'phrase'
MODEL = 'eleven_flash_v2_5'


def phrase_key(text: str) -> str:
    """Match JavaScript phraseKey's UTF-16 code units, including emoji."""
    h = 2166136261
    raw = unicodedata.normalize('NFC', text.strip()).encode('utf-16-le')
    for i in range(0, len(raw), 2):
        h ^= raw[i] | raw[i + 1] << 8
        h = (h * 16777619) & 0xffffffff
    return f'{h:08x}'


def collect_jobs(kind: str = 'all'):
    jobs: dict[tuple[str, str], pathlib.Path] = {}
    for path in sorted(ENTRIES.glob('*.json')):
        for entry in json.loads(path.read_text()):
            if kind in ('all', 'zh'):
                for example in entry.get('ex') or []:
                    zh = (example.get('zh') or '').strip()
                    if zh:
                        jobs['zh', zh] = OUTPUT / 'zh' / f'{phrase_key(zh)}.mp3'
            for pair in entry.get('col') or []:
                parts = pair.replace('|', '｜').split('｜')
                en = parts[0].strip()
                zh = parts[1].strip() if len(parts) > 1 else ''
                if en and kind in ('all', 'en'):
                    jobs['en', en] = OUTPUT / 'en' / f'{phrase_key(en)}.mp3'
                if zh and kind in ('all', 'zh'):
                    jobs['zh', zh] = OUTPUT / 'zh' / f'{phrase_key(zh)}.mp3'
    if not jobs:
        raise SystemExit(f'No entries found in {ENTRIES}')
    return [(language, text, out) for (language, text), out in jobs.items()]


def key_from_local_env() -> str:
    key = os.environ.get('ELEVENLABS_API_KEY') or load_env().get('ELEVENLABS_API_KEY', '')
    if key:
        return key
    env_path = ROOT.parents[1] / '.env.local'
    if env_path.exists():
        for line in env_path.read_text().splitlines():
            if line.startswith('ELEVENLABS_API_KEY='):
                return line.partition('=')[2].strip().strip('"\'')
    raise SystemExit('ElevenLabs key missing; set ELEVENLABS_API_KEY in the environment')


def generate(key: str, kind: str, text: str, out: pathlib.Path):
    spoken = text if text.endswith(('.', '!', '?')) else text + '.'
    payload = {'text': spoken, 'model_id': MODEL, 'language_code': 'en',
               'voice_settings': {'stability': 0.65, 'similarity_boost': 0.8, 'style': 0}}
    request = urllib.request.Request(
        f'https://api.elevenlabs.io/v1/text-to-speech/{VOICES["us"]}?output_format=mp3_44100_64',
        data=json.dumps(payload).encode(),
        headers={'xi-api-key': key, 'Content-Type': 'application/json', 'Accept': 'audio/mpeg'},
    )
    def fetch():
        with urllib.request.urlopen(request, timeout=60) as response:
            audio = response.read()
        if len(audio) < 2000 or audio[:3] != b'ID3' and audio[:2] != b'\xff\xfb':
            raise ValueError('Invalid or very short MP3 response')
        return audio
    audio = with_retry(fetch, tries=3, base=2)
    out.parent.mkdir(parents=True, exist_ok=True)
    part = out.with_suffix('.part')
    part.write_bytes(audio)
    part.replace(out)


def remaining_credits(key: str) -> int:
    request = urllib.request.Request('https://api.elevenlabs.io/v1/user/subscription',
                                     headers={'xi-api-key': key})
    with urllib.request.urlopen(request, timeout=20) as response:
        subscription = json.load(response)
    return max(0, int(subscription['character_limit']) - int(subscription['character_count']))


class MandarinWorker:
    def __init__(self, family_id: int):
        self.family_id = family_id
        self.process = subprocess.Popen(
            ['docker', 'compose', 'exec', '-T', 'app', 'php', 'scripts/atlas_tts_worker.php'],
            cwd=ROOT.parents[1], stdin=subprocess.PIPE, stdout=subprocess.PIPE, text=True,
        )

    def generate(self, text: str, out: pathlib.Path):
        self.process.stdin.write(json.dumps({'family_id': self.family_id, 'text': text}, ensure_ascii=False)+'\n')
        self.process.stdin.flush()
        response = json.loads(self.process.stdout.readline())
        if response.get('error'):
            raise RuntimeError(response['error'])
        import base64
        audio = base64.b64decode(response['audio_base64'], validate=True)
        if len(audio) < 2000:
            raise ValueError('Invalid Mandarin MP3')
        out.parent.mkdir(parents=True, exist_ok=True)
        part = out.with_suffix('.part')
        part.write_bytes(audio)
        part.replace(out)

    def close(self):
        self.process.stdin.close()
        self.process.wait(timeout=10)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--kind', choices=('all', 'en', 'zh'), default='all')
    parser.add_argument('--limit', type=int, default=0, help='Maximum new clips this run; 0 means all')
    parser.add_argument('--dry-run', action='store_true')
    parser.add_argument('--credit-floor', type=int, default=500,
                        help='Keep at least this many account credits unused')
    parser.add_argument('--family-id', type=int, default=1,
                        help='Family whose configured Qwen key generates Mandarin')
    args = parser.parse_args()
    jobs = collect_jobs(args.kind)
    pending = [job for job in jobs if not job[2].exists()]
    print(f'total={len(jobs)} existing={len(jobs)-len(pending)} pending={len(pending)} estimated_characters={sum(len(t) for _,t,_ in pending)}', flush=True)
    if args.dry_run:
        return
    if args.limit:
        pending = pending[:args.limit]
    key = key_from_local_env() if any(job[0] == 'en' for job in pending) else ''
    budget = max(0, remaining_credits(key) - args.credit_floor) if key else 0
    selected = []
    for job in pending:
        cost_ceiling = len(job[1]) + (0 if job[0] == 'zh' or job[1].endswith(('.', '!', '?')) else 1) if job[0] == 'en' else 0
        if cost_ceiling > budget:
            break
        selected.append(job)
        budget -= cost_ceiling
    pending = selected
    print(f'queued_with_credit_floor={len(pending)}', flush=True)
    failures = 0
    mandarin = None
    for index, (kind, text, out) in enumerate(pending, 1):
        try:
            if kind == 'zh':
                if mandarin is None:
                    mandarin = MandarinWorker(args.family_id)
                mandarin.generate(text, out)
            else:
                generate(key, kind, text, out)
        except Exception as error:
            failures += 1
            print(f'FAIL {kind} {out.name}: {str(error)[:120]}', flush=True)
            if failures >= 3:
                break
        if index % 100 == 0:
            print(f'done={index} failures={failures}', flush=True)
    print(f'finished attempted={index if pending else 0} failures={failures}', flush=True)
    if mandarin is not None:
        mandarin.close()


if __name__ == '__main__':
    main()
