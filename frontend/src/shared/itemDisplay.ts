/** School-bag item label: emoji + Chinese, English underneath. */

export type ItemDisplay = {
  emoji: string
  zh: string
  en: string
}

const EMOJI_RE = /^(?:\p{Extended_Pictographic}|\p{Emoji_Presentation}|\uFE0F|\u200D)+/u

/** Longer keys first so「数学课本」beats「课本」. */
const CATALOG: Array<{ match: string; emoji: string; en: string }> = [
  { match: '数学课本', emoji: '📘', en: 'Math book' },
  { match: '语文课本', emoji: '📕', en: 'Chinese book' },
  { match: '英语课本', emoji: '📗', en: 'English book' },
  { match: '作业本', emoji: '📓', en: 'Exercise book' },
  { match: '练习本', emoji: '📒', en: 'Practice book' },
  { match: '口算本', emoji: '🔢', en: 'Mental math book' },
  { match: '文具盒', emoji: '✏️', en: 'Pencil case' },
  { match: '铅笔盒', emoji: '✏️', en: 'Pencil case' },
  { match: '水彩笔', emoji: '🖍', en: 'Markers' },
  { match: '彩色笔', emoji: '🖍', en: 'Crayons' },
  { match: '书包', emoji: '🎒', en: 'School bag' },
  { match: '校牌', emoji: '🪪', en: 'School ID' },
  { match: '校徽', emoji: '🏅', en: 'School badge' },
  { match: '水杯', emoji: '🥤', en: 'Water bottle' },
  { match: '水壶', emoji: '🧴', en: 'Water bottle' },
  { match: '课本', emoji: '📚', en: 'Textbook' },
  { match: '橡皮', emoji: '🧽', en: 'Eraser' },
  { match: '铅笔', emoji: '✏️', en: 'Pencil' },
  { match: '钢笔', emoji: '🖊', en: 'Pen' },
  { match: '尺子', emoji: '📏', en: 'Ruler' },
  { match: '剪刀', emoji: '✂️', en: 'Scissors' },
  { match: '胶水', emoji: '🧴', en: 'Glue' },
  { match: '胶带', emoji: '📦', en: 'Tape' },
  { match: '雨伞', emoji: '☂️', en: 'Umbrella' },
  { match: '雨衣', emoji: '🧥', en: 'Raincoat' },
  { match: '手套', emoji: '🧤', en: 'Gloves' },
  { match: '帽子', emoji: '🧢', en: 'Hat' },
  { match: '围巾', emoji: '🧣', en: 'Scarf' },
  { match: '饭盒', emoji: '🍱', en: 'Lunch box' },
  { match: '餐盒', emoji: '🍱', en: 'Lunch box' },
  { match: '运动鞋', emoji: '👟', en: 'Sneakers' },
  { match: '球鞋', emoji: '👟', en: 'Sneakers' },
  { match: '跳绳', emoji: '🪢', en: 'Jump rope' },
  { match: '画具', emoji: '🎨', en: 'Art supplies' },
  { match: '画笔', emoji: '🖌', en: 'Paintbrush' },
  { match: '手工', emoji: '🧶', en: 'Craft' },
  { match: '纸巾', emoji: '🧻', en: 'Tissues' },
  { match: '手帕', emoji: '🧻', en: 'Handkerchief' },
  { match: '钥匙', emoji: '🔑', en: 'Keys' },
  { match: '手机', emoji: '📱', en: 'Phone' },
  { match: '平板', emoji: '📱', en: 'Tablet' },
  { match: '眼镜', emoji: '👓', en: 'Glasses' },
  { match: '手表', emoji: '⌚', en: 'Watch' },
  { match: '红领巾', emoji: '🧣', en: 'Red scarf' },
  { match: '衣服', emoji: '👕', en: 'Clothes' },
  { match: '外套', emoji: '🧥', en: 'Jacket' },
]

function stripLeadingEmoji(name: string): { emoji: string; rest: string } {
  const m = name.trim().match(EMOJI_RE)
  if (!m) return { emoji: '', rest: name.trim() }
  return { emoji: m[0], rest: name.trim().slice(m[0].length).trim() }
}

function lookup(zh: string): { emoji: string; en: string } | null {
  const hit = CATALOG.find((row) => zh.includes(row.match))
  return hit ? { emoji: hit.emoji, en: hit.en } : null
}

/** Split「水杯 Water bottle」or「水杯 / Water bottle」style names. */
function splitBilingual(raw: string): { zh: string; en: string } | null {
  const slash = raw.match(/^(.+?)\s*[\/｜|]\s*([A-Za-z].+)$/)
  if (slash?.[1] && slash[2]) return { zh: slash[1].trim(), en: slash[2].trim() }
  const space = raw.match(/^([\u4e00-\u9fff\u3400-\u4dbf\s·\-]+)\s+([A-Za-z][A-Za-z0-9\s'\-]+)$/)
  if (space?.[1] && space[2]) return { zh: space[1].trim(), en: space[2].trim() }
  return null
}

export function itemDisplay(name: string): ItemDisplay {
  const { emoji: leadingEmoji, rest } = stripLeadingEmoji(name || '')
  const bilingual = splitBilingual(rest)
  const zh = bilingual?.zh || rest || name.trim() || '?'
  const catalog = lookup(zh)
  const emoji = leadingEmoji || catalog?.emoji || '📦'
  const en = bilingual?.en || catalog?.en || ''
  return { emoji, zh, en }
}
