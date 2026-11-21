import client from './axiosClient'

export const getUtilityLedger = (boardingHouseId, year) => client.get(`/boarding-houses/${boardingHouseId}/utility-ledger`, { params: { year } })
