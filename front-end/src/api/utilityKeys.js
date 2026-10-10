export const utilityKeys = {
  // Route params are strings, while RoomDetail provides numeric IDs.
  meters: (roomId) => ['utility-meters', String(roomId)],
  meter: (id) => ['utility-meter', String(id)],
  readings: (meterId) => ['utility-readings', String(meterId)],
  reading: (id) => ['utility-reading', String(id)],
}
