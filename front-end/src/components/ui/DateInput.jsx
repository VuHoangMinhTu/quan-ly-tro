import { useEffect, useRef, useState } from 'react'
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
  return `rounded-lg border px-2 py-2 text-sm transition-colors focus:outline-none focus:ring-2 focus:ring-slate-400 ${isActive ? 'border-slate-900 bg-slate-900 text-white hover:bg-slate-800' : 'border-slate-200 hover:border-slate-400 hover:bg-slate-50'}`
}

export default function DateInput({ value = '', onChange, onBlur, placeholder, mode = 'date', invalid = false }) {
  const containerRef = useRef(null)
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
    const closeOnOutsideClick = (event) => { if (!containerRef.current?.contains(event.target)) setIsOpen(false) }
    const closeOnEscape = (event) => { if (event.key === 'Escape') setIsOpen(false) }
    document.addEventListener('mousedown', closeOnOutsideClick)
    document.addEventListener('keydown', closeOnEscape)
    return () => { document.removeEventListener('mousedown', closeOnOutsideClick); document.removeEventListener('keydown', closeOnEscape) }
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
  }

  const commitBillingPeriod = (period) => {
    onChange(`${String(period.month).padStart(2, '0')}/${period.year}`)
    setIsOpen(false)
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

  return <div ref={containerRef} className="relative"><div className="relative"><input value={value} onChange={(event) => onChange(event.target.value)} onBlur={onBlur} placeholder={placeholder} aria-invalid={invalid} className={`mt-1 w-full rounded-lg border px-3 py-2 pr-11 ${invalid ? 'border-red-500' : 'border-slate-300'}`} /><button type="button" onClick={openPicker} aria-label={isMonthMode ? 'Chọn kỳ hóa đơn' : 'Chọn ngày'} className="absolute inset-y-0 right-0 mt-1 flex w-10 items-center justify-center rounded-r-lg text-slate-500 hover:bg-slate-100 hover:text-slate-700 focus:outline-none focus:ring-2 focus:ring-slate-400"><CalendarDays size={18} aria-hidden="true" /></button></div>{isOpen && <div className="absolute right-0 z-20 mt-2 w-80 max-w-[calc(100vw-3rem)] rounded-xl border border-slate-200 bg-white p-3 shadow-lg"><div className="mb-3 flex items-center justify-between gap-1"><button type="button" onClick={() => navigate(-1)} className="rounded p-1 text-slate-600 hover:bg-slate-100 focus:outline-none focus:ring-2 focus:ring-slate-400" aria-label={pickerView === 'years' ? 'Các năm trước' : isMonthMode ? 'Năm trước' : 'Tháng trước'}><ChevronLeft size={18} /></button><div className="flex min-w-0 items-center justify-center gap-1"><button type="button" onClick={() => setPickerView('months')} className="inline-flex items-center gap-1 rounded px-2 py-1 text-sm font-semibold hover:bg-slate-100 focus:outline-none focus:ring-2 focus:ring-slate-400">{monthLabels[visiblePeriod.month - 1]}<ChevronDown size={14} /></button><button type="button" onClick={() => { setYearPageStart(visiblePeriod.year - 10); setPickerView('years') }} className="inline-flex items-center gap-1 rounded px-2 py-1 text-sm font-semibold hover:bg-slate-100 focus:outline-none focus:ring-2 focus:ring-slate-400">{visiblePeriod.year}<ChevronDown size={14} /></button></div><button type="button" onClick={() => navigate(1)} className="rounded p-1 text-slate-600 hover:bg-slate-100 focus:outline-none focus:ring-2 focus:ring-slate-400" aria-label={pickerView === 'years' ? 'Các năm sau' : isMonthMode ? 'Năm sau' : 'Tháng sau'}><ChevronRight size={18} /></button></div>{pickerView === 'calendar' && <><div className="grid grid-cols-7 text-center text-xs font-medium text-slate-500">{weekdayLabels.map((label) => <span key={label} className="py-1">{label}</span>)}</div><div className="grid grid-cols-7 gap-1">{calendarDays.map((day, index) => day ? <button key={day} type="button" onClick={() => selectDate(day)} className={`aspect-square rounded-md text-sm hover:bg-slate-100 focus:outline-none focus:ring-2 focus:ring-slate-400 ${selectedDate?.day === day && selectedDate.month === visiblePeriod.month && selectedDate.year === visiblePeriod.year ? 'bg-slate-900 text-white hover:bg-slate-800' : ''}`}>{day}</button> : <span key={`empty-${index}`} />)}</div></>}{pickerView === 'months' && <div className="grid grid-cols-3 gap-2">{monthLabels.map((label, index) => <button key={label} type="button" onClick={() => selectMonth(index + 1)} className={selectorButtonClass(activeMonth === index + 1)}>{label}</button>)}</div>}{pickerView === 'years' && <div className="grid grid-cols-3 gap-2">{yearOptions.map((year) => <button key={year} type="button" onClick={() => selectYear(year)} className={selectorButtonClass(activeYear === year)}>{year}</button>)}</div>}</div>}</div>
}
