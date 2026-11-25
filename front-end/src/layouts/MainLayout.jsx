// BEEHOUSE_NAV_CONTEXT_V1
import { useEffect, useRef, useState } from 'react'
import { getApiErrorMessage } from '../api/getApiErrorMessage'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { Building2, CreditCard, FileText, Gauge, KeyRound, LayoutDashboard, LogOut, DoorClosed, Grid2X2, Receipt, UserRound, Users, X } from 'lucide-react'
import { Link, Outlet, useLocation, useNavigate } from 'react-router-dom'
import { logout } from '../api/authApi'
import { APP_NAME } from '../utils/brand'
import { endSession, getSession, isCurrentSession } from '../api/session'
import beeHouseLogo from '../assets/logo_bee_mau.png'
import '../styles/mobile-bottom-nav.css'

const menus = [
  ['Dashboard', '/dashboard', LayoutDashboard, (p) => p === '/dashboard'],
  ['Nhà trọ', '/boarding-houses', Building2, (p) => /^\/boarding-houses(\/[^/]+)?(\/edit)?$/.test(p)],
  ['Phòng', '/rooms', Building2, (p) => p === '/rooms' || /^\/rooms\/[^/]+(\/edit)?$/.test(p) || /^\/boarding-houses\/[^/]+\/rooms/.test(p)],
  ['Người thuê', '/tenants', Users, (p) => p === '/tenants' || /^\/tenants\//.test(p)],
  ['Hợp đồng', '/contracts', FileText, (p) => p === '/contracts' || /^\/contracts\//.test(p) || /^\/rooms\/[^/]+\/contracts/.test(p)],
  ['Mẫu hợp đồng', '/contract-templates', FileText, (p) => p.startsWith('/contract-templates')],
  ['Dịch vụ', '/services', Gauge, (p) => p === '/services' || /^\/services\//.test(p) || /^\/boarding-houses\/[^/]+\/services/.test(p)],
  ['Điện nước', '/utilities', Gauge, (p) => p === '/utilities' || /^\/utility-meters\//.test(p) || /^\/rooms\/[^/]+\/billing-v2$/.test(p)],
  ['Hóa đơn', '/invoices', Receipt, (p) => p === '/invoices' || /^\/invoices\//.test(p) || /^\/rooms\/[^/]+\/invoices/.test(p)],
  ['Khai báo cư trú', '/residence-declarations', FileText, (p) => p.startsWith('/residence-declarations')],
]

function Brand() {
  return <div className="bh-brand flex min-w-0 items-center gap-3">
    <img src={beeHouseLogo} width="44" height="44" alt="" className="bh-brand-mark h-11 w-11 shrink-0 rounded-lg bg-white p-1 object-contain" />
    <div className="min-w-0"><h1 className="text-xl font-bold">{APP_NAME}</h1><p className="text-xs text-slate-300">Không gian vận hành</p></div>
  </div>
}

const bottomRoutes = ['/dashboard', '/boarding-houses', '/rooms', '/invoices']
const bottomLabels = { '/dashboard': 'Tổng quan', '/boarding-houses': 'Nhà trọ', '/rooms': 'Phòng', '/invoices': 'Hóa đơn' }
const visibleMenus = canManageTemplates => menus.filter(([, to]) => to !== '/contract-templates' || canManageTemplates)

// Keep tab order stable. Route, not a separate selected state, determines the active tab.
function MobileBottomNav({ pathname, onNavigate, onOpenMore, drawerOpen }) {
  const routeIndex = bottomRoutes.findIndex(to => menus.find(item => item[1] === to)[3](pathname))
  const activeIndex = drawerOpen ? 4 : routeIndex === -1 ? 4 : routeIndex
  return <nav aria-label="Điều hướng nhanh" className="bh-mobile-bottom-nav lg:hidden" style={{ '--bh-active-index': activeIndex }}>
    <div className="bh-bottom-surface" aria-hidden="true">
      <div className="bh-bottom-shape"><div className="bh-bottom-notch">
        <svg viewBox="0 0 112 76" preserveAspectRatio="none" focusable="false"><path d="M0 0 C24 0 24 45 56 45 C88 45 88 0 112 0 V76 H0 Z" fill="white" /></svg>
      </div></div>
    </div>
    <div className="bh-bottom-bubble" aria-hidden="true" />
    {bottomRoutes.map((to, index) => {
      const [, , DefaultIcon] = menus.find(item => item[1] === to)
      const Icon = to === '/rooms' ? DoorClosed : DefaultIcon
      const active = index === activeIndex
      return <Link key={to} to={to} onClick={onNavigate} aria-current={active ? 'page' : undefined} data-active={active} className="bh-bottom-item">
        <span className="bh-bottom-icon"><Icon size={24} aria-hidden="true" /></span><span className="bh-bottom-label">{bottomLabels[to]}</span>
      </Link>
    })}
    <button type="button" aria-label="Mở menu Khác" aria-controls="mobile-navigation" aria-expanded={drawerOpen} aria-current={!drawerOpen && activeIndex === 4 ? 'true' : undefined} data-active={activeIndex === 4} onClick={onOpenMore} className="bh-bottom-item">
      <span className="bh-bottom-icon"><Grid2X2 size={24} aria-hidden="true" /></span><span className="bh-bottom-label">Khác</span>
    </button>
  </nav>
}

function Navigation({ pathname, onNavigate, canManageTemplates = false, overflowOnly = false }) {
  return <nav aria-label={overflowOnly ? "Các chức năng khác" : "Điều hướng chính"} className="bh-primary-nav mt-8">
    {visibleMenus(canManageTemplates).filter(([, to]) => !overflowOnly || !bottomRoutes.includes(to)).map(([name, to, Icon, matches]) => <Link
      key={name}
      to={to}
      onClick={onNavigate}
      aria-current={matches(pathname) ? 'page' : undefined}
      className={'bh-nav-link mb-2 flex min-h-11 items-center gap-3 rounded-lg px-3 py-2 text-sm transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white lg:min-h-0 ' + (matches(pathname) ? 'bg-slate-700 font-semibold text-white' : 'text-slate-300 hover:bg-slate-800 hover:text-white')}
    >{overflowOnly ? <span className="bh-more-icon"><Icon size={21} aria-hidden="true" /></span> : <Icon size={18} aria-hidden="true" />}{name}</Link>)}
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
  const previousPathRef = useRef(pathname)
  const me = client.getQueryData(['me'])?.data?.data
  const userName = me?.user?.name || 'bạn'
  const session = getSession()
  const mutation = useMutation({
    mutationFn: logout,
    onSettled: (_, error) => {
      if (!isCurrentSession(session)) return
      endSession(session)
      navigate('/login', { state: error ? { error: getApiErrorMessage(error) } : undefined })
    },
  })

  useEffect(() => {
    const dialog = drawerRef.current
    if (!dialog) return undefined
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

  // Keep drawer and account menu in sync with programmatic navigation.
  useEffect(() => {
    if (previousPathRef.current === pathname) return
    previousPathRef.current = pathname
    setDrawerOpen(false)
    setUserMenuOpen(false)
  }, [pathname])

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
    <a className="bh-skip" href="#app-main">Đến nội dung chính</a>
    <aside className="bh-sidebar hidden bg-slate-900 p-5 text-white lg:sticky lg:top-0 lg:block lg:h-dvh lg:w-60 lg:shrink-0 lg:overflow-y-auto">
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
    }} className="mobile-navigation bh-mobile-navigation bh-more-sheet overflow-hidden border-0 p-0 lg:hidden">
      <div className="bh-more-inner flex flex-col">
        <div aria-hidden="true" className="mx-auto mb-3 h-1 w-10 shrink-0 rounded-full bg-slate-300" />
        <div className="flex shrink-0 items-center justify-between gap-2 border-b border-slate-200 pb-3"><div><h2 className="text-lg font-bold">Khác</h2><p className="text-xs text-slate-500">Truy cập nhanh các chức năng</p></div><button type="button" onClick={() => setDrawerOpen(false)} aria-label="Đóng menu" className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl border border-slate-200 bg-slate-50 focus-visible:ring-2 focus-visible:ring-teal-700"><X size={22} /></button></div>
        <div className="min-h-0 flex-1 overflow-y-auto overscroll-contain pt-2"><Navigation pathname={pathname} onNavigate={closeMenus} canManageTemplates={Boolean(me?.landlord)} overflowOnly /></div>
      </div>
    </dialog>
    <section className="min-w-0 flex-1">
      <header className="bh-topbar bh-mobile-topbar sticky top-0 z-30 flex min-h-16 items-center justify-between gap-3 border-b border-slate-200 bg-white/95 px-4 py-2 backdrop-blur-xl [padding-top:max(.5rem,env(safe-area-inset-top))] lg:static lg:min-h-0 lg:p-4">
        <div className="flex min-w-0 items-center gap-2 lg:hidden">
          <Link to="/dashboard" onClick={closeMenus} className="flex min-h-11 min-w-0 items-center gap-2.5"><img src={beeHouseLogo} width="34" height="34" alt="" className="h-9 w-9 shrink-0 rounded-lg bg-white object-contain" /><span className="flex min-w-0 flex-col"><span className="truncate text-base font-bold leading-tight tracking-tight text-slate-900">{APP_NAME}</span><span className="bh-mobile-subtitle truncate text-[10px] font-medium leading-4 text-slate-500">Quản lý nhà trọ</span></span></Link>
        </div>
        <span className="hidden min-w-0 break-words lg:block">Xin chào, {userName}</span>
        <div ref={userMenuRef} className="relative shrink-0">
          <button ref={userButtonRef} type="button" aria-label="Menu tài khoản" aria-haspopup="menu" aria-expanded={userMenuOpen} aria-controls="mobile-user-menu" onClick={() => setUserMenuOpen((open) => !open)} className="bh-account-trigger flex h-11 w-11 items-center justify-center rounded-full border border-slate-200 bg-white text-slate-700 focus-visible:ring-2 focus-visible:ring-teal-700 lg:w-auto lg:gap-2 lg:rounded-lg lg:px-3"><UserRound size={21} /><span className="hidden max-w-48 truncate text-sm lg:block">{userName}</span><span aria-hidden="true" className="hidden lg:block">▾</span></button>
          {userMenuOpen && <div id="mobile-user-menu" role="menu" onKeyDown={menuKeys} className="bh-account-menu absolute right-0 top-full mt-2 w-[min(256px,calc(100vw-32px))] rounded-xl border border-slate-200 bg-white p-2 shadow-lg">
            <p className="border-b border-slate-100 px-3 py-3 text-sm font-semibold [overflow-wrap:anywhere]">{userName}</p>
            {me?.landlord && <Link role="menuitem" to="/payment-settings" onClick={closeMenus} className="mt-1 flex min-h-11 items-center gap-2 rounded-lg px-3 py-2 text-sm hover:bg-slate-100"><CreditCard size={18} />Cài đặt thanh toán</Link>}
            <Link role="menuitem" to="/change-password" onClick={closeMenus} className="mt-1 flex min-h-11 items-center gap-2 rounded-lg px-3 py-2 text-sm hover:bg-slate-100"><KeyRound size={18} />Đổi mật khẩu</Link>
            <button role="menuitem" type="button" onClick={logoutFromMenu} disabled={mutation.isPending} className="flex min-h-11 w-full items-center gap-2 rounded-lg px-3 py-2 text-sm text-red-700 hover:bg-red-50 disabled:opacity-60"><LogOut size={18} />{mutation.isPending ? 'Đang đăng xuất...' : 'Đăng xuất'}</button>
          </div>}
        </div>
      </header>
      <main id="app-main" tabIndex="-1" className="app-content bh-mobile-nav-content min-w-0 p-4 [padding-bottom:max(1rem,env(safe-area-inset-bottom))] sm:p-5 lg:p-6"><Outlet /></main>
    </section>
    <MobileBottomNav pathname={pathname} onNavigate={closeMenus} drawerOpen={drawerOpen} onOpenMore={() => { setUserMenuOpen(false); setDrawerOpen(open => !open) }} />
  </div>
}
