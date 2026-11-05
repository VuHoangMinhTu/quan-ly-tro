export default function PageHeader({ title, description, eyebrow, children }) {
  return <div className="bh-page-header"><div>{eyebrow && <p className="bh-eyebrow">{eyebrow}</p>}<h2>{title}</h2>{description && <p className="bh-page-description">{description}</p>}</div>{children && <div className="bh-page-actions">{children}</div>}</div>
}
