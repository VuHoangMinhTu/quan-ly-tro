/** Show unfinished drafts even when a room has no active lease. Do not mutate API response. */
export function reviewableRoomContracts(contracts = []) {
  if (!Array.isArray(contracts)) return []
  return contracts
    .filter(contract => contract && ['DRAFT', 'ACTIVE'].includes(contract.status))
    .slice()
    .sort((a, b) => (a.status === b.status ? 0 : a.status === 'DRAFT' ? -1 : 1)
      || Number(b.id || 0) - Number(a.id || 0))
}
