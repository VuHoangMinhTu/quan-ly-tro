import AuthAside from './AuthAside'
import '../../styles/auth.css'

export default function AuthLayout({ children }) {
  return (
    <main className="bh-auth-page">
      <AuthAside />
      <div className="bh-auth-main">
        <div className="bh-auth-content bh-auth-controls">{children}</div>
      </div>
    </main>
  )
}
