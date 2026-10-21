# Daily English preparation

`english:prepare-day` runs every ten minutes through Laravel's existing scheduler. It prepares active children after 05:00 in each family's timezone, including catch-up after downtime. `--now` allows an initial preparation before 05:00; it still respects deduplication, cooldown and the attempt limit.

The service stores the same durable daily AI records used by the live learning flow: ten adaptive new words, a fairy tale, the first later practice pool, ordering and coaching tips. Speech cache retention is 48 hours so morning audio remains available in the evening. It also warms the existing speech cache for words, meanings, choices and tale lines. It does not cache the live plan response, submit answers, mark listening, unlock reviews or issue rewards. Existing answers remain authoritative.

Each child/date/provider/model has a cache lock and completion marker. A failed attempt can retry after 30 minutes, up to three attempts per day; successful material is reused during those retries. Exceptions for one child do not stop other children. AI fallback remains available when the provider fails. For a prepared day, same-engine fallback retries run only in the background; opening the day reuses fallback immediately. Engine/model changes still follow the existing retry rules. Background AI requests allow up to 45 seconds, while interactive deadlines remain unchanged.

This removes ordinary first-open generation after successful preparation. Extra practice rounds, collected words, engine changes, difficulty changes during the day, expired audio, or an unavailable upstream may still require generation. Preparation does not guarantee every possible later request is instantaneous.

Check `php artisan schedule:list` and run `php artisan english:prepare-day --now` for initial validation. Output reports only child IDs, dates, counts and readiness, with no content or secrets. Do not rerun the command with cache flushing against an active child to force regeneration.

## Word companions

Daily words expose `usage.phrase` and `usage.sentence`, each with English text and Chinese meaning. The daily background task prepares a `word_usage_day` record and warms the companion audio. Plan/answer reads never generate these examples. Common ladder words have reviewed offline examples; a word without either a validated generated example or an offline example has `usage: null` and the next background attempt can fill it. Preparation cache version 2 includes this requirement.

A correct new-word answer pauses on the companion phrase and sentence with explicit continue and audio controls. Companions do not create learning answers or rewards, and do not consume the ten-new-word allowance. Word, phrase and sentence cards have separate labels; a multiword expression cannot count as one daily new word.
