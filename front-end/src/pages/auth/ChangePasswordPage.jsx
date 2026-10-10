import { applyApiFieldErrors, getApiErrorMessage } from '../../api/getApiErrorMessage'
import { changePassword } from '../../api/authApi'
import PasswordInput from '../../components/ui/PasswordInput'
import { zodResolver } from '@hookform/resolvers/zod'
import { useMutation } from '@tanstack/react-query'
import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { z } from 'zod'

const schema = z.object({
  current_password: z.string().min(1, 'Vui lòng nhập mật khẩu hiện tại.'),
  password: z.string().min(8, 'Mật khẩu phải có ít nhất 8 ký tự.'),
  password_confirmation: z.string().min(1, 'Vui lòng xác nhận mật khẩu mới.'),
}).refine((values) => values.password === values.password_confirmation, {
  path: ['password_confirmation'],
  message: 'Mật khẩu xác nhận không khớp.',
})

export default function ChangePasswordPage() {
  const form = useForm({
    resolver: zodResolver(schema),
    defaultValues: { current_password: '', password: '', password_confirmation: '' },
  })
  const [generalError, setGeneralError] = useState('')
  const [successMessage, setSuccessMessage] = useState('')

  const mutation = useMutation({
    mutationFn: changePassword,
    onMutate: () => {
      setGeneralError('')
      setSuccessMessage('')
    },
    onSuccess: (response) => {
      form.reset()
      setSuccessMessage(response.data.message)
    },
    onError: (error) => {
      form.clearErrors()
      if (!applyApiFieldErrors(error, form.setError)) {
        setGeneralError(getApiErrorMessage(error))
      }
    },
  })

  const passwordFields = [
    ['current_password', 'Mật khẩu hiện tại', 'current-password'],
    ['password', 'Mật khẩu mới', 'new-password'],
    ['password_confirmation', 'Xác nhận mật khẩu mới', 'new-password'],
  ]

  return (
    <section className="max-w-xl">
      <h2 className="text-2xl font-bold">Đổi mật khẩu</h2>
      <p className="mt-2 text-sm text-slate-600">Đặt mật khẩu mới để bảo vệ tài khoản BeeHouse của bạn.</p>

      <form className="mt-6 rounded-xl bg-white p-6 shadow-sm" onSubmit={form.handleSubmit((values) => mutation.mutate(values))}>
        <div className="space-y-4">
          {passwordFields.map(([name, label, autoComplete]) => (
            <label key={name} className="block text-sm font-medium">
              {label} <span className="text-red-600">*</span>
              <PasswordInput
                className="mt-1"
                autoComplete={autoComplete}
                aria-invalid={Boolean(form.formState.errors[name])}
                {...form.register(name, {
                  onChange: () => form.clearErrors(name),
                })}
              />
              {form.formState.errors[name] && <p className="mt-1 text-sm text-red-600">{form.formState.errors[name].message}</p>}
            </label>
          ))}
        </div>

        {generalError && <p role="alert" className="mt-4 rounded-lg bg-red-50 p-3 text-sm text-red-700">{generalError}</p>}
        {successMessage && <p className="mt-4 rounded-lg bg-green-50 p-3 text-sm text-green-800">{successMessage}</p>}

        <button
          disabled={mutation.isPending}
          className="mt-6 rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white disabled:cursor-not-allowed disabled:opacity-60"
        >
          {mutation.isPending ? 'Đang cập nhật...' : 'Đổi mật khẩu'}
        </button>
      </form>
    </section>
  )
}
