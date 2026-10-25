import { getApiErrorMessage } from '../../api/getApiErrorMessage'
import { useEffect, useRef, useState } from 'react'
import { completeLogin } from '../../api/authSession'
import { getSession, isCurrentSession } from '../../api/session'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { exchangeGoogleCode } from '../../api/authApi'

const debug = (message) => {
  if (import.meta.env.DEV) {
    console.log(message)
  }
}

export default function GoogleCallbackPage() {
  const [params] = useSearchParams()
  const navigate = useNavigate()
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
        const previousSession = getSession()
        const response = await exchangeGoogleCode(code)
        const token = response.data.data.token

        debug('Google exchange success')
        const session = await completeLogin(token, previousSession)
        debug('/me verified')
        return session
      })()
    }

    const finishGoogleLogin = async () => {
      try {
        const session = await loginPromise.current

        if (active && isCurrentSession(session)) {
          debug('Navigating to dashboard')
          navigate('/dashboard', { replace: true })
        }
      } catch (requestError) {
        if (active) {
          setError(getApiErrorMessage(requestError))
        }
      }
    }

    finishGoogleLogin()

    return () => {
      active = false
    }
  }, [code, navigate])

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
