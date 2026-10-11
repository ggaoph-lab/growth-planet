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
