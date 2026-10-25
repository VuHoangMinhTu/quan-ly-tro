import { formatVnd, parseVnd } from '../../utils/formatters'

export default function MoneyInput({ value = '', onChange, ...props }) {
  const displayValue = value === '' || value === null || value === undefined
    ? ''
    : formatVnd(value)

  return (
    <input
      {...props}
      type="text"
      inputMode="numeric"
      value={displayValue}
      onChange={(event) => {
        const raw = event.target.value
        const parsed = parseVnd(raw)
        // Preserve the sign so form validation can reject a negative amount,
        // instead of silently turning a pasted -100 into a valid +100.
        onChange(raw.trim().startsWith('-') && parsed !== '' ? -parsed : parsed)
      }}
    />
  )
}
