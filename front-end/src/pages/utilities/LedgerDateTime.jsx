import { formatLedgerDate, replaceLedgerDate } from './utilityLedgerDisplay.mjs'

export default function LedgerDateTime({ label, value, onValueChange, ...inputProps }) {
  return <label className="ledger-date-field">
    <span className="block text-sm">{label}</span>
    <span className="ledger-date-control">
      <input type="date" lang="vi-VN" aria-label={label} value={value.slice(0, 10)} {...inputProps}
        onChange={event => {
          if (event.target.value) onValueChange(replaceLedgerDate(value, event.target.value))
        }} />
      <span className="ledger-date-display" aria-hidden="true">{formatLedgerDate(value)}</span>
    </span>
  </label>
}
