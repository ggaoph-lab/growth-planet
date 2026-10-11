/** Split a stored intro into Chinese then English when the two paragraphs are separated by a blank line. */
export function overviewParagraphs(overview: string | null | undefined): string[] {
  const text = overview?.trim() ?? ''
  if (!text) return []
  return text.split(/\n\s*\n/).map((part) => part.trim()).filter((part) => part.length > 0)
}
