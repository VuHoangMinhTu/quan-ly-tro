import axios from 'axios'
import client from './axiosClient'

// Public signing never attaches a landlord's Sanctum token or clears their login.
const publicClient = axios.create({ baseURL: import.meta.env.VITE_API_BASE_URL, headers: { Accept: 'application/json' }, withCredentials: false })
const endpoint = ({ id, token }) => token ? `/sign/${encodeURIComponent(token)}` : `/contracts/${id}/sign/lessor`
const transport = target => target.token ? publicClient : client
export const signingKeys = { detail: id => ['contract-signing', String(id)] }
export const getContractSigning = id => client.get(`/contracts/${id}/signing`)
export const startContractSigning = (id, expectedEmail) => client.post(`/contracts/${id}/signing-requests`, expectedEmail ? { expected_tenant_email: expectedEmail } : {})
export const resendContractSigning = (id, expectedEmail) => client.post(`/contracts/${id}/signing-requests/resend`, expectedEmail ? { expected_tenant_email: expectedEmail } : {})
export const voidContractSigning = (id, reason) => client.post(`/contracts/${id}/signing/void`, { reason })
export const getSigningContext = (target, signal) => transport(target).get(endpoint(target), { signal, headers: target.verificationToken ? { 'X-Signing-Verification': target.verificationToken } : undefined })
export const requestSigningOtp = target => transport(target).post(`${endpoint(target)}/request-otp`)
export const verifySigningOtp = (target, otp) => transport(target).post(`${endpoint(target)}/verify-otp`, { otp })
export const submitContractSignature = (target, payload) => transport(target).post(endpoint(target), payload, { headers: { 'Content-Type': 'multipart/form-data' } })
export const declineContractSigning = (target, reason) => publicClient.post(`${endpoint(target)}/decline`, { reason }, { headers: { 'X-Signing-Verification': target.verificationToken } })
export const getSignedContractDocument = (id, signal) => client.get(`/contracts/${id}/document/signed`, { signal })
export const getSignatureImage = (target, signatureId, signal) => transport(target).get(target.token
  ? `${endpoint(target)}/signatures/${signatureId}` : `/contracts/${target.id}/signatures/${signatureId}/image`, { responseType: 'blob', signal })
