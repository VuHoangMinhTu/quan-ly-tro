import { getApiErrorMessage } from '../api/getApiErrorMessage'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Building2, FileText, Gauge, KeyRound, LayoutDashboard, LogOut, Receipt, Users } from 'lucide-react'
import { Link, Outlet, useLocation, useNavigate } from 'react-router-dom'
import { logout } from '../api/authApi'
import { APP_NAME } from '../utils/brand'
import beeHouseLogo from '../assets/logo_bee_mau.png'

const menus = [
  ['Dashboard', '/dashboard', LayoutDashboard, (p) => p === '/dashboard'],
  ['Nhà trọ', '/boarding-houses', Building2, (p) => /^\/boarding-houses(\/[^/]+)?(\/edit)?$/.test(p)],
  ['Phòng', '/rooms', Building2, (p) => p === '/rooms' || /^\/rooms\/[^/]+(\/edit)?$/.test(p) || /^\/boarding-houses\/[^/]+\/rooms/.test(p)],
  ['Người thuê', '/tenants', Users, (p) => p === '/tenants' || /^\/tenants\//.test(p)],
  ['Hợp đồng', '/contracts', FileText, (p) => p === '/contracts' || /^\/contracts\//.test(p) || /^\/rooms\/[^/]+\/contracts/.test(p)],
  ['Dịch vụ', '/services', Gauge, (p) => p === '/services' || /^\/services\//.test(p) || /^\/boarding-houses\/[^/]+\/services/.test(p)],
  ['Điện nước', '/utilities', Gauge, (p) => p === '/utilities' || /^\/utility-meters\//.test(p)],
  ['Hóa đơn', '/invoices', Receipt, (p) => p === '/invoices' || /^\/invoices\//.test(p) || /^\/rooms\/[^/]+\/invoices/.test(p)],
]
export default function MainLayout() { const client = useQueryClient(), navigate = useNavigate(), { pathname } = useLocation(); const me = client.getQueryData(['me'])?.data?.data; const mutation = useMutation({ mutationFn: logout, onSettled: (_, error) => { localStorage.removeItem('access_token'); client.removeQueries({ queryKey: ['me'] }); navigate('/login', { state: error ? { error: getApiErrorMessage(error) } : undefined }) } }); return <div className="min-h-screen md:flex"><aside className="bg-slate-900 p-5 text-white md:w-60"><div className="mb-8 flex items-center gap-3"><img src={beeHouseLogo} alt="" className="h-11 w-11 shrink-0 rounded-lg bg-white p-1 object-contain" /><div className="min-w-0"><h1 className="text-xl font-bold">{APP_NAME}</h1><p className="text-xs text-slate-300">Quản lý trọ</p></div></div><nav>{menus.map(([name,to,Icon,matches]) => <Link key={name} to={to} className={`mb-2 flex items-center gap-3 rounded-lg px-3 py-2 text-sm transition ${matches(pathname) ? 'bg-slate-700 font-semibold text-white' : 'text-slate-300 hover:bg-slate-800 hover:text-white'}`}><Icon size={18}/>{name}</Link>)}</nav></aside><section className="flex-1"><header className="flex items-center justify-between bg-white p-4 shadow-sm"><span>Xin chào, {me?.user?.name || 'bạn'}</span><div className="flex items-center gap-4"><Link to="/change-password" className="flex items-center gap-2 text-sm"><KeyRound size={17}/>Đổi mật khẩu</Link><button onClick={() => mutation.mutate()} disabled={mutation.isPending} className="flex items-center gap-2 text-sm"><LogOut size={17}/>Đăng xuất</button></div></header><main className="p-6"><Outlet/></main></section></div> }
