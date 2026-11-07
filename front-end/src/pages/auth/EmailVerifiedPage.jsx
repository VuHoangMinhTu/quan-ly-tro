import { Link, useSearchParams } from 'react-router-dom'
import { CircleAlert, CircleCheck } from 'lucide-react'
import AuthLayout from '../../components/ui/AuthLayout'

export default function EmailVerifiedPage() {
  const [params] = useSearchParams()
  const success = params.get('status') === 'success'
  const Icon = success ? CircleCheck : CircleAlert

  return (
    <AuthLayout>
      <section>
        <span className="bh-auth-status-icon" data-tone={success ? 'success' : 'warning'}>
          <Icon size={26} aria-hidden="true" />
        </span>
        <h1>{success ? 'Xác minh email thành công' : 'Liên kết xác minh không hợp lệ hoặc đã hết hạn'}</h1>
        <Link className="bh-auth-primary mt-6 w-full" to="/login">Đăng nhập</Link>
      </section>
    </AuthLayout>
  )
}
