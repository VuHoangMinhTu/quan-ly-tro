import { applyApiFieldErrors, getApiErrorMessage } from '../../api/getApiErrorMessage'
import { useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { Link, useNavigate } from 'react-router-dom'
import { register } from '../../api/authApi'
const schema = z.object({
  name: z.string().min(1, 'Vui lòng nhập họ tên').max(255),
  email: z.string().min(1, 'Vui lòng nhập email').email('Email không hợp lệ'),
  phone: z.string().min(1, 'Vui lòng nhập số điện thoại').max(30).regex(/^[0-9+()\-\s]+$/, 'Số điện thoại không hợp lệ'),
  password: z.string().min(8, 'Mật khẩu tối thiểu 8 ký tự'),
})

const fields = [
  ['name', 'Họ tên', 'text'],
  ['email', 'Email', 'email'],
  ['phone', 'Số điện thoại', 'tel'],
  ['password', 'Mật khẩu', 'password'],
]

export default function RegisterPage() {
  const navigate = useNavigate()
  const form = useForm({ resolver: zodResolver(schema) })
  const [generalError, setGeneralError] = useState('')
  const mutation = useMutation({
    mutationFn: register,
    onMutate: () => setGeneralError(''),
    onSuccess: (_, data) => navigate('/verify-email-sent', { state: { email: data.email } }),
    onError: (error) => {
      form.clearErrors()
      setGeneralError('')
      // Field validation belongs only under the matching inputs.
      if (applyApiFieldErrors(error, form.setError)) return
      setGeneralError(getApiErrorMessage(error))
    },
  })

  return (
    <main className="flex min-h-dvh items-center justify-center p-4">
      <form
        onSubmit={form.handleSubmit((data) => mutation.mutate(data))}
        className="w-full min-w-0 max-w-md rounded-xl bg-white p-5 sm:p-8 shadow"
      >
        <h1 className="mb-6 text-xl sm:text-2xl font-bold">Đăng ký tài khoản</h1>
        {fields.map(([field, label, type]) => {
          const fieldError = form.formState.errors[field]

          return (
            <label key={field} className="mb-3 block">
              {label}
              <input
                type={type}
                className="min-h-11 sm:min-h-0 mt-1 mb-1 w-full min-w-0 rounded border p-2"
                aria-invalid={Boolean(fieldError)}
                aria-describedby={fieldError ? `${field}-error` : undefined}
                {...form.register(field, {
                  onChange: () => {
                    if (form.formState.errors[field]?.type === 'server') {
                      form.clearErrors(field)
                    }
                  },
                })}
              />
              {fieldError && (
                <p id={`${field}-error`} className="text-sm text-red-600">
                  {fieldError.message}
                </p>
              )}
            </label>
          )
        })}
        {generalError && (
          <div role="alert" className="mb-3 rounded border border-red-200 bg-red-50 p-3 text-sm text-red-700">
            {generalError}
          </div>
        )}
        <button disabled={mutation.isPending} className="min-h-11 sm:min-h-0 w-full min-w-0 rounded bg-slate-900 p-2 text-white disabled:opacity-60">
          {mutation.isPending ? 'Đang đăng ký...' : 'Đăng ký'}
        </button>
        <p className="mt-4 text-center text-sm">
          Đã có tài khoản? <Link className="inline-flex min-h-11 items-center font-medium underline lg:min-h-0" to="/login">Đăng nhập</Link>
        </p>
      </form>
    </main>
  )
}
