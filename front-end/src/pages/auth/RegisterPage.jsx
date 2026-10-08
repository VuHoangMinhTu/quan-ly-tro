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
  const mutation = useMutation({
    mutationFn: register,
    onSuccess: (_, data) => navigate('/verify-email-sent', { state: { email: data.email } }),
    onError: (error) => {
      const response = error.response?.data
      const fieldErrors = Object.entries(response?.errors ?? {}).filter(
        ([, messages]) => Array.isArray(messages) && messages.length > 0,
      )

      form.clearErrors()

      // Laravel validation messages belong to their fields, not the form summary.
      if (fieldErrors.length > 0) {
        fieldErrors.forEach(([field, messages]) => {
          form.setError(field, { type: 'server', message: messages[0] })
        })
        return
      }

      form.setError('root.server', {
        type: 'server',
        message: response?.message || 'Đăng ký không thành công. Vui lòng thử lại.',
      })
    },
  })
  const generalError = form.formState.errors.root?.server?.message

  return (
    <main className="flex min-h-screen items-center justify-center p-4">
      <form
        onSubmit={form.handleSubmit((data) => mutation.mutate(data))}
        className="w-full max-w-md rounded-xl bg-white p-8 shadow"
      >
        <h1 className="mb-6 text-2xl font-bold">Đăng ký tài khoản</h1>
        {fields.map(([field, label, type]) => {
          const fieldError = form.formState.errors[field]

          return (
            <label key={field} className="mb-3 block">
              {label}
              <input
                type={type}
                className="mt-1 mb-1 w-full rounded border p-2"
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
        <button disabled={mutation.isPending} className="w-full rounded bg-slate-900 p-2 text-white disabled:opacity-60">
          {mutation.isPending ? 'Đang đăng ký...' : 'Đăng ký'}
        </button>
        <p className="mt-4 text-center text-sm">
          Đã có tài khoản? <Link className="font-medium underline" to="/login">Đăng nhập</Link>
        </p>
      </form>
    </main>
  )
}
