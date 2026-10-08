import { useState } from 'react'
import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useForm } from 'react-hook-form'
import { zodResolver } from '@hookform/resolvers/zod'
import { z } from 'zod'
import { Link, useLocation, useNavigate } from 'react-router-dom'
import { getMe, login } from '../../api/authApi'
import GoogleButton from '../../components/ui/GoogleButton'

const schema = z.object({
  email: z.string().min(1, 'Vui lòng nhập email').email('Email không hợp lệ'),
  password: z.string().min(1, 'Vui lòng nhập mật khẩu'),
})

export default function LoginPage() {
  const navigate = useNavigate()
  const location = useLocation()
  const client = useQueryClient()
  const form = useForm({ resolver: zodResolver(schema) })
  const [isGoogleRedirecting, setIsGoogleRedirecting] = useState(false)

  const mutation = useMutation({
    mutationFn: async (data) => {
      const response = await login(data)
      localStorage.setItem('access_token', response.data.data.token)

      try {
        const me = await getMe()
        client.setQueryData(['me'], me)
        return me
      } catch (error) {
        localStorage.removeItem('access_token')
        throw error
      }
    },
    onSuccess: () => navigate('/dashboard'),
  })

  const handleGoogleLogin = () => {
    setIsGoogleRedirecting(true)
    window.location.assign(`${import.meta.env.VITE_API_BASE_URL}/auth/google/redirect`)
  }

  const error = mutation.error?.response?.data?.message || mutation.error?.message

  return (
    <main className="flex min-h-screen items-center justify-center p-4">
      <form
        onSubmit={form.handleSubmit((data) => mutation.mutate(data))}
        className="w-full max-w-md rounded-xl bg-white p-8 shadow"
      >
        <h1 className="mb-6 text-2xl font-bold">Đăng nhập</h1>

        {location.state?.message && (
          <p className="mb-3 text-sm text-green-700">{location.state.message}</p>
        )}

        <label>
          Email
          <input className="mt-1 mb-1 w-full rounded border p-2" {...form.register('email')} />
        </label>
        <p className="mb-3 text-sm text-red-600">{form.formState.errors.email?.message}</p>

        <label>
          Mật khẩu
          <input
            type="password"
            className="mt-1 mb-1 w-full rounded border p-2"
            {...form.register('password')}
          />
        </label>
        <p className="mb-3 text-sm text-red-600">{form.formState.errors.password?.message}</p>

        {error && <p className="mb-3 text-sm text-red-600">{error}</p>}

        <button
          disabled={mutation.isPending}
          className="w-full rounded bg-slate-900 p-2 text-white disabled:opacity-60"
        >
          {mutation.isPending ? 'Đang đăng nhập...' : 'Đăng nhập'}
        </button>

        <div className="my-5 flex items-center gap-3" aria-hidden="true">
          <span className="h-px flex-1 bg-slate-200" />
          <span className="text-xs font-medium text-slate-500">hoặc</span>
          <span className="h-px flex-1 bg-slate-200" />
        </div>

        <GoogleButton
          onClick={handleGoogleLogin}
          disabled={mutation.isPending}
          isLoading={isGoogleRedirecting}
        />

        <p className="mt-4 text-center text-sm">
          Chưa có tài khoản?{' '}
          <Link className="font-medium underline" to="/register">
            Đăng ký
          </Link>
        </p>
      </form>
    </main>
  )
}
