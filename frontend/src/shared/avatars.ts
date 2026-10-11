/** Built-in avatars (must match backend App\Modules\Identity\Enums\Avatar). Children never upload photos. */
export const AVATARS: Record<string, string> = {
  astronaut: '🧑‍🚀',
  rocket: '🚀',
  planet: '🪐',
  star: '⭐',
  comet: '☄️',
  moon: '🌙',
  ufo: '🛸',
  robot: '🤖',
  cat: '🐱',
  dog: '🐶',
  rabbit: '🐰',
  panda: '🐼',
}

export function avatarEmoji(code: string): string {
  return AVATARS[code] ?? '⭐'
}

export const THEME_COLORS = ['#5B8DEF', '#7C6CF2', '#FF9F43', '#2EC4B6', '#FF6B9A', '#4CAF50', '#F4B400', '#8D6E63']
