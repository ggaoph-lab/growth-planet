let ctx: AudioContext | null = null

/**
 * A short, soft confirmation tone generated with Web Audio (no audio files, no network).
 * Callers must check the child's "sounds" preference first.
 */
export function playTap(): void {
  try {
    ctx ??= new AudioContext()
    const osc = ctx.createOscillator()
    const gain = ctx.createGain()
    osc.type = 'sine'
    osc.frequency.value = 660
    gain.gain.setValueAtTime(0.0001, ctx.currentTime)
    gain.gain.exponentialRampToValueAtTime(0.08, ctx.currentTime + 0.02)
    gain.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + 0.18)
    osc.connect(gain).connect(ctx.destination)
    osc.start()
    osc.stop(ctx.currentTime + 0.2)
  } catch {
    // Audio unavailable (e.g. autoplay policy); silently skip.
  }
}

/** Crisp water-drop for a correct Schulte tap. Callers check the sounds preference first. */
export function playDrop(): void {
  const audio = audioContext()
  if (!audio) return
  try {
    const start = audio.currentTime
    const osc = audio.createOscillator()
    const gain = audio.createGain()
    osc.type = 'sine'
    osc.frequency.setValueAtTime(1680, start)
    osc.frequency.exponentialRampToValueAtTime(520, start + 0.07)
    gain.gain.setValueAtTime(0.0001, start)
    gain.gain.exponentialRampToValueAtTime(0.12, start + 0.006)
    gain.gain.exponentialRampToValueAtTime(0.0001, start + 0.13)
    osc.connect(gain).connect(audio.destination)
    osc.start(start)
    osc.stop(start + 0.15)
  } catch {
    // Audio unavailable (e.g. autoplay policy); silently skip.
  }
}

/** Soft prompt for a wrong Schulte tap. Callers check the sounds preference first. */
export function playMiss(): void {
  tone(349, 0.16, 0.035)
}

/** Two short rising notes when a Schulte grid is finished. */
export function playWin(): void {
  tone(523, 0.14, 0.06)
  tone(784, 0.2, 0.06, 0.1)
}

function audioContext(): AudioContext | null {
  try {
    ctx ??= new AudioContext()
    if (ctx.state === 'suspended') void ctx.resume()
    return ctx
  } catch {
    return null
  }
}

function tone(frequency: number, duration: number, peak: number, delay = 0): void {
  const audio = audioContext()
  if (!audio) return
  try {
    const start = audio.currentTime + delay
    const osc = audio.createOscillator()
    const gain = audio.createGain()
    osc.type = 'sine'
    osc.frequency.setValueAtTime(frequency, start)
    gain.gain.setValueAtTime(0.0001, start)
    gain.gain.exponentialRampToValueAtTime(peak, start + 0.02)
    gain.gain.exponentialRampToValueAtTime(0.0001, start + duration)
    osc.connect(gain).connect(audio.destination)
    osc.start(start)
    osc.stop(start + duration + 0.02)
  } catch {
    // Audio unavailable (e.g. autoplay policy); silently skip.
  }
}
