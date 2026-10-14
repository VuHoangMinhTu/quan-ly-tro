export const residenceStatusLabels = { DRAFT: 'Nháp', PENDING: 'Chờ nộp', SUBMITTED: 'Đã nộp', REGISTERED: 'Đã đăng ký', REJECTED: 'Bị từ chối', EXPIRED: 'Hết hạn' }
export const residenceTransitions = { DRAFT: ['PENDING', 'SUBMITTED'], PENDING: ['DRAFT', 'SUBMITTED', 'REJECTED'], SUBMITTED: ['REGISTERED', 'REJECTED'], REGISTERED: ['EXPIRED'], REJECTED: [], EXPIRED: [] }
export function residenceFormValues(record) {
  return {
    declarant_tenant_id: record?.declarant_tenant_id == null ? '' : String(record.declarant_tenant_id),
    contract_id: record?.contract_id == null ? '' : String(record.contract_id),
    authority_name: record?.authority_name ?? '',
    relationship_to_household_head: record?.relationship_to_household_head ?? '',
    request_content: record ? record.request_content ?? '' : 'Đăng ký tạm trú',
    members: (record?.members ?? []).map(member => ({ tenant_id: Number(member.tenant_id), relationship_to_household_head: member.relationship_to_household_head ?? '' })),
  }
}
export function residencePayload(roomId, values) {
  return {
    room_id: Number(roomId),
    declarant_tenant_id: values.declarant_tenant_id ? Number(values.declarant_tenant_id) : null,
    contract_id: values.contract_id ? Number(values.contract_id) : null,
    authority_name: values.authority_name.trim() || null,
    relationship_to_household_head: values.relationship_to_household_head.trim() || null,
    request_content: values.request_content.trim() || null,
    members: values.members.map(member => ({ tenant_id: Number(member.tenant_id), relationship_to_household_head: member.relationship_to_household_head.trim() || null })),
  }
}
