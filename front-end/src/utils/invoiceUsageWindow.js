// The invoice's saved usage window is authoritative for BOTH room rentals and dorm beds.
// A meter period is a legacy fallback; calendar month is not a safe default.
export function invoiceUsageWindow(snapshot) {
  const window = snapshot?.usage_window
  const period = snapshot?.usage_periods?.[0]
  const start = window?.start ?? period?.usage_start_at ?? null
  const end = window?.end ?? period?.usage_end_at ?? null
  return { start, end, hasSavedWindow: Boolean(start && end) }
}
