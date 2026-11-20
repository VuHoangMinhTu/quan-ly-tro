// Keep one immutable request for an uncertain submission, including its original timestamp.
export function createManualPaymentAttempt(newKey = () => crypto.randomUUID()) {
  let request
  let inFlight
  let uncertain = false

  return {
    get uncertain() { return uncertain },
    submit(values, send) {
      if (inFlight) return inFlight
      request ??= { ...values, request_key: newKey() }
      inFlight = Promise.resolve().then(() => send(request)).then(response => {
        request = undefined
        uncertain = false
        return response
      }, error => {
        const status = error.response?.status
        uncertain ||= !status || status === 408 || status >= 500
        if (!uncertain) request = undefined
        throw error
      }).finally(() => { inFlight = undefined })
      return inFlight
    },
  }
}
