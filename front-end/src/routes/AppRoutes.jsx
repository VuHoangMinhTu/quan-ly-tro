import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom'
import BoardingHouseCreatePage from '../pages/boarding-houses/BoardingHouseCreatePage'
import BoardingHouseDetailPage from '../pages/boarding-houses/BoardingHouseDetailPage'
import BoardingHouseEditPage from '../pages/boarding-houses/BoardingHouseEditPage'
import BoardingHouseListPage from '../pages/boarding-houses/BoardingHouseListPage'
import ChangePasswordPage from '../pages/auth/ChangePasswordPage'
import EmailVerifiedPage from '../pages/auth/EmailVerifiedPage'
import ForgotPasswordPage from '../pages/auth/ForgotPasswordPage'
import GoogleCallbackPage from '../pages/auth/GoogleCallbackPage'
import LoginPage from '../pages/auth/LoginPage'
import RegisterPage from '../pages/auth/RegisterPage'
import ResetPasswordPage from '../pages/auth/ResetPasswordPage'
import VerifyEmailSentPage from '../pages/auth/VerifyEmailSentPage'
import DashboardPage from '../pages/dashboard/DashboardPage'
import RoomCreatePage from '../pages/rooms/RoomCreatePage'
import RoomDetailPage from '../pages/rooms/RoomDetailPage'
import RoomEditPage from '../pages/rooms/RoomEditPage'
import RoomListPage from '../pages/rooms/RoomListPage'
import TenantCreatePage from '../pages/tenants/TenantCreatePage'
import TenantDetailPage from '../pages/tenants/TenantDetailPage'
import TenantEditPage from '../pages/tenants/TenantEditPage'
import TenantListPage from '../pages/tenants/TenantListPage'
import ContractCreatePage from '../pages/contracts/ContractCreatePage'
import ContractDetailPage from '../pages/contracts/ContractDetailPage'
import ContractEditPage from '../pages/contracts/ContractEditPage'
import ContractListPage from '../pages/contracts/ContractListPage'
import ContractNavigationPage from '../pages/contracts/ContractNavigationPage'
import ContractTemplateListPage from '../pages/contracts/ContractTemplateListPage'
import ContractTemplateEditorPage from '../pages/contracts/ContractTemplateEditorPage'
import ContractDocumentPage from '../pages/contracts/ContractDocumentPage'
import ContractDemoPage from '../pages/contracts/ContractDemoPage'
import ContractSigningPage from '../pages/contracts/ContractSigningPage'
import SignedContractDocumentPage from '../pages/contracts/SignedContractDocumentPage'
import ServiceListPage from '../pages/services/ServiceListPage'
import UtilityMeterDetailPage from '../pages/utilities/UtilityMeterDetailPage'
import InvoiceCreatePage from '../pages/invoices/InvoiceCreatePage'
import InvoiceDetailPage from '../pages/invoices/InvoiceDetailPage'
import InvoiceEditPage from '../pages/invoices/InvoiceEditPage'
import InvoiceListPage from '../pages/invoices/InvoiceListPage'
import InvoicePrintPage from '../pages/invoices/InvoicePrintPage'
import ModuleNavigationPage from '../pages/navigation/ModuleNavigationPage'
import PayOSReturnPage from '../pages/payments/PayOSReturnPage'
import PaymentSettingsPage from '../pages/payments/PaymentSettingsPage'
import ResidenceDeclarationListPage from '../pages/residence/ResidenceDeclarationListPage'
import ResidenceDeclarationEditPage from '../pages/residence/ResidenceDeclarationEditPage'
import ResidenceDeclarationDetailPage from '../pages/residence/ResidenceDeclarationDetailPage'
import ResidenceCt01PrintPage from '../pages/residence/ResidenceCt01PrintPage'
import ResidenceStayConsentPrintPage from '../pages/residence/ResidenceStayConsentPrintPage'
import MainLayout from '../layouts/MainLayout'
import ProtectedRoute from './ProtectedRoute'

