import { getApiErrorMessage } from '../../api/getApiErrorMessage'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { getInvoice, updateInvoice } from '../../api/invoiceApi'
import { invoiceKeys } from '../../api/invoiceKeys'
import { getContractsByRoom } from '../../api/contractApi'
import { contractKeys } from '../../api/contractKeys'
import InvoiceForm from '../../components/forms/InvoiceForm'
import { canEditInvoiceFinancials, hasInvoiceFinancialChanges } from '../../utils/invoiceFinancials'

export default function InvoiceEditPage() {
  const { id } = useParams()
  const navigate = useNavigate()
  const queryClient = useQueryClient()
  const invoiceQuery = useQuery({ queryKey: invoiceKeys.detail(id), queryFn: () => getInvoice(id) })
  const invoice = invoiceQuery.data?.data?.data
  const contractsQuery = useQuery({ queryKey: contractKeys.byRoom(invoice?.room_id), queryFn: () => getContractsByRoom(invoice.room_id), enabled: Boolean(invoice?.room_id) })
  const updateMutation = useMutation({ mutationFn: (payload) => updateInvoice(id, payload) })

  if (invoiceQuery.isPending || contractsQuery.isPending) return <p>Đang tải...</p>
  if (invoiceQuery.isError || contractsQuery.isError || !invoice) return <p>{getApiErrorMessage(invoiceQuery.error || contractsQuery.error)}</p>

  const editableFinancials = canEditInvoiceFinancials(invoice)
  if (invoice.can_manage === false) return <section className="space-y-3"><p>Bạn chỉ có quyền xem hóa đơn này.</p><Link to={`/invoices/${id}`} className="inline-flex min-h-11 items-center underline">Quay lại chi tiết hóa đơn</Link></section>
  const submit = async (payload) => {
    if (editableFinancials && invoice.payos_payment_request && hasInvoiceFinancialChanges(invoice, payload) && !window.confirm('Việc thay đổi số tiền hóa đơn sẽ làm mã QR thanh toán hiện tại hết hiệu lực. Bạn sẽ cần tạo mã QR mới.')) return

    await updateMutation.mutateAsync(payload)
    await queryClient.invalidateQueries({ queryKey: invoiceKeys.detail(id) })
    await queryClient.invalidateQueries({ queryKey: invoiceKeys.byRoom(invoice.room_id) })
    navigate(`/invoices/${id}`)
  }

  return <section className="w-full min-w-0 max-w-3xl"><Link to={`/invoices/${id}`} className="inline-flex min-h-11 items-center">← Quay lại</Link><h2 className="my-5 text-xl font-bold sm:text-2xl">Sửa hóa đơn</h2><InvoiceForm roomId={invoice.room_id} initialValues={invoice} contractOptions={contractsQuery.data.data.data || []} canEditFinancials={editableFinancials} onSubmit={submit} label="Lưu thay đổi" /></section>
}
