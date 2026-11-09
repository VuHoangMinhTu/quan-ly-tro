import { useEffect, useId, useLayoutEffect, useRef, useState } from 'react'
import { createPortal } from 'react-dom'
import { CalendarDays, ChevronDown, ChevronLeft, ChevronRight } from 'lucide-react'
import { daysInMonth, formatBillingPeriodForDisplay, getTodayForDisplay, parseDisplayDate } from '../../utils/date'

const weekdayLabels = ['T2', 'T3', 'T4', 'T5', 'T6', 'T7', 'CN']
const monthLabels = Array.from({ length: 12 }, (_, index) => `Tháng ${String(index + 1).padStart(2, '0')}`)
const billingPeriodPattern = /^(0[1-9]|1[0-2])\/(\d{4})$/

function getTodayParts() {
  const today = parseDisplayDate(getTodayForDisplay())
  return { month: today.month, year: today.year }
}

function datePartsFromValue(value) {
  const parsed = parseDisplayDate(value)
  return parsed ? { day: parsed.day, month: parsed.month, year: parsed.year } : null
}

function billingPeriodFromValue(value) {
  const displayValue = billingPeriodPattern.test(value) ? value : formatBillingPeriodForDisplay(value)
  const match = billingPeriodPattern.exec(displayValue)
  return match ? { month: Number(match[1]), year: Number(match[2]) } : null
}

function firstWeekday(year, month) {
  const monthOffsets = [0, 3, 2, 5, 0, 3, 5, 1, 4, 6, 2, 4]
  const adjustedYear = month < 3 ? year - 1 : year
  const sundayFirst = (adjustedYear + Math.floor(adjustedYear / 4) - Math.floor(adjustedYear / 100) + Math.floor(adjustedYear / 400) + monthOffsets[month - 1] + 1) % 7
  return (sundayFirst + 6) % 7
}

function moveMonth({ year, month }, direction) {
  if (month + direction === 0) return { year: year - 1, month: 12 }
  if (month + direction === 13) return { year: year + 1, month: 1 }
  return { year, month: month + direction }
}

function selectorButtonClass(isActive) {
  return `min-h-11 rounded-lg border px-2 py-2 text-sm lg:min-h-0 transition-colors focus:outline-none focus:ring-2 focus:ring-slate-400 ${isActive ? 'border-slate-900 bg-slate-900 text-white hover:bg-slate-800' : 'border-slate-200 hover:border-slate-400 hover:bg-slate-50'}`
}

