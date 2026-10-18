import MoneyInput from '../ui/MoneyInput'
import { getBasePriceLabel } from '../../utils/service'

const inputClass = 'mt-1 min-h-11 w-full min-w-0 rounded-lg border border-slate-300 px-3 py-2'

export default function ContractServicePriceEditor({ value, onChange }) {
  const tiers = value.pricing_snapshot ?? []
  const updateTier = (index, key, next) => onChange({ ...value, pricing_snapshot: tiers.map((tier, at) => at === index ? { ...tier, [key]: next } : tier) })
  return <div className="min-w-0 space-y-3">
    <div className="grid gap-3 sm:grid-cols-2">
      {value.billing_method !== 'TIERED' && <label className="text-sm">{getBasePriceLabel(value.billing_method)}{value.unit ? ` (đ / ${value.unit})` : ' (đ)'}<MoneyInput value={value.base_price} onChange={base_price => onChange({ ...value, base_price })} className={inputClass} /></label>}
      <label className="text-sm">VAT (%)<input type="number" min="0" max="100" step="0.01" value={value.vat_rate ?? ''} onChange={event => onChange({ ...value, vat_rate: event.target.value })} className={inputClass} /></label>
    </div>
    {value.billing_method === 'TIERED' && <fieldset className="min-w-0 space-y-3"><legend className="text-sm font-medium">Bảng giá bậc thang{value.unit ? ` (${value.unit})` : ''}</legend>
      {tiers.map((tier, index) => <div key={index} className="grid min-w-0 gap-3 rounded-lg border border-slate-200 p-3 sm:grid-cols-3">
        <label className="text-sm">Từ<input type="number" min="0" step="any" value={tier.from_quantity} onChange={event => updateTier(index, 'from_quantity', event.target.value)} className={inputClass} /></label>
        <label className="text-sm">Đến<input type="number" min="0" step="any" placeholder="Không giới hạn" value={tier.to_quantity ?? ''} onChange={event => updateTier(index, 'to_quantity', event.target.value)} className={inputClass} /></label>
        <label className="text-sm">Đơn giá (đ)<MoneyInput value={tier.unit_price} onChange={amount => updateTier(index, 'unit_price', amount)} className={inputClass} /></label>
        <button type="button" onClick={() => onChange({ ...value, pricing_snapshot: tiers.filter((_, at) => at !== index) })} className="min-h-11 text-left text-sm text-red-700 underline sm:col-span-3">Bỏ bậc {index + 1}</button>
      </div>)}
      <button type="button" onClick={() => onChange({ ...value, pricing_snapshot: [...tiers, { from_quantity: tiers.at(-1)?.to_quantity ?? 0, to_quantity: null, unit_price: '' }] })} className="min-h-11 rounded-lg border px-3 text-sm">+ Thêm bậc giá</button>
      <p className="text-xs text-slate-500">Bắt đầu từ 0, các bậc liên tiếp; để trống “Đến” ở bậc cuối cùng.</p>
    </fieldset>}
  </div>
}
