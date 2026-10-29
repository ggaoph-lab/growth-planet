"""Publish live static progress JSON for the local IELTS audio build."""
import datetime
import json
import pathlib
import re
import time
from gen_phrase_audio import ROOT, collect_jobs

JOBS = collect_jobs()
OUTPUTS = [path for _, _, path in JOBS]
TARGETS = [ROOT / 'public' / 'audio-progress.json', ROOT / 'dist' / 'audio-progress.json']
LOGS = pathlib.Path('/private/tmp/atlas-kokoro-logs')

while True:
    done = sum(path.is_file() for path in OUTPUTS)
    failures = sum(len(re.findall(r'^FAIL ', log.read_text(), re.M)) for log in LOGS.glob('shard-*.log'))
    payload = json.dumps({'done': done, 'total': len(OUTPUTS), 'failures': failures,
                          'updated_at': datetime.datetime.now(datetime.UTC).isoformat()})
    for target in TARGETS:
        if target.parent.exists():
            part = target.with_suffix('.json.part')
            part.write_text(payload)
            part.replace(target)
    if done == len(OUTPUTS):
        break
    time.sleep(10)