export default function DateInput({ id, value = '', onChange, onBlur, placeholder, mode = 'date', invalid = false }) {
  const containerRef = useRef(null)
  const triggerRef = useRef(null)
  const pickerRef = useRef(null)
  const pickerId = useId()
  const [popupStyle, setPopupStyle] = useState({ top: 0, left: 0, width: 320, maxHeight: 600 })
  const [isOpen, setIsOpen] = useState(false)
  const [pickerView, setPickerView] = useState('calendar')
  const [visiblePeriod, setVisiblePeriod] = useState(getTodayParts)
  const [pendingPeriod, setPendingPeriod] = useState(null)
  const [periodSelections, setPeriodSelections] = useState({ month: false, year: false })
  const [yearPageStart, setYearPageStart] = useState(getTodayParts().year - 10)
  const isMonthMode = mode === 'month'
  const selectedDate = datePartsFromValue(value)
  const selectedPeriod = billingPeriodFromValue(value)

  useEffect(() => {
    if (!isOpen) return undefined

    const closeOnOutsideClick = (event) => {
      const clickedInput = containerRef.current?.contains(event.target)
      const clickedPicker = pickerRef.current?.contains(event.target)
      if (!clickedInput && !clickedPicker) setIsOpen(false)
    }
    const closeOnEscape = (event) => {
      if (event.key !== 'Escape') return
      event.stopPropagation()
      setIsOpen(false)
      triggerRef.current?.focus({ preventScroll: true })
    }

    document.addEventListener('pointerdown', closeOnOutsideClick)
    document.addEventListener('keydown', closeOnEscape)
    return () => {
      document.removeEventListener('pointerdown', closeOnOutsideClick)
      document.removeEventListener('keydown', closeOnEscape)
    }
  }, [isOpen])

  // The calendar is portalled to <body>, outside the scrolling RoomOccupants modal.
  // Calculate its viewport position and flip above the input when space is tight.
  useLayoutEffect(() => {
    if (!isOpen) return undefined

    const reposition = () => {
      const trigger = triggerRef.current
      const picker = pickerRef.current
      if (!trigger || !picker) return

      const padding = 12
      const gap = 8
      const viewportWidth = document.documentElement.clientWidth
      const viewportHeight = window.innerHeight
      const width = Math.min(viewportWidth < 1024 ? 360 : 320, viewportWidth - 2 * padding)
      const naturalHeight = picker.scrollHeight

      if (viewportWidth < 1024) {
        const maxHeight = Math.max(0, viewportHeight - 2 * padding)
        const actualHeight = Math.min(naturalHeight, maxHeight)
        const nextStyle = {
          top: Math.max(padding, (viewportHeight - actualHeight) / 2),
          left: Math.max(padding, (viewportWidth - width) / 2),
          width,
          maxHeight,
        }
        setPopupStyle((old) => Object.keys(nextStyle).every((key) => old[key] === nextStyle[key]) ? old : nextStyle)
        return
      }

      const rect = trigger.getBoundingClientRect()
      const availableBelow = Math.max(0, viewportHeight - rect.bottom - gap - padding)
      const availableAbove = Math.max(0, rect.top - gap - padding)
      const showBelow = availableBelow >= naturalHeight || availableBelow >= availableAbove
      const maxHeight = Math.min(naturalHeight, Math.max(0, showBelow ? availableBelow : availableAbove))
      const idealTop = showBelow ? rect.bottom + gap : rect.top - gap - maxHeight
      const nextStyle = {
        top: Math.max(padding, Math.min(idealTop, viewportHeight - maxHeight - padding)),
        left: Math.max(padding, Math.min(rect.right - width, viewportWidth - width - padding)),
        width,
        maxHeight,
      }
      setPopupStyle((old) => Object.keys(nextStyle).every((key) => old[key] === nextStyle[key]) ? old : nextStyle)
    }

    reposition()
    window.addEventListener('resize', reposition)
    window.addEventListener('scroll', reposition, true)
    const observer = typeof ResizeObserver !== 'undefined' ? new ResizeObserver(reposition) : null
    if (pickerRef.current) observer?.observe(pickerRef.current)

    return () => {
      window.removeEventListener('resize', reposition)
      window.removeEventListener('scroll', reposition, true)
      observer?.disconnect()
    }
  }, [isOpen, pickerView, visiblePeriod.month, visiblePeriod.year, yearPageStart])

  useEffect(() => {
    if (isOpen) pickerRef.current?.querySelector('button')?.focus({ preventScroll: true })
  }, [isOpen])

  const openPicker = () => {
    const today = getTodayParts()
    const basePeriod = isMonthMode ? selectedPeriod || today : selectedDate || today
    setVisiblePeriod({ month: basePeriod.month, year: basePeriod.year })
    setPendingPeriod(isMonthMode ? selectedPeriod : null)
    setPeriodSelections({ month: false, year: false })
    setYearPageStart(basePeriod.year - 10)
    setPickerView(isMonthMode ? 'months' : 'calendar')
    setIsOpen(true)
  }

  const selectDate = (day) => {
    onChange(`${String(day).padStart(2, '0')}/${String(visiblePeriod.month).padStart(2, '0')}/${visiblePeriod.year}`)
    setIsOpen(false)
    triggerRef.current?.focus({ preventScroll: true })
  }

  const commitBillingPeriod = (period) => {
    onChange(`${String(period.month).padStart(2, '0')}/${period.year}`)
    setIsOpen(false)
    triggerRef.current?.focus({ preventScroll: true })
  }

  const selectMonth = (month) => {
    setVisiblePeriod((current) => ({ ...current, month }))
    if (!isMonthMode) { setPickerView('calendar'); return }
    const period = { month, year: pendingPeriod?.year ?? selectedPeriod?.year ?? visiblePeriod.year }
    setPendingPeriod(period)
    if (periodSelections.year) { commitBillingPeriod(period); return }
    setPeriodSelections((current) => ({ ...current, month: true }))
    setPickerView('years')
  }

  const selectYear = (year) => {
    setVisiblePeriod((current) => ({ ...current, year }))
    if (!isMonthMode) { setPickerView('calendar'); return }
    const period = { month: pendingPeriod?.month ?? selectedPeriod?.month, year }
    setPendingPeriod(period)
    if (period.month && periodSelections.month) { commitBillingPeriod(period); return }
    setPeriodSelections((current) => ({ ...current, year: true }))
    setPickerView('months')
  }

  const navigate = (direction) => {
    if (pickerView === 'years') { setYearPageStart((year) => year + direction * 21); return }
    setVisiblePeriod((current) => isMonthMode ? { ...current, year: current.year + direction } : moveMonth(current, direction))
  }

  const calendarDays = Array.from({ length: firstWeekday(visiblePeriod.year, visiblePeriod.month) + daysInMonth(visiblePeriod.year, visiblePeriod.month) }, (_, index) => {
    const day = index - firstWeekday(visiblePeriod.year, visiblePeriod.month) + 1
    return day > 0 ? day : null
  })
  const yearOptions = Array.from({ length: 21 }, (_, index) => yearPageStart + index)
  const activeMonth = isMonthMode ? (pendingPeriod?.month ?? selectedPeriod?.month ?? visiblePeriod.month) : visiblePeriod.month
  const activeYear = isMonthMode ? (pendingPeriod?.year ?? selectedPeriod?.year ?? visiblePeriod.year) : visiblePeriod.year

  return <><div ref={containerRef} className="relative min-w-0"><div className="relative"><input id={id} value={value} onChange={(event) => onChange(event.target.value)} onBlur={onBlur} placeholder={placeholder} aria-invalid={invalid} className={`mt-1 min-h-11 w-full min-w-0 rounded-lg border px-3 py-2 pr-11 lg:min-h-0 ${invalid ? 'border-red-500' : 'border-slate-300'}`} /><button ref={triggerRef} type="button" onClick={openPicker} aria-haspopup="dialog" aria-expanded={isOpen} aria-controls={isOpen ? pickerId : undefined} aria-label={isMonthMode ? 'Chọn kỳ hóa đơn' : 'Chọn ngày'} className="absolute inset-y-0 right-0 mt-1 flex w-11 items-center justify-center rounded-r-lg text-slate-500 hover:bg-slate-100 hover:text-slate-700 focus:outline-none focus:ring-2 focus:ring-slate-400"><CalendarDays size={18} aria-hidden="true" /></button></div></div>{isOpen && createPortal(<div ref={pickerRef} id={pickerId} role="dialog" onClick={(event) => event.stopPropagation()} aria-label={isMonthMode ? 'Chọn tháng và năm' : 'Chọn ngày tháng'} style={popupStyle} className="fixed z-[100] overscroll-contain overflow-y-auto rounded-xl border border-slate-200 bg-white p-3 shadow-2xl"><div className="mb-3 flex items-center justify-between gap-1"><button type="button" onClick={() => navigate(-1)} className="flex h-11 w-11 shrink-0 items-center justify-center rounded text-slate-600 lg:h-auto lg:w-auto lg:p-1 hover:bg-slate-100 focus:outline-none focus:ring-2 focus:ring-slate-400" aria-label={pickerView === 'years' ? 'Các năm trước' : isMonthMode ? 'Năm trước' : 'Tháng trước'}><ChevronLeft size={18} /></button><div className="flex min-w-0 items-center justify-center gap-1"><button type="button" onClick={() => setPickerView('months')} className="inline-flex min-h-11 items-center gap-1 rounded px-1 py-1 text-sm font-semibold lg:min-h-0 sm:px-2 hover:bg-slate-100 focus:outline-none focus:ring-2 focus:ring-slate-400">{monthLabels[visiblePeriod.month - 1]}<ChevronDown size={14} /></button><button type="button" onClick={() => { setYearPageStart(visiblePeriod.year - 10); setPickerView('years') }} className="inline-flex min-h-11 items-center gap-1 rounded px-1 py-1 text-sm font-semibold lg:min-h-0 sm:px-2 hover:bg-slate-100 focus:outline-none focus:ring-2 focus:ring-slate-400">{visiblePeriod.year}<ChevronDown size={14} /></button></div><button type="button" onClick={() => navigate(1)} className="flex h-11 w-11 shrink-0 items-center justify-center rounded text-slate-600 lg:h-auto lg:w-auto lg:p-1 hover:bg-slate-100 focus:outline-none focus:ring-2 focus:ring-slate-400" aria-label={pickerView === 'years' ? 'Các năm sau' : isMonthMode ? 'Năm sau' : 'Tháng sau'}><ChevronRight size={18} /></button></div>{pickerView === 'calendar' && <><div className="grid grid-cols-7 text-center text-xs font-medium text-slate-500">{weekdayLabels.map((label) => <span key={label} className="py-1">{label}</span>)}</div><div className="grid grid-cols-7 gap-0.5 sm:gap-1">{calendarDays.map((day, index) => day ? <button key={day} type="button" onClick={() => selectDate(day)} className={`min-h-10 rounded-md lg:aspect-square lg:min-h-0 text-sm hover:bg-slate-100 focus:outline-none focus:ring-2 focus:ring-slate-400 ${selectedDate?.day === day && selectedDate.month === visiblePeriod.month && selectedDate.year === visiblePeriod.year ? 'bg-slate-900 text-white hover:bg-slate-800' : ''}`}>{day}</button> : <span key={`empty-${index}`} />)}</div></>}{pickerView === 'months' && <div className="grid grid-cols-3 gap-2">{monthLabels.map((label, index) => <button key={label} type="button" onClick={() => selectMonth(index + 1)} className={selectorButtonClass(activeMonth === index + 1)}>{label}</button>)}</div>}{pickerView === 'years' && <div className="grid grid-cols-3 gap-2">{yearOptions.map((year) => <button key={year} type="button" onClick={() => selectYear(year)} className={selectorButtonClass(activeYear === year)}>{year}</button>)}</div>}</div>, document.body)}</>
}
