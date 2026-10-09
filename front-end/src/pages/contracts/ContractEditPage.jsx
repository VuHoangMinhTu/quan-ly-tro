import { getApiErrorMessage } from '../../api/getApiErrorMessage'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { getContract, updateContract } from '../../api/contractApi'
import { contractKeys } from '../../api/contractKeys'
import ContractForm from '../../components/forms/ContractForm'
import { roomKeys } from '../../api/roomKeys'
export default function ContractEditPage(){const {id}=useParams();const navigate=useNavigate(),client=useQueryClient();const query=useQuery({queryKey:contractKeys.detail(id),queryFn:()=>getContract(id)});const mutation=useMutation({mutationFn:(payload)=>updateContract(id,payload)});if(query.isPending)return <p>Đang tải hợp đồng...</p>;if(query.isError)return <p className="text-red-600">{getApiErrorMessage(query.error)}</p>;const contract=query.data.data.data;const submit=async(payload)=>{await mutation.mutateAsync(payload);await Promise.all([client.invalidateQueries({queryKey:contractKeys.detail(id)}),client.invalidateQueries({queryKey:contractKeys.byRoom(contract.room_id)}),client.invalidateQueries({queryKey:roomKeys.detail(contract.room_id)})]);navigate(`/contracts/${id}`,{replace:true,state:{message:'Cập nhật hợp đồng thành công.'}})};return <section className="max-w-3xl"><Link to={`/contracts/${id}`} className="text-sm text-slate-600">← Quay lại chi tiết</Link><h2 className="mb-6 mt-3 text-2xl font-bold">Sửa hợp đồng</h2><ContractForm initialValues={contract} onSubmit={submit} submitLabel="Lưu thay đổi"/></section>}
