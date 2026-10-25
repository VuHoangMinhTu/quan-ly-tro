export const DASHBOARD_REFRESH_MS = 30_000

// Re-entering a fresh dashboard must not postpone its next scheduled refresh.
export function dashboardRefetchInterval(query) {
  const updatedAt = query.state.dataUpdatedAt
  const age = Date.now() - updatedAt
  return updatedAt > 0 && age >= 0 && age < DASHBOARD_REFRESH_MS ? DASHBOARD_REFRESH_MS - age : DASHBOARD_REFRESH_MS
}
