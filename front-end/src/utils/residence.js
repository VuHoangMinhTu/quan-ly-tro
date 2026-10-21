import { formatDateForApi, formatDateForDisplay } from './date.js'

export const residenceStatusLabels = { DRAFT: 'Nháp', PENDING: 'Chờ nộp', SUBMITTED: 'Đã nộp', REGISTERED: 'Đã đăng ký', REJECTED: 'Bị từ chối', EXPIRED: 'Hết hạn' }
export const residenceTransitions = { DRAFT: ['PENDING', 'SUBMITTED'], PENDING: ['DRAFT', 'SUBMITTED', 'REJECTED'], SUBMITTED: ['REGISTERED', 'REJECTED'], REGISTERED: ['EXPIRED'], REJECTED: [], EXPIRED: [] }
export function residenceFormValues(record) {
  return {
    declarant_tenant_id: record?.declarant_tenant_id == null ? '' : String(record.declarant_tenant_id),
    contract_id: record?.contract_id == null ? '' : String(record.contract_id),
    authority_name: record?.authority_name ?? '',
    household_head_mode: record?.household_head_mode ?? '',
    household_head_name: record?.household_head_name ?? '',
    household_head_identity_number: record?.household_head_identity_number ?? '',
    relationship_to_household_head: record?.relationship_to_household_head ?? '',
    request_content: record ? record.request_content ?? '' : 'Đăng ký tạm trú',
    stay_start_date: formatDateForDisplay(record?.stay_start_date),
    stay_end_date: formatDateForDisplay(record?.stay_end_date),
    household_head_opinion: record?.household_head_opinion ?? '',
    legal_owner_opinion: record?.legal_owner_opinion ?? '',
    guardian_opinion: record?.guardian_opinion ?? '',
    members: (record?.members ?? []).map(member => ({ tenant_id: Number(member.tenant_id), relationship_to_household_head: member.relationship_to_household_head ?? '' })),
  }
}
export function residencePayload(roomId, values) {
  return {
    room_id: Number(roomId),
    declarant_tenant_id: values.declarant_tenant_id ? Number(values.declarant_tenant_id) : null,
    contract_id: values.contract_id ? Number(values.contract_id) : null,
    authority_name: values.authority_name.trim() || null,
    household_head_mode: values.household_head_mode || null,
    household_head_name: values.household_head_mode === 'OTHER_PERSON' ? values.household_head_name.trim() || null : null,
    household_head_identity_number: values.household_head_mode === 'OTHER_PERSON' ? values.household_head_identity_number.trim() || null : null,
    relationship_to_household_head: values.household_head_mode === 'DECLARANT' ? 'Chủ hộ' : values.household_head_mode === 'OTHER_PERSON' ? values.relationship_to_household_head.trim() || null : null,
    request_content: values.request_content.trim() || null,
    stay_start_date: formatDateForApi(values.stay_start_date) || null,
    stay_end_date: formatDateForApi(values.stay_end_date) || null,
    household_head_opinion: values.household_head_opinion?.trim() || null,
    legal_owner_opinion: values.legal_owner_opinion?.trim() || null,
    guardian_opinion: values.guardian_opinion?.trim() || null,
    members: values.members.map(member => ({ tenant_id: Number(member.tenant_id), relationship_to_household_head: member.relationship_to_household_head.trim() || null })),
  }
}
