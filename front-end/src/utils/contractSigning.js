export const SIGNING_STATUS_LABELS = {
  NOT_STARTED: 'Chưa gửi ký', PENDING: 'Chờ ký', PARTIALLY_SIGNED: 'Đã ký một phần',
  COMPLETED: 'Đã hoàn tất ký', DECLINED: 'Có bên từ chối ký', VOID: 'Tài liệu đã hủy',
}
export const SIGNER_STATUS_LABELS = {
  NOT_STARTED: 'Chưa gửi ký', PENDING: 'Chưa ký', OTP_VERIFIED: 'Đã xác thực OTP', SIGNED: 'Đã ký',
  EXPIRED: 'Yêu cầu đã hết hạn', REVOKED: 'Yêu cầu đã thu hồi', DECLINED: 'Đã từ chối ký',
}
export const SIGNING_EVENT_LABELS = {
  DOCUMENT_FINALIZED: 'Đã chốt nội dung hợp đồng', SIGN_REQUEST_CREATED: 'Đã tạo yêu cầu ký',
  SIGN_REQUEST_SENT: 'Đã gửi email yêu cầu ký', DOCUMENT_VIEWED: 'Đã mở tài liệu hợp đồng',
  OTP_SENT: 'Đã gửi mã xác thực', OTP_VERIFIED: 'Đã xác thực OTP', SIGNATURE_SUBMITTED: 'Đã gửi chữ ký',
  SIGNED: 'Đã xác nhận ký', DECLINED: 'Đã từ chối ký', REQUEST_REVOKED: 'Đã thu hồi yêu cầu ký',
  DOCUMENT_VOIDED: 'Đã hủy tài liệu ký', SIGNING_COMPLETED: 'Hợp đồng đã hoàn tất ký',
  NOTIFICATION_FAILED: 'Chưa gửi được email thông báo cập nhật',
}
export function buildSignaturePayload(context, blob, verificationToken) {
  const payload = new FormData()
  payload.append('consent', '1')
  payload.append('consent_version', context.consent_version)
  payload.append('content_hash', context.content_hash)
  payload.append('verification_token', verificationToken)
  payload.append('signature', blob, 'signature.png')
  return payload
}
