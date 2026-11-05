export default function LoadingState({ label = 'Đang tải dữ liệu…', rows = 4 }) {
  return <section role="status" aria-live="polite" className="bh-loading"><span className="sr-only">{label}</span>{Array.from({ length: rows }, (_, index) => <div key={index} className="bh-skeleton" aria-hidden="true" />)}</section>
}