export default function AppRoutes() {
  return (
    <BrowserRouter>
      <Routes>
        <Route path="/login" element={<LoginPage />} />
        <Route path="/sign/:token" element={<ContractSigningPage publicSigning />} />
        <Route path="/register" element={<RegisterPage />} />
        <Route path="/forgot-password" element={<ForgotPasswordPage />} />
        <Route path="/reset-password" element={<ResetPasswordPage />} />
        <Route path="/verify-email-sent" element={<VerifyEmailSentPage />} />
        <Route path="/email-verified" element={<EmailVerifiedPage />} />
        <Route path="/auth/google/callback" element={<GoogleCallbackPage />} />

        <Route element={<ProtectedRoute />}>
          <Route path="/contracts/:id/sign" element={<ContractSigningPage />} />
          <Route path="/contracts/:id/document/signed" element={<SignedContractDocumentPage />} />
          <Route path="/contracts/:id/document" element={<ContractDocumentPage />} />
          <Route path="/contract-templates/demos/:type" element={<ContractDemoPage />} />
          <Route path="/invoices/:id/print" element={<InvoicePrintPage />} />
          <Route path="/residence-declarations/:id/print" element={<ResidenceCt01PrintPage />} />
          <Route path="/residence-declarations/:id/stay-consent/print" element={<ResidenceStayConsentPrintPage />} />
          <Route element={<MainLayout />}>
            <Route path="/dashboard" element={<DashboardPage />} />
            <Route path="/residence-declarations" element={<ResidenceDeclarationListPage />} />
            <Route path="/residence-declarations/new" element={<ResidenceDeclarationEditPage />} />
            <Route path="/residence-declarations/:id/edit" element={<ResidenceDeclarationEditPage />} />
            <Route path="/residence-declarations/:id" element={<ResidenceDeclarationDetailPage />} />
            <Route path="/change-password" element={<ChangePasswordPage />} />
            <Route path="/payment-settings" element={<PaymentSettingsPage />} />
            <Route path="/rooms" element={<ModuleNavigationPage module="rooms" />} />
            <Route path="/contracts" element={<ContractNavigationPage />} />
            <Route path="/contract-templates" element={<ContractTemplateListPage />} />
            <Route path="/contract-templates/new" element={<ContractTemplateEditorPage />} />
            <Route path="/contract-templates/:id" element={<ContractTemplateEditorPage />} />
            <Route path="/services" element={<ModuleNavigationPage module="services" />} />
            <Route path="/utilities" element={<ModuleNavigationPage module="utilities" />} />
            <Route path="/invoices" element={<ModuleNavigationPage module="invoices" />} />
            <Route path="/boarding-houses" element={<BoardingHouseListPage />} />
            <Route path="/boarding-houses/new" element={<BoardingHouseCreatePage />} />
            <Route path="/boarding-houses/:id" element={<BoardingHouseDetailPage />} />
            <Route path="/boarding-houses/:id/edit" element={<BoardingHouseEditPage />} />
            <Route path="/boarding-houses/:boardingHouseId/rooms" element={<RoomListPage />} />
            <Route path="/boarding-houses/:boardingHouseId/rooms/new" element={<RoomCreatePage />} />
            <Route path="/rooms/:id" element={<RoomDetailPage />} />
            <Route path="/rooms/:id/edit" element={<RoomEditPage />} />
            <Route path="/tenants" element={<TenantListPage />} />
            <Route path="/tenants/new" element={<TenantCreatePage />} />
            <Route path="/tenants/:id" element={<TenantDetailPage />} />
            <Route path="/tenants/:id/edit" element={<TenantEditPage />} />
            <Route path="/rooms/:roomId/contracts" element={<ContractListPage />} />
            <Route path="/rooms/:roomId/contracts/new" element={<ContractCreatePage />} />
            <Route path="/contracts/:id" element={<ContractDetailPage />} />
            <Route path="/contracts/:id/edit" element={<ContractEditPage />} />
            <Route path="/boarding-houses/:boardingHouseId/services" element={<ServiceListPage />} />
            <Route path="/utility-meters/:id" element={<UtilityMeterDetailPage />} />
            <Route path="/rooms/:roomId/invoices" element={<InvoiceListPage />} />
            <Route path="/rooms/:roomId/invoices/new" element={<InvoiceCreatePage />} />
            <Route path="/invoices/:id" element={<InvoiceDetailPage />} />
            <Route path="/invoices/:id/edit" element={<InvoiceEditPage />} />
            <Route path="/payments/payos/success" element={<PayOSReturnPage />} />
            <Route path="/payments/payos/cancel" element={<PayOSReturnPage cancelled />} />
            <Route path="/" element={<Navigate to="/dashboard" replace />} />
          </Route>
        </Route>

        <Route path="*" element={<Navigate to="/" replace />} />
      </Routes>
    </BrowserRouter>
  )
}
