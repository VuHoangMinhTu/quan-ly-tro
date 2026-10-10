import { Eye, EyeOff } from 'lucide-react'
import { forwardRef, useState } from 'react'

const PasswordInput = forwardRef(function PasswordInput({ className = '', ...props }, ref) {
  const [isVisible, setIsVisible] = useState(false)

  return (
    <div className="relative">
      <input
        {...props}
        ref={ref}
        type={isVisible ? 'text' : 'password'}
        className={'w-full rounded-lg border border-slate-300 px-3 py-2 pr-11 outline-none transition focus:border-slate-600 focus:ring-2 focus:ring-slate-200 ' + className}
      />
      <button
        type="button"
        onClick={() => setIsVisible((visible) => !visible)}
        className="absolute inset-y-0 right-0 flex w-11 items-center justify-center text-slate-500 transition hover:text-slate-800 focus:outline-none focus:ring-2 focus:ring-inset focus:ring-slate-300"
        aria-label={isVisible ? 'Ẩn mật khẩu' : 'Hiển thị mật khẩu'}
      >
        {isVisible ? <EyeOff size={18} aria-hidden="true" /> : <Eye size={18} aria-hidden="true" />}
      </button>
    </div>
  )
})

export default PasswordInput
