import { useState } from 'react'
import { createRoot } from 'react-dom/client'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter } from 'react-router-dom'
import TenantPicker from '../src/components/tenants/TenantPicker'
import ContractWizard from '../src/components/contracts/ContractWizard'
import RoomTenantForm from '../src/components/forms/RoomTenantForm'
import ResidenceDeclarationForm from '../src/components/residence/ResidenceDeclarationForm'
import ToastProvider from '../src/notifications/ToastProvider'
import '../src/index.css'

const params = new URLSearchParams(location.search)
const room = { id: 11, boarding_house_id: 3, room_code: '101', room_type: params.get('dorm') ? 'DORMITORY' : 'PRIVATE_ROOM', monthly_rent: 3000000, max_tenants: 4, boarding_house: { id: 3, name: 'Nhà thử', residence_profile: {} } }
const context = { room, tenants: [], beds: [{ id: 1, bed_code: 'G01', status: 'AVAILABLE' }], templates: [], representatives: [], utility_openings: [], services: [], contracts: [], operational_role: 'OWNER', occupants: [1, 2].map(id => ({ tenant: { id, full_name: `Người thử ${String(id).padStart(2, '0')}`, phone: `09000000${id}`, date_of_birth: '2000-01-01', identity_number: `00000000000${id}` } })) }
const contract = { tenant_id: '', start_date: '2026-10-01', monthly_rent: 3000000, deposit_amount: 0, auto_code: true, contract_type: params.get('dorm') ? 'DORM_BED' : 'ROOM_RENTAL', bed_id: params.get('dorm') ? 1 : null, status: 'DRAFT' }
const membership = { tenant_id: '', move_in_date: '2026-10-01', status: 'ACTIVE', is_primary: false, bed_id: params.get('dorm') ? 1 : null }
export function Fixture() {
  const [selected, setSelected] = useState([])
  const [result, setResult] = useState(null)
  const mode = params.get('mode')
  return <main className="mx-auto max-w-4xl space-y-4 p-4"><h1>Kiểm thử chọn người thuê</h1>
    {mode === 'contract' ? <ContractWizard context={context} initial={contract} onSubmit={setResult} /> : mode === 'room' ? <RoomTenantForm room={room} initialValues={membership} submitLabel="Lưu người ở" onSubmit={setResult} /> : mode === 'residence' ? <ResidenceDeclarationForm context={context} onSubmit={setResult} /> : <><input aria-label="Ghi chú kiểm thử" /><TenantPicker label="Người ở cùng" multiple allowClear value={selected} onChange={setSelected} contextRoomId={room.id} /><output id="selected">{JSON.stringify(selected)}</output></>}
    <pre id="result">{JSON.stringify(result)}</pre>
  </main>
}
createRoot(document.getElementById('root')).render(<ToastProvider><QueryClientProvider client={new QueryClient()}><MemoryRouter><Fixture /></MemoryRouter></QueryClientProvider></ToastProvider>)
