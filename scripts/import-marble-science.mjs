#!/usr/bin/env node
/**
 * Trim Marble Skill Taxonomy (os-taxonomy) to Science topics + intra-Science edges.
 * Usage:
 *   node scripts/import-marble-science.mjs [--src=/path/to/os-taxonomy/data]
 *   node scripts/import-marble-science.mjs --download
 */
import { mkdir, readFile, writeFile } from 'node:fs/promises'
import { dirname, join, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const __dirname = dirname(fileURLToPath(import.meta.url))
const root = resolve(__dirname, '..')
const outDir = join(root, 'backend/app/Modules/Encyclopedia/data/marble')
const RAW = {
  topics: 'https://raw.githubusercontent.com/withmarbleapp/os-taxonomy/main/data/topics.json',
  dependencies: 'https://raw.githubusercontent.com/withmarbleapp/os-taxonomy/main/data/dependencies.json',
  manifest: 'https://raw.githubusercontent.com/withmarbleapp/os-taxonomy/main/data/manifest.json',
}

function argValue(name, fallback = null) {
  const hit = process.argv.find((a) => a.startsWith(`${name}=`))
  return hit ? hit.slice(name.length + 1) : fallback
}

async function loadJson(pathOrUrl, isUrl) {
  if (isUrl) {
    const res = await fetch(pathOrUrl)
    if (!res.ok) throw new Error(`HTTP ${res.status} for ${pathOrUrl}`)
    return res.json()
  }
  return JSON.parse(await readFile(pathOrUrl, 'utf8'))
}

const src = argValue('--src')
const download = process.argv.includes('--download')
const base = src ? resolve(src) : join('/tmp/marble-os-taxonomy')

const topicsPayload = await loadJson(download ? RAW.topics : join(base, 'topics.json'), download)
const depsPayload = await loadJson(download ? RAW.dependencies : join(base, 'dependencies.json'), download)
let manifest = null
try {
  manifest = await loadJson(download ? RAW.manifest : join(base, 'manifest.json'), download)
} catch {
  /* optional */
}

const science = (topicsPayload.topics ?? []).filter((t) => t.subject === 'Science')
const scienceIds = new Set(science.map((t) => t.id))
const dependencies = (depsPayload.dependencies ?? []).filter(
  (d) => scienceIds.has(d.topicId) && scienceIds.has(d.prerequisiteId),
)

await mkdir(outDir, { recursive: true })

const outTopics = {
  version: topicsPayload.version ?? manifest?.version ?? 'v1',
  source: 'Marble Skill Taxonomy (os-taxonomy)',
  subject: 'Science',
  topicCount: science.length,
  topics: science.map((t) => ({
    id: t.id,
    type: t.type,
    subject: t.subject,
    domain: t.domain,
    name: t.name,
    description: t.description,
    ageRangeStart: t.ageRangeStart,
    ageRangeEnd: t.ageRangeEnd,
    centrality: t.centrality ?? 0,
  })),
}

const outDeps = {
  version: depsPayload.version ?? outTopics.version,
  source: 'Marble Skill Taxonomy (os-taxonomy)',
  subject: 'Science',
  dependencyCount: dependencies.length,
  dependencies,
}

await writeFile(join(outDir, 'topics.science.json'), `${JSON.stringify(outTopics, null, 2)}\n`)
await writeFile(join(outDir, 'dependencies.science.json'), `${JSON.stringify(outDeps, null, 2)}\n`)

const notice = `Marble Skill Taxonomy (v1)
© Generative Spark, Inc. (Marble) · https://withmarble.com
Source: https://github.com/withmarbleapp/os-taxonomy

Database licensed under ODbL 1.0.
Textual content authored by Marble licensed under CC BY-SA 4.0.

Attribution required for any use:
Marble Skill Taxonomy (v1) · © Generative Spark, Inc. (Marble) · https://withmarble.com · licensed under ODbL 1.0 (database) and CC BY-SA 4.0 (content).

This directory contains a Science-only extract used inside Growth Planet as a produced work.
Chinese kid-facing overlays in zh-overlay.json are Growth Planet adaptations; credit Marble for the underlying taxonomy.
`

await writeFile(join(outDir, 'NOTICE'), notice)

console.log(`Wrote ${science.length} Science topics and ${dependencies.length} edges to ${outDir}`)
