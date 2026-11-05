// Synthetic UI fixtures only. No environment files, backend or customer records.
export const uiHouse = { id: 101, name: 'Nhà trọ Vườn Nhỏ — dữ liệu minh họa', address: 'Địa chỉ kiểm thử', default_payment_due_day: 5 }
export const uiTenant = { id: 501, full_name: 'Người thuê minh họa', phone: null, occupancy_status: 'UNASSIGNED' }
export const uiServices = [
  { id: 11, name: 'Điện sinh hoạt', type: 'ELECTRICITY', billing_method: 'PER_UNIT', unit: 'kWh', base_price: 3800, is_active: true, vat_rate: 0 },
  { id: 12, name: 'Nước sinh hoạt theo người', type: 'WATER', billing_method: 'PER_PERSON', unit: 'người', base_price: 100000, is_active: true, vat_rate: 0 },
  { id: 13, name: 'Internet dùng chung', type: 'INTERNET', billing_method: 'FIXED', unit: 'tháng', base_price: 120000, is_active: true, vat_rate: 0 },
  { id: 14, name: 'Điện theo bậc thang — phương án tham khảo', type: 'ELECTRICITY', billing_method: 'TIERED', unit: 'kWh', base_price: null, is_active: false, vat_rate: 0 },
]
export const uiTemplates = [
  { id: 31, template_key: 'ROOM_RENTAL_STANDARD', name: 'Mẫu phòng trọ — nội dung kiểm thử', version: 1, is_active: true, landlord_id: null, documents_count: 2, created_at: '2026-10-01' },
  { id: 32, template_key: 'DORM_BED_STANDARD', name: 'Mẫu KTX — nội dung kiểm thử', version: 1, is_active: true, landlord_id: null, documents_count: 0, created_at: '2026-10-01' },
]
export function workspaceFixture(url, { demoOnly = false } = {}) {
  const path = url.pathname.replace(/^\/api/, '')
  const templates = demoOnly ? uiTemplates.map(t => ({ ...t, template_key: t.template_key.replace('_STANDARD', '_DEMO'), name: t.name.replace('nội dung kiểm thử', 'DEMO') })) : uiTemplates
  if (path === '/contract-templates') return { templates, variables: [{ key: 'contract.code', placeholder: '{{contract.code}}', label: 'Mã hợp đồng', group: 'Hợp đồng' }], can_manage: !demoOnly }
  if (path === '/contract-templates/31') return { ...templates[0], content_html: '<h1 class="text-center">MẪU KIỂM THỬ GIAO DIỆN</h1><p>Đây là nội dung giả lập, không phải văn bản hợp đồng của BeeHouse.</p><p>Mã: {{contract.code}}</p>' }
  if (path === '/rooms/101/contract-context') return { demo_contracts_only: demoOnly, room: { id: 101, boarding_house_id: 101, room_code: '101', room_type: 'PRIVATE_ROOM', monthly_rent: 3000000, max_tenants: 4, boarding_house: uiHouse }, templates, beds: [{ id: 51, bed_code: 'G01', status: 'AVAILABLE' }], representatives: [{ user_id: 101, full_name: 'Chủ trọ minh họa', title: 'Chủ trọ' }], services: uiServices.slice(0, 3), utility_openings: [{ utility_meter_id: 71, service_name: 'Điện sinh hoạt', unit: 'kWh', opening_reading: 1200, reading_date: '2026-10-01' }], operational_role: 'OWNER', occupants: [] }
  if (path === '/tenant-lookup/options') return { houses: [uiHouse], rooms: [{ id: 101, room_code: '101' }], can_create: true }
  if (path === '/tenant-lookup') return { tenants: [uiTenant], total: 1, current_page: 1, last_page: 1, per_page: 20, can_search_identity: true }
  if (path === '/boarding-houses/101/services') return uiServices
  if (path === '/services/14/price-tiers') return [{ id: 1, from_quantity: 0, to_quantity: 50, unit_price: 2000, tier_order: 1 }, { id: 2, from_quantity: 50, to_quantity: null, unit_price: 3000, tier_order: 2 }]
  if (path === '/utility-meters/71') return { id: 71, room_id: 101, meter_code: 'ĐH-ĐIỆN-101', initial_reading: 1000, is_active: true, service: uiServices[0] }
  if (path === '/utility-meters/71/readings') return [{ id: 1, reading_date: '2026-09-01', reading_value: 1100, note: 'Chỉ số minh họa tháng trước' }, { id: 2, reading_date: '2026-10-01', reading_value: 1200, note: 'Ghi chú dài kiểm tra khả năng hiển thị trên màn hình điện thoại và bảng dữ liệu.' }]
  if (path === '/invoices/101') return { id: 101, room_id: 101, invoice_code: 'HD-MINH-HOA-2026-10', billing_period: '2026-10-01', issued_at: '2026-10-01', due_date: '2026-10-10', subtotal: 3500000, discount_amount: 0, total_amount: 3500000, paid_amount: 1000000, status: 'PARTIALLY_PAID', can_manage: true, note: 'Dữ liệu minh họa để kiểm tra layout. Không phải hóa đơn thực tế.', payment_profile: null, items: [{ id: 1, type: 'RENT', description: 'Tiền thuê phòng', quantity: 1, unit_price: 3000000, amount: 3000000 }, { id: 2, type: 'OTHER', description: 'Dịch vụ minh họa', quantity: 1, unit_price: 500000, amount: 500000 }] }
  if (path === '/invoices/101/payments') return [{ id: 1, amount: 1000000, payment_method: 'CASH', paid_at: '2026-10-03', note: 'Thanh toán giả lập' }]
  if (path === '/residence-declarations') return [{ id: 61, status: 'DRAFT', room_code: '101', boarding_house_name: uiHouse.name, declarant: uiTenant, created_at: '2026-10-01', is_frozen: false }]
  return undefined
}
