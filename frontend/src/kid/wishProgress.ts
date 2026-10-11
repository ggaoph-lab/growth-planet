/** Share of the wish price already covered by available coins, capped at 100. */
export function wishProgressPercent(available: number, price: number | null | undefined): number {
  if (price == null || !Number.isFinite(price) || price <= 0) return 0
  const coins = Number.isFinite(available) ? Math.max(0, available) : 0
  return Math.min(100, Math.floor((coins / price) * 100))
}
