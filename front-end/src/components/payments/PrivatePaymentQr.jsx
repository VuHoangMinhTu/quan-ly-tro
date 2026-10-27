import { useEffect, useRef } from 'react'
import { useQuery } from '@tanstack/react-query'
import { getPaymentQrImage } from '../../api/paymentSettingsApi'
import { cropPaymentQr } from '../../utils/paymentQrImage'

export function LocalQrImage({ blob, alt, className }) {
  const image = useRef(null)
  useEffect(() => {
    const source = URL.createObjectURL(blob)
    if (image.current) image.current.src = source
    return () => URL.revokeObjectURL(source)
  }, [blob])
  return <img ref={image} alt={alt} className={className} />
}

export default function PrivatePaymentQr({ profile, className = '', alt = 'QR chuyển khoản ngân hàng', cropToQr = false }) {
  const image = useQuery({
    queryKey: ['payment-qr', profile.payment_qr_url, profile.payment_qr_version, cropToQr ? 'bill-crop-v1' : 'original'],
    queryFn: async () => {
      const blob = (await getPaymentQrImage(profile.payment_qr_url)).data
      return cropToQr ? cropPaymentQr(blob) : blob
    },
    enabled: Boolean(profile.payment_qr_url),
    staleTime: Infinity,
    retry: 1,
  })
  if (image.isPending) return <span className="no-print text-xs text-slate-500">Đang tải QR...</span>
  if (image.isError) return <span className="text-xs text-red-700">Không tải được QR. Vui lòng đối chiếu thông tin ngân hàng với chủ trọ. <button type="button" onClick={() => image.refetch()} className="no-print underline">Thử lại</button></span>
  return image.data ? <LocalQrImage key={profile.payment_qr_version} blob={image.data} alt={alt} className={className} /> : null
}
