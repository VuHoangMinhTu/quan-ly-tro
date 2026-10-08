import { BedDouble, ShowerHead, Snowflake, Sparkles, Wifi } from 'lucide-react'

export default function AmenityIcon({ name, icon, size = 18, className }) {
  // Amenity currently has no persisted icon field. If one is introduced later,
  // unknown icon strings still fall back safely instead of being rendered as code.
  const normalizedName = String(icon || name || '').toLowerCase()
  const iconProps = { 'aria-hidden': true, size, className }

  if (/(giường|bed)/.test(normalizedName)) return <BedDouble {...iconProps} />
  if (/(điều hòa|air condition|máy lạnh)/.test(normalizedName)) return <Snowflake {...iconProps} />
  if (/(nước nóng|shower|vòi sen)/.test(normalizedName)) return <ShowerHead {...iconProps} />
  if (/(wifi|wi-fi|internet)/.test(normalizedName)) return <Wifi {...iconProps} />

  return <Sparkles {...iconProps} />
}
