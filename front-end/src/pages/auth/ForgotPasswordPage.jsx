import { applyApiFieldErrors, getApiErrorMessage } from '../../api/getApiErrorMessage'
import { forgotPassword } from '../../api/authApi'
import { zodResolver } from '@hookform/resolvers/zod'
import { useMutation } from '@tanstack/react-query'
import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { Link } from 'react-router-dom'
import { z } from 'zod'

const schema = z.object({
  email: z.string().min(1, 'Vui lòng nhập email.').email('Email không đúng định dạng.'),
})

export default function ForgotPasswordPage() {
  const form = useForm({ resolver: zodResolver(schema), defaultValues: { email: '' } })
  const [generalError, setGeneralError] = useState('')

  const mutation = useMutation({
    mutationFn: forgotPassword,
    onMutate: () => setGeneralError(''),
    onSuccess: () => form.reset(),
    onError: (error) => {
      form.clearErrors()
      if (!applyApiFieldErrors(error, form.setError)) {
        setGeneralError(getApiErrorMessage(error))
      }
    },
  })

  const successMessage = mutation.data?.data?.message

  return (
    <main className="flex min-h-dvh items-center justify-center p-4">
      <section className="w-full min-w-0 max-w-md rounded-xl bg-white p-5 sm:p-8 shadow">
        <h1 className="text-xl sm:text-2xl font-bold">Quên mật khẩu</h1>
        <p className="mt-3 text-sm leading-6 text-slate-600">
          Nhập địa chỉ email đã đăng ký. BeeHouse sẽ gửi cho bạn liên kết để đặt lại mật khẩu.
        </p>

        <form className="min-w-0 break-words mt-6 space-y-4" onSubmit={form.handleSubmit((values) => mutation.mutate(values))}>
          <label className="block text-sm font-medium">
            Email <span className="text-red-600">*</span>
            <input
              type="email"
              autoComplete="email"
              className="mt-1 min-h-11 w-full min-w-0 rounded-lg border border-slate-300 px-3 py-2 text-base outline-none transition focus:border-slate-600 focus:ring-2 focus:ring-slate-200 sm:min-h-0 sm:text-sm"
              aria-invalid={Boolean(form.formState.errors.email)}
              {...form.register('email', {
                onChange: () => form.clearErrors('email'),
              })}
            />
            {form.formState.errors.email && <p className="mt-1 text-sm text-red-600">{form.formState.errors.email.message}</p>}
          </label>

          {generalError && <p role="alert" className="rounded-lg bg-red-50 p-3 text-sm text-red-700">{generalError}</p>}
          {successMessage && (
            <div className="rounded-lg bg-green-50 p-3 text-sm leading-6 text-green-800">
              <p>{successMessage}</p>
              <p className="mt-1">Nếu chưa thấy email, vui lòng kiểm tra Spam / Thư rác.</p>
            </div>
          )}

          <button
            disabled={mutation.isPending}
            className="min-h-11 sm:min-h-0 w-full min-w-0 rounded-lg bg-slate-900 px-4 py-2 font-medium text-white disabled:cursor-not-allowed disabled:opacity-60"
          >
            {mutation.isPending ? 'Đang gửi...' : 'Gửi liên kết đặt lại mật khẩu'}
          </button>
        </form>

        <Link to="/login" className="mt-5 inline-flex min-h-11 items-center sm:min-h-0 text-sm font-medium text-slate-700 underline">
          ← Quay lại đăng nhập
        </Link>
      </section>
    </main>
  )
}
