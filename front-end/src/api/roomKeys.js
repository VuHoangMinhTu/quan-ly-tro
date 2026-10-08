export const roomKeys = {
  // Route params are strings while API models expose numeric IDs. Canonicalize
  // them here so every query and invalidation targets the same cache entry.
  byBoardingHouse: (boardingHouseId) => ['rooms', String(boardingHouseId)],
  detail: (id) => ['room', String(id)],
  amenities: ['amenities'],
}
