import { useEffect, useRef, useState } from 'react'
import { getApiErrorMessage } from '../api/getApiErrorMessage'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Building2, FileText, Gauge, KeyRound, LayoutDashboard, LogOut, Menu, Receipt, UserRound, Users, X } from 'lucide-react'
import { Link, Outlet, useLocation, useNavigate } from 'react-router-dom'
import { logout } from '../api/authApi'
import { APP_NAME } from '../utils/brand'
import { clearContractDocumentCache } from '../api/contractDocumentCache'
import beeHouseLogo from '../assets/logo_bee_mau.png'

const menus = [
  ['Dashboard', '/dashboard', LayoutDashboard, (p) => p === '/dashboard'],
  ['Nhà trọ', '/boarding-houses', Building2, (p) => /^\/boarding-houses(\/[^/]+)?(\/edit)?$/.test(p)],
  ['Phòng', '/rooms', Building2, (p) => p === '/rooms' || /^\/rooms\/[^/]+(\/edit)?$/.test(p) || /^\/boarding-houses\/[^/]+\/rooms/.test(p)],
  ['Người thuê', '/tenants', Users, (p) => p === '/tenants' || /^\/tenants\//.test(p)],
  ['Hợp đồng', '/contracts', FileText, (p) => p === '/contracts' || /^\/contracts\//.test(p) || /^\/rooms\/[^/]+\/contracts/.test(p)],
  ['Mẫu hợp đồng', '/contract-templates', FileText, (p) => p.startsWith('/contract-templates')],
  ['Dịch vụ', '/services', Gauge, (p) => p === '/services' || /^\/services\//.test(p) || /^\/boarding-houses\/[^/]+\/services/.test(p)],
  ['Điện nước', '/utilities', Gauge, (p) => p === '/utilities' || /^\/utility-meters\//.test(p)],
  ['Hóa đơn', '/invoices', Receipt, (p) => p === '/invoices' || /^\/invoices\//.test(p) || /^\/rooms\/[^/]+\/invoices/.test(p)],
  ['Khai báo cư trú', '/residence-declarations', FileText, (p) => p.startsWith('/residence-declarations')],
]

function Brand() {
  return <div className="flex min-w-0 items-center gap-3">
    <img src={beeHouseLogo} alt="" className="h-11 w-11 shrink-0 rounded-lg bg-white p-1 object-contain" />
    <div className="min-w-0"><h1 className="text-xl font-bold">{APP_NAME}</h1><p className="text-xs text-slate-300">Quản lý trọ</p></div>
  </div>
}

function Navigation({ pathname, onNavigate, canManageTemplates = false }) {
  return <nav aria-label="Điều hướng chính" className="mt-8">
    {menus.filter(([, to]) => to !== '/contract-templates' || canManageTemplates).map(([name, to, Icon, matches]) => <Link
      key={name}
      to={to}
      onClick={onNavigate}
      aria-current={matches(pathname) ? 'page' : undefined}
      className={'mb-2 flex min-h-11 items-center gap-3 rounded-lg px-3 py-2 text-sm transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white lg:min-h-0 ' + (matches(pathname) ? 'bg-slate-700 font-semibold text-white' : 'text-slate-300 hover:bg-slate-800 hover:text-white')}
    ><Icon size={18} aria-hidden="true" />{name}</Link>)}
  </nav>
}

export default function MainLayout() {
  const client = useQueryClient()
  const navigate = useNavigate()
  const { pathname } = useLocation()
  const [drawerOpen, setDrawerOpen] = useState(false)
  const [userMenuOpen, setUserMenuOpen] = useState(false)
  const drawerRef = useRef(null)
  const userMenuRef = useRef(null)
  const userButtonRef = useRef(null)
  const me = client.getQueryData(['me'])?.data?.data
  const userName = me?.user?.name || 'bạn'
  const mutation = useMutation({
    mutationFn: logout,
    onSettled: (_, error) => {
      localStorage.removeItem('access_token')
      client.removeQueries({ queryKey: ['me'] })
      client.removeQueries({ queryKey: ['dashboard'] })
      clearContractDocumentCache(client)
      navigate('/login', { state: error ? { error: getApiErrorMessage(error) } : undefined })
    },
  })

  useEffect(() => {
    const dialog = drawerRef.current
    if (drawerOpen && !dialog.open) dialog.showModal()
    if (!drawerOpen && dialog.open) dialog.close()
    if (!drawerOpen) return undefined
    const previousOverflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'
    const breakpoint = window.matchMedia('(min-width: 1024px)')
    const closeOnDesktop = () => { if (breakpoint.matches) setDrawerOpen(false) }
    breakpoint.addEventListener('change', closeOnDesktop)
    return () => {
      document.body.style.overflow = previousOverflow
      breakpoint.removeEventListener('change', closeOnDesktop)
    }
  }, [drawerOpen])

  useEffect(() => {
    if (!userMenuOpen) return undefined
    const outside = (event) => { if (!userMenuRef.current?.contains(event.target)) setUserMenuOpen(false) }
    const escape = (event) => {
      if (event.key === 'Escape') { setUserMenuOpen(false); userButtonRef.current?.focus() }
    }
    document.addEventListener('pointerdown', outside)
    document.addEventListener('keydown', escape)
    userMenuRef.current?.querySelector('[role="menuitem"]')?.focus()
    return () => {
      document.removeEventListener('pointerdown', outside)
      document.removeEventListener('keydown', escape)
    }
  }, [userMenuOpen])

  const closeMenus = () => { setDrawerOpen(false); setUserMenuOpen(false) }
  const logoutFromMenu = () => { setUserMenuOpen(false); mutation.mutate() }
  const menuKeys = (event) => {
    if (!['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) return
    event.preventDefault()
    const items = [...userMenuRef.current.querySelectorAll('[role="menuitem"]')]
    const index = items.indexOf(document.activeElement)
    const next = event.key === 'Home' ? 0 : event.key === 'End' ? items.length - 1 : (index + (event.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length
    items[next]?.focus()
  }

  return <div className="app-shell min-h-dvh lg:flex">
    <aside className="hidden bg-slate-900 p-5 text-white lg:sticky lg:top-0 lg:block lg:h-dvh lg:w-60 lg:shrink-0 lg:overflow-y-auto">
      <Brand /><Navigation pathname={pathname} canManageTemplates={Boolean(me?.landlord)} />
    </aside>
    <dialog ref={drawerRef} id="mobile-navigation" aria-label="Menu BeeHouse" onClose={() => setDrawerOpen(false)} onCancel={() => setDrawerOpen(false)} onKeyDown={(event) => {
      if (event.key !== 'Tab') return
      const items = [...event.currentTarget.querySelectorAll('a[href], button:not([disabled])')]
      const first = items[0]
      const last = items[items.length - 1]
      if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus() }
      if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus() }
    }} onClick={(event) => {
      const bounds = event.currentTarget.getBoundingClientRect()
      if (event.target === event.currentTarget && (event.clientX < bounds.left || event.clientX > bounds.right || event.clientY < bounds.top || event.clientY > bounds.bottom)) setDrawerOpen(false)
    }} className="mobile-navigation m-0 h-dvh max-h-none w-[min(280px,85vw)] max-w-none border-0 bg-slate-900 p-0 text-white shadow-xl backdrop:bg-black/40 lg:hidden">
      <div className="flex h-full flex-col p-4 [padding-top:max(1rem,env(safe-area-inset-top))] [padding-bottom:max(1rem,env(safe-area-inset-bottom))]">
        <div className="flex items-center justify-between gap-2"><Brand /><button type="button" onClick={() => setDrawerOpen(false)} aria-label="Đóng menu" className="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg hover:bg-slate-800 focus-visible:outline-2 focus-visible:outline-white"><X size={22} /></button></div>
        <div className="min-h-0 flex-1 overflow-y-auto"><Navigation pathname={pathname} onNavigate={closeMenus} canManageTemplates={Boolean(me?.landlord)} /></div>
      </div>
    </dialog>
    <section className="min-w-0 flex-1">
      <header className="sticky top-0 z-30 flex min-h-16 items-center justify-between gap-3 bg-white px-4 py-2 shadow-sm [padding-top:max(.5rem,env(safe-area-inset-top))] lg:static lg:min-h-0 lg:p-4">
        <div className="flex min-w-0 items-center gap-2 lg:hidden">
          <button type="button" aria-label="Mở menu" aria-controls="mobile-navigation" aria-expanded={drawerOpen} onClick={() => { setUserMenuOpen(false); setDrawerOpen(true) }} className="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg text-slate-700 hover:bg-slate-100 focus-visible:ring-2 focus-visible:ring-slate-900"><Menu size={23} /></button>
          <Link to="/dashboard" onClick={closeMenus} className="flex min-h-11 min-w-0 items-center gap-2"><img src={beeHouseLogo} alt="" className="h-8 w-8 shrink-0 object-contain" /><span className="truncate text-lg font-bold text-slate-900">{APP_NAME}</span></Link>
        </div>
        <span className="hidden min-w-0 break-words lg:block">Xin chào, {userName}</span>
        <div className="hidden shrink-0 items-center gap-4 lg:flex">
          <Link to="/change-password" className="flex items-center gap-2 text-sm"><KeyRound size={17} />Đổi mật khẩu</Link>
          <button type="button" onClick={() => mutation.mutate()} disabled={mutation.isPending} className="flex items-center gap-2 text-sm disabled:opacity-60"><LogOut size={17} />Đăng xuất</button>
        </div>
        <div ref={userMenuRef} className="relative shrink-0 lg:hidden">
          <button ref={userButtonRef} type="button" aria-label="Menu tài khoản" aria-haspopup="menu" aria-expanded={userMenuOpen} aria-controls="mobile-user-menu" onClick={() => setUserMenuOpen((open) => !open)} className="flex h-11 w-11 items-center justify-center rounded-full bg-slate-100 text-slate-700 focus-visible:ring-2 focus-visible:ring-slate-900"><UserRound size={21} /></button>
          {userMenuOpen && <div id="mobile-user-menu" role="menu" onKeyDown={menuKeys} className="absolute right-0 top-full mt-2 w-[min(256px,calc(100vw-32px))] rounded-xl border border-slate-200 bg-white p-2 shadow-lg">
            <p className="border-b border-slate-100 px-3 py-3 text-sm font-semibold [overflow-wrap:anywhere]">{userName}</p>
            <Link role="menuitem" to="/change-password" onClick={closeMenus} className="mt-1 flex min-h-11 items-center gap-2 rounded-lg px-3 py-2 text-sm hover:bg-slate-100"><KeyRound size={18} />Đổi mật khẩu</Link>
            <button role="menuitem" type="button" onClick={logoutFromMenu} disabled={mutation.isPending} className="flex min-h-11 w-full items-center gap-2 rounded-lg px-3 py-2 text-sm text-red-700 hover:bg-red-50 disabled:opacity-60"><LogOut size={18} />{mutation.isPending ? 'Đang đăng xuất...' : 'Đăng xuất'}</button>
          </div>}
        </div>
      </header>
      <main className="app-content min-w-0 p-4 [padding-bottom:max(1rem,env(safe-area-inset-bottom))] sm:p-5 lg:p-6"><Outlet /></main>
    </section>
  </div>
}
