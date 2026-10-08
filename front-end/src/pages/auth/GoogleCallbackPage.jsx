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
      } catch {
        localStorage.removeItem('access_token')

        if (active) {
          setError('Đăng nhập Google thất bại. Vui lòng thử lại.')
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
      <main className="p-8">
        {error || 'Đăng nhập Google thất bại.'}{' '}
        <Link className="underline" to="/login">
          Quay lại đăng nhập
        </Link>
      </main>
    )
  }

  return <main className="p-8">Đang đăng nhập bằng Google...</main>
}
