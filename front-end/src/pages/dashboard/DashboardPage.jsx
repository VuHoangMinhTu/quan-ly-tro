import { getApiErrorMessage } from '../../api/getApiErrorMessage'
import { useQuery } from '@tanstack/react-query'
import { getMe } from '../../api/authApi'
export default function DashboardPage(){const {data,isError,error}=useQuery({queryKey:['me'],queryFn:getMe});if(isError)return <p className="text-red-600">{getApiErrorMessage(error)}</p>;const profile=data?.data?.data;return <div><h2 className="text-2xl font-bold">Dashboard</h2><p className="mt-2">Chào mừng quay lại hệ thống quản lý phòng trọ</p><div className="mt-6 rounded-lg bg-white p-5 shadow"><p>Người dùng: {profile?.user?.name}</p><p>Chủ nhà: {profile?.landlord?.full_name}</p></div></div>}
