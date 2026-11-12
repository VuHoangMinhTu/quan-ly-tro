// BEEHOUSE_NAV_CONTEXT_V1
/** Safe in-app back/return paths. Never accept protocol-relative/external URLs. */
export function currentPagePath(location) {
  return (location?.pathname || '/') + (location?.search || '') + (location?.hash || '')
}
export function safeReturnTo(candidate, fallback = '/dashboard') {
  if (typeof candidate !== 'string' || !candidate.startsWith('/') || candidate.startsWith('//') || /[\\\\\x00-\x1f\x7f]/.test(candidate)) return fallback
  try {
    const url = new URL(candidate, 'https://beehouse.invalid')
    if (url.origin !== 'https://beehouse.invalid') return fallback
    return url.pathname + url.search + url.hash
  } catch { return fallback }
}
export function backTarget(location, fallback) {
  return safeReturnTo(location?.state?.returnTo, fallback)
}
export function forwardContext(location, fallback) {
  return {
    returnTo: backTarget(location, fallback),
    ...(typeof location?.state?.returnLabel === 'string' ? { returnLabel: location.state.returnLabel } : {}),
    ...(location?.state?.parentReturnTo ? { parentReturnTo: safeReturnTo(location.state.parentReturnTo, fallback) } : {}),
  }
}
