// API nullable text becomes an empty input; empty text is mapped back on submit.
export function toFormString(value) {
  return value ?? ''
}
