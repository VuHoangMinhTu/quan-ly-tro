import { forwardRef, useEffect, useImperativeHandle, useRef } from 'react'

// Store normalized strokes, not screenshots: resize/orientation changes redraw without losing ink.
const SignatureCanvas = forwardRef(function SignatureCanvas({ onChange, disabled }, ref) {
  const canvas = useRef(null)
  const strokes = useRef([])
  const activePointer = useRef(null)
  const redraw = () => {
    const field = canvas.current
    if (!field) return
    const ctx = field.getContext('2d')
    ctx.fillStyle = '#ffffff'; ctx.fillRect(0, 0, field.width, field.height)
    ctx.strokeStyle = '#0f172a'; ctx.fillStyle = '#0f172a'
    ctx.lineWidth = Math.max(2, field.width / 240); ctx.lineCap = 'round'; ctx.lineJoin = 'round'
    for (const stroke of strokes.current) {
      ctx.beginPath()
      stroke.forEach(([x, y], index) => index ? ctx.lineTo(x * field.width, y * field.height) : ctx.moveTo(x * field.width, y * field.height))
      ctx.stroke()
      if (stroke.length === 1) { const [x, y] = stroke[0]; ctx.beginPath(); ctx.arc(x * field.width, y * field.height, ctx.lineWidth / 2, 0, Math.PI * 2); ctx.fill() }
    }
  }
  useEffect(() => {
    const field = canvas.current
    const resize = () => {
      const width = field.getBoundingClientRect().width
      const ratio = Math.min(window.devicePixelRatio || 1, 2)
      field.width = Math.min(1800, Math.round(width * ratio))
      field.height = Math.round(field.width * 0.45)
      redraw()
    }
    const observer = new ResizeObserver(resize)
    observer.observe(field); resize()
    return () => observer.disconnect()
  }, [])
  useImperativeHandle(ref, () => ({
    clear() { strokes.current = []; activePointer.current = null; redraw(); onChange(false) },
    exportPng() { return new Promise(resolve => strokes.current.length ? canvas.current.toBlob(resolve, 'image/png') : resolve(null)) },
  }))
  const point = event => { const rect = canvas.current.getBoundingClientRect(); return [Math.max(0, Math.min(1, (event.clientX - rect.left) / rect.width)), Math.max(0, Math.min(1, (event.clientY - rect.top) / rect.height))] }
  return <canvas ref={canvas} aria-label="Vùng vẽ chữ ký bằng chuột hoặc chạm tay" role="img"
    className={`block aspect-[20/9] w-full rounded-lg border-2 border-dashed border-slate-300 bg-white ${disabled ? 'opacity-60' : 'cursor-crosshair'}`}
    style={{ touchAction: 'none' }}
    onPointerDown={event => { if (disabled || activePointer.current !== null) return; event.preventDefault(); activePointer.current = event.pointerId; event.currentTarget.setPointerCapture(event.pointerId); strokes.current.push([point(event)]); redraw(); onChange(true) }}
    onPointerMove={event => { if (disabled || activePointer.current !== event.pointerId) return; event.preventDefault(); strokes.current.at(-1).push(point(event)); redraw() }}
    onPointerUp={event => { if (activePointer.current === event.pointerId) activePointer.current = null }}
    onPointerCancel={() => { activePointer.current = null }} />
})
export default SignatureCanvas
