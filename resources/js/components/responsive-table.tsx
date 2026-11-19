import { Children, Fragment, isValidElement, type ReactElement, type ReactNode, type TableHTMLAttributes } from 'react';
import { ArrowUpDown } from 'lucide-react';

type NodeProps = { children?: ReactNode; className?: string; 'aria-sort'?: string };

function elements(children: ReactNode): ReactElement<NodeProps>[] {
  const result: ReactElement<NodeProps>[] = [];
  Children.forEach(children, child => {
    if (!isValidElement<NodeProps>(child)) return;
    if (child.type === Fragment) result.push(...elements(child.props.children));
    else result.push(child);
  });
  return result;
}

function text(children: ReactNode): string {
  return Children.toArray(children).map(child => {
    if (typeof child === 'string' || typeof child === 'number') return String(child);
    return isValidElement<NodeProps>(child) ? text(child.props.children) : '';
  }).join('').trim();
}

// Both presentations share the same cell content and action handlers. CSS exposes
// exactly one layout, keeping hidden actions out of the tab order and accessibility tree.
export default function ResponsiveTable({ children, className = '', ...props }: TableHTMLAttributes<HTMLTableElement>) {
  const sections = elements(children);
  const head = sections.find(section => section.type === 'thead');
  const body = sections.find(section => section.type === 'tbody');
  const headings = elements(elements(head?.props.children)[0]?.props.children);
  const columns = headings.map((heading, index) => ({
    label: text(heading.props.children) || `Column ${index + 1}`,
    sort: elements(heading.props.children).find(child => child.type === 'button'),
    direction: heading.props['aria-sort'],
  }));
  const rows = elements(body?.props.children).filter(row => row.type === 'tr');
  const active = columns.find(column => column.direction && column.direction !== 'none');
  const identity = columns.findIndex(column => /^(REQUEST \/ STORE|STORE|STORE NAME|SUPPLIER|TEAM MEMBER|ACTOR|STORE \/ MODULE|TASK \/ ISSUE)$/i.test(column.label));
  const primary = Math.max(0, identity);
  return <>
    <table {...props} className={`${className} desktop-record-table`}>{children}</table>
    <div className="mobile-record-layout">
      {columns.some(column => column.sort) && <details className="mobile-record-sorting">
        <summary><ArrowUpDown size={16} aria-hidden="true" /><span>Sort records{active && <small>{active.label} / {active.direction}</small>}</span></summary>
        <div className="mobile-sort-options" role="group" aria-label="Sort records by column">{columns.map((column, index) => column.sort && <div key={index} className={column.direction && column.direction !== 'none' ? 'mobile-sort-active' : ''}>{column.sort}</div>)}</div>
      </details>}
      <div className="mobile-record-cards" role="list" aria-label={props['aria-label'] || 'Records'}>
        {rows.map((row, rowIndex) => <article key={row.key ?? rowIndex} className={`mobile-record-card ${row.props.className ?? ''}`} role="listitem">
          <dl>{elements(row.props.children).filter(cell => cell.type === 'td').map((cell, index) => {
            const label = columns[index]?.label ?? `Column ${index + 1}`;
            const actions = /^(ACTIONS|DETAILS|SOA|SOA ATTACHMENT)$/i.test(label);
            const wide = /^(BEFORE|AFTER|DATE PAID \/ PAYMENT|DATE VOUCHERED|RECIPIENT \/ REQUEST)$/i.test(label);
            return <div key={index} className={`record-card-field ${index === primary ? 'record-card-primary' : ''} ${actions ? 'record-card-actions' : ''} ${wide ? 'record-card-wide' : ''} ${cell.props.className?.includes('amount-column') ? 'record-card-amount' : ''}`}>
              <dt>{label}</dt><dd>{cell.props.children}</dd>
            </div>;
          })}</dl>
        </article>)}
      </div>
    </div>
  </>;
}
