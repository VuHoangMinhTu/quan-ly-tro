import { getApiErrorMessage } from '../../api/getApiErrorMessage'
import { useEffect, useRef, useState } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { exchangeGoogleCode, getMe } from '../../api/authApi'

const debug = (message) => {
  if (import.meta.env.DEV) {
    console.log(message)
  }
}

export default function GoogleCallbackPage() {
  const [params] = useSearchParams()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const code = params.get('code')
  const [error, setError] = useState('')
  const loginPromise = useRef(null)

  useEffect(() => {
    if (!code) {
      return
    }

    let active = true

    if (!loginPromise.current) {
      loginPromise.current = (async () => {
        const response = await exchangeGoogleCode(code)
        const token = response.data.data.token

        debug('Google exchange success')
        localStorage.setItem('access_token', token)
        debug('Token saved')

        await queryClient.fetchQuery({
          queryKey: ['me'],
          queryFn: getMe,
        })
        debug('/me verified')
      })()
    }

    const finishGoogleLogin = async () => {
      try {
        await loginPromise.current

        if (active) {
          debug('Navigating to dashboard')
          navigate('/dashboard', { replace: true })
        }
      } catch (requestError) {
        localStorage.removeItem('access_token')

        if (active) {
          setError(getApiErrorMessage(requestError))
        }
      }
    }

    finishGoogleLogin()

    return () => {
      active = false
    }
  }, [code, navigate, queryClient])

  if (!code || error) {
    return (
      <main className="flex min-h-dvh items-center justify-center p-4">
        <section className="w-full min-w-0 max-w-md break-words rounded-xl bg-white p-5 text-center shadow sm:p-8">
        <p role="alert">{error || 'Đăng nhập Google thất bại.'}</p>
        <Link className="mt-4 inline-flex min-h-11 items-center font-medium underline sm:min-h-0" to="/login">
          Quay lại đăng nhập
        </Link>
        </section>
      </main>
    )
  }

  return <main className="flex min-h-dvh items-center justify-center p-4"><section role="status" className="w-full min-w-0 max-w-md rounded-xl bg-white p-5 text-center shadow sm:p-8">Đang đăng nhập bằng Google...</section></main>
}
