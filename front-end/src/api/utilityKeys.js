export const utilityKeys = {
  meters: (roomId) => ['utility-meters', roomId],
  meter: (id) => ['utility-meter', id],
  readings: (meterId) => ['utility-readings', meterId],
  reading: (id) => ['utility-reading', id],
}
