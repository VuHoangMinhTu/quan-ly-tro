function cleanPart(value) {
  const withoutControls = [...String(value ?? '')].filter(character => character.charCodeAt(0) >= 32).join('')
  return withoutControls
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .replace(/đ/g, 'd').replace(/Đ/g, 'D')
    .replace(/[<>:"/\\|?*]/g, '-')
    .replace(/\s+/g, '')
    .replace(/-+/g, '-')
    .replace(/^-|-$/g, '')
}

function title(parts, fallback) {
  const value = parts.map(cleanPart).filter(Boolean).join('_') || fallback
  return value.slice(0, 120)
}

export function contractPrintTitle(snapshot, demo = false) {
  const room = snapshot?.room?.room_code
  const bed = snapshot?.bed?.bed_code
  const tenant = snapshot?.tenant?.full_name
  const contractCode = snapshot?.contract?.contract_code
  return title([bed ? 'HopDong_KTX' : 'HopDong_Phong', room, bed, tenant, demo ? 'DEMO' : ''], title(['HopDong', contractCode], 'HopDong_BeeHouse'))
}

export function ct01PrintTitle(data) {
  return title(['CT01', data?.residence?.room_code, data?.declarant?.full_name], 'CT01_BeeHouse')
}

export function stayConsentPrintTitle(data) {
  return title(['VanBanDongYChoONho', data?.room_code, data?.guest?.full_name], 'VanBanDongYChoONho_BeeHouse')
}

