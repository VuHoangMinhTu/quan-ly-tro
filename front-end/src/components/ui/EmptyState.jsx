export default function EmptyState({ icon: Icon, title, description, children }) {
  return <div className="bh-empty-state">{Icon && <span className="bh-empty-icon"><Icon size={26} strokeWidth={1.5} aria-hidden="true" /></span>}<h3>{title}</h3>{description && <p>{description}</p>}{children && <div>{children}</div>}</div>
}
