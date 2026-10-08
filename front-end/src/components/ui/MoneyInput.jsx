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
      onChange={(event) => onChange(parseVnd(event.target.value))}
    />
  )
}
