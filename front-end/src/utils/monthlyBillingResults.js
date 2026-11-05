export function summarizeMonthlyResults(results = []) {
  const counts = { CREATED: 0, EXISTING: 0, PENDING_FINAL_SETTLEMENT: 0, BLOCKED: 0, NO_ELIGIBLE_CONTRACT: 0, NO_NEW_RECEIVABLES: 0 }
  for (const result of results) if (Object.hasOwn(counts, result.status)) counts[result.status]++
  return counts
}
