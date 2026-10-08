function GoogleIcon() {
  return (
    <svg aria-hidden="true" className="h-5 w-5 shrink-0" viewBox="0 0 24 24">
      <path
        fill="#4285F4"
        d="M21.35 12.23c0-.71-.06-1.39-.18-2.05H12v3.88h5.24a4.48 4.48 0 0 1-1.94 2.94v2.51h3.23c1.89-1.74 2.82-4.3 2.82-7.28Z"
      />
      <path
        fill="#34A853"
        d="M12 21.75c2.62 0 4.82-.87 6.42-2.36L15.19 16.9c-.87.58-1.98.92-3.19.92-2.52 0-4.65-1.7-5.42-3.99H3.24v2.59A9.7 9.7 0 0 0 12 21.75Z"
      />
      <path
        fill="#FBBC05"
        d="M6.58 13.83A5.83 5.83 0 0 1 6.28 12c0-.64.11-1.26.3-1.83V7.58H3.24A9.71 9.71 0 0 0 2.25 12c0 1.57.37 3.05.99 4.42l3.34-2.59Z"
      />
      <path
        fill="#EA4335"
        d="M12 6.18c1.43 0 2.72.49 3.73 1.45l2.8-2.8C16.81 3.23 14.62 2.25 12 2.25a9.7 9.7 0 0 0-8.76 5.33l3.34 2.59C7.35 7.88 9.48 6.18 12 6.18Z"
      />
    </svg>
  )
}

export default function GoogleButton({
  children = 'Đăng nhập với Google',
  disabled = false,
  isLoading = false,
  onClick,
}) {
  const isDisabled = disabled || isLoading

  return (
    <button
      type="button"
      onClick={onClick}
      disabled={isDisabled}
      aria-label={isLoading ? 'Đang chuyển hướng đến Google' : children}
      className="flex h-11 w-full items-center justify-center gap-3 rounded-lg border border-slate-300 bg-white px-4 text-sm font-medium text-slate-700 shadow-sm transition hover:bg-slate-50 hover:shadow focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-60 disabled:hover:bg-white disabled:hover:shadow-sm"
    >
      <GoogleIcon />
      <span>{isLoading ? 'Đang chuyển hướng...' : children}</span>
    </button>
  )
}
