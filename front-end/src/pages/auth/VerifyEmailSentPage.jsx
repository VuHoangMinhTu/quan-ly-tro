import AuthLayout from '../../components/ui/AuthLayout'
import { MailCheck } from 'lucide-react'
import { getApiErrorMessage } from '../../api/getApiErrorMessage'
import { useMutation } from '@tanstack/react-query'
import { Link, useLocation } from 'react-router-dom'
import { resendVerification } from '../../api/authApi'
export default function VerifyEmailSentPage() {
  const email = useLocation().state?.email
  const mutation = useMutation({ mutationFn: () => resendVerification(email) })

  return (
    <AuthLayout>
      <section>
        <span className="bh-auth-status-icon"><MailCheck size={26} aria-hidden="true" /></span>
        <h1 className="text-xl sm:text-2xl font-bold">Đăng ký thành công</h1>
        <p className="mt-3 break-words">
          Email xác minh đã được gửi đến: <b>{email || 'địa chỉ email của bạn'}</b>
        </p>
        <p className="mt-4 rounded-lg bg-slate-50 p-3 text-left text-sm leading-relaxed text-slate-600">
          Nếu quý khách không tìm thấy email do hệ thống gửi đến, vui lòng kiểm tra thư mục Spam / Thư rác trong hộp thư.
        </p>
        {mutation.isError && <p role="alert" className="mt-3 text-sm text-red-600">{getApiErrorMessage(mutation.error)}</p>}
        {mutation.data && <p role="status" className="mt-3 text-sm text-green-700">{mutation.data.data.message}</p>}
        <button
          disabled={!email || mutation.isPending}
          onClick={() => mutation.mutate()}
          className="bh-auth-primary w-full mt-6"
        >
          {mutation.isPending ? 'Đang gửi...' : 'Gửi lại email xác minh'}
        </button>
        <p className="mt-4">
          <Link className="inline-flex min-h-11 items-center underline lg:min-h-0" to="/login">Quay về đăng nhập</Link>
        </p>
      </section>
    </AuthLayout>
  )
}
