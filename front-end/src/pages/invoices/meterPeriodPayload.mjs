/** Build one explicit interval per physical meter; the envelope is only request metadata. */
export function buildMeterPeriodPayload(meters, cycleForMeter) {
  if (!meters.length) throw new Error('Chưa có công tơ để quyết toán.')
  const periods = meters.map(meter => {
    const cycle = cycleForMeter(meter)
    const start = String(cycle.start).replace('T', ' ') + (String(cycle.start).length === 16 ? ':00' : '')
    const end = String(cycle.end).replace('T', ' ') + (String(cycle.end).length === 16 ? ':00' : '')
    if (start >= end) throw new Error(`Công tơ #${meter.id}: mốc cuối phải sau mốc đầu.`)
    return { utility_meter_id: Number(meter.id), usage_start_at: start, usage_end_at: end }
  })
  const start = periods.map(x => x.usage_start_at).sort()[0]
  const end = periods.map(x => x.usage_end_at).sort().at(-1)
  return { meterPeriods: periods, start, end }
}
