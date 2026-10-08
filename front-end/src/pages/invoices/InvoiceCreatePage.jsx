import { useMutation,useQueryClient } from '@tanstack/react-query'
import { Link,useNavigate,useParams } from 'react-router-dom'
import { createInvoice } from '../../api/invoiceApi'
import { invoiceKeys } from '../../api/invoiceKeys'
import InvoiceForm from '../../components/forms/InvoiceForm'
export default function InvoiceCreatePage(){const {roomId}=useParams(),n=useNavigate(),c=useQueryClient(),m=useMutation({mutationFn:p=>createInvoice(roomId,p)});const submit=async p=>{await m.mutateAsync(p);await c.invalidateQueries({queryKey:invoiceKeys.byRoom(roomId)});n(`/rooms/${roomId}/invoices`)};return <section className="max-w-3xl"><Link to={`/rooms/${roomId}/invoices`}>← Quay lại</Link><h2 className="my-5 text-2xl font-bold">Tạo hóa đơn</h2><InvoiceForm roomId={roomId} onSubmit={submit} label="Tạo hóa đơn"/></section>}
