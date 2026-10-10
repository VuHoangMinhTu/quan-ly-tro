import { applyApiFieldErrors, getApiErrorMessage } from '../../api/getApiErrorMessage'
import { resetPassword } from '../../api/authApi'
import PasswordInput from '../../components/ui/PasswordInput'
import { zodResolver } from '@hookform/resolvers/zod'
import { useMutation } from '@tanstack/react-query'
import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { z } from 'zod'

const schema = z.object({
  email: z.string().min(1, 'Vui lòng nhập email.').email('Email không đúng định dạng.'),
  password: z.string().min(8, 'Mật khẩu phải có ít nhất 8 ký tự.'),
  password_confirmation: z.string().min(1, 'Vui lòng xác nhận mật khẩu mới.'),
}).refine((values) => values.password === values.password_confirmation, {
  path: ['password_confirmation'],
  message: 'Mật khẩu xác nhận không khớp.',
})

export default function ResetPasswordPage() {
  const navigate = useNavigate()
  const [searchParams] = useSearchParams()
  const token = searchParams.get('token') || ''
  const email = searchParams.get('email') || ''
  const [generalError, setGeneralError] = useState('')
  const form = useForm({
    resolver: zodResolver(schema),
    defaultValues: { email, password: '', password_confirmation: '' },
  })

  const mutation = useMutation({
    mutationFn: resetPassword,
    onMutate: () => setGeneralError(''),
    onSuccess: () => {
      navigate('/login', {
        replace: true,
        state: { message: 'Đặt lại mật khẩu thành công. Vui lòng đăng nhập lại.' },
      })
    },
    onError: (error) => {
      form.clearErrors()
      if (!applyApiFieldErrors(error, form.setError)) {
        setGeneralError(getApiErrorMessage(error))
      }
    },
  })

  if (!token || !email) {
    return (
      <main className="flex min-h-screen items-center justify-center p-4">
        <section className="w-full max-w-md rounded-xl bg-white p-8 shadow">
          <h1 className="text-2xl font-bold">Liên kết không hợp lệ</h1>
          <p className="mt-3 text-sm leading-6 text-slate-600">
            Liên kết đặt lại mật khẩu không hợp lệ hoặc đã hết hạn. Vui lòng yêu cầu liên kết mới.
          </p>
          <Link to="/forgot-password" className="mt-6 inline-block rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white">
            Yêu cầu liên kết mới
          </Link>
        </section>
      </main>
    )
  }

  return (
    <main className="flex min-h-screen items-center justify-center p-4">
      <section className="w-full max-w-md rounded-xl bg-white p-8 shadow">
        <h1 className="text-2xl font-bold">Đặt lại mật khẩu</h1>
        <p className="mt-3 text-sm text-slate-600">Chọn mật khẩu mới cho tài khoản BeeHouse của bạn.</p>

        <form
          className="mt-6 space-y-4"
          onSubmit={form.handleSubmit((values) => mutation.mutate({ ...values, token }))}
        >
          <label className="block text-sm font-medium">
            Email
            <input
              readOnly
              className="mt-1 w-full cursor-default rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-slate-600"
              {...form.register('email')}
            />
          </label>

          <label className="block text-sm font-medium">
            Mật khẩu mới <span className="text-red-600">*</span>
            <PasswordInput
              className="mt-1"
              autoComplete="new-password"
              aria-invalid={Boolean(form.formState.errors.password)}
              {...form.register('password', {
                onChange: () => form.clearErrors('password'),
              })}
            />
            {form.formState.errors.password && <p className="mt-1 text-sm text-red-600">{form.formState.errors.password.message}</p>}
          </label>

          <label className="block text-sm font-medium">
            Xác nhận mật khẩu <span className="text-red-600">*</span>
            <PasswordInput
              className="mt-1"
              autoComplete="new-password"
              aria-invalid={Boolean(form.formState.errors.password_confirmation)}
              {...form.register('password_confirmation', {
                onChange: () => form.clearErrors('password_confirmation'),
              })}
            />
            {form.formState.errors.password_confirmation && <p className="mt-1 text-sm text-red-600">{form.formState.errors.password_confirmation.message}</p>}
          </label>

          {generalError && (
            <div role="alert" className="rounded-lg bg-red-50 p-3 text-sm leading-6 text-red-700">
              <p>{generalError}</p>
              <Link to="/forgot-password" className="mt-1 inline-block font-medium underline">
                Yêu cầu liên kết mới
              </Link>
            </div>
          )}

          <button
            disabled={mutation.isPending}
            className="w-full rounded-lg bg-slate-900 px-4 py-2 font-medium text-white disabled:cursor-not-allowed disabled:opacity-60"
          >
            {mutation.isPending ? 'Đang đặt lại mật khẩu...' : 'Đặt lại mật khẩu'}
          </button>
        </form>
      </section>
    </main>
  )
}
