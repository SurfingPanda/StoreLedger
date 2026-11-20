import { Building2, Check, ClipboardCheck, CreditCard, Droplets, FileText, History, LayoutDashboard, LockKeyhole, Monitor, ShieldCheck, Wifi, Zap } from 'lucide-react';
import { useEffect, useRef } from 'react';
import type { AppData } from '@/lib/auth';

const modules = [
  { name: 'Rental', detail: 'Leases & store payments', icon: Building2 },
  { name: 'Electricity', detail: 'Monthly statements', icon: Zap },
  { name: 'Water', detail: 'Bills & account details', icon: Droplets },
  { name: 'Internet', detail: 'Providers & billing cycles', icon: Wifi },
  { name: 'UTAK POS', detail: 'Subscriptions & renewals', icon: Monitor },
];
const stages = [
  { title: 'For review', team: 'Accounting', name: 'Monthly store rental', category: 'Rental', amount: '₱18,000.00', icon: Building2, status: 'SOA submitted', tone: 'blue' },
  { title: 'For payment', team: 'Finance', name: 'Electricity statement', category: 'Electricity', amount: '₱4,250.00', icon: Zap, status: 'Voucher prepared', tone: 'orange' },
  { title: 'Completed', team: 'Shared record', name: 'Internet subscription', category: 'Internet', amount: '₱2,499.00', icon: Wifi, status: 'Payment recorded', tone: 'green' },
];
const steps = [
  { icon: FileText, team: 'ADMIN / RSO', title: 'Start with the statement.', text: 'Add the store, billing period, amount, and due date. Attach the SOA so your team has everything to begin.' },
  { icon: ClipboardCheck, team: 'ACCOUNTING', title: 'Make the next move clear.', text: 'Review the details, prepare the voucher, and pass the request to Finance. Return it for correction when needed.' },
  { icon: CreditCard, team: 'FINANCE', title: 'Close the loop.', text: 'Record payment details and attach the proof. The statement, voucher, and payment stay together in one complete record.' },
];
const roles = [
  ['Admin', 'Manage requests, stores, suppliers, user access, and notification recipients.'],
  ['Accounting', 'Review statements, prepare vouchers, and return requests for correction.'],
  ['Finance', 'Review vouchers, record payments, and attach proof of payment.'],
  ['RSO', 'Submit and correct Rental, Water, and Electricity bills, and follow their progress.'],
  ['IT', 'View monitoring records and follow request progress across the workspace.'],
];

export default function Landing({ data }: { data: AppData }) {
  const pageRef = useRef<HTMLDivElement>(null);
  useEffect(() => {
    const page = pageRef.current;
    const preference = window.matchMedia('(prefers-reduced-motion: reduce)');
    if (!page || !('IntersectionObserver' in window)) return;
    const targets = page.querySelectorAll<HTMLElement>('[data-reveal]');
    let observer: IntersectionObserver | undefined;
    const reset = () => {
      observer?.disconnect();
      targets.forEach(target => target.classList.remove('is-waiting', 'is-visible'));
    };
    const setup = () => {
      reset();
      if (preference.matches) return;
      observer = new IntersectionObserver(entries => entries.forEach(entry => {
        if (!entry.isIntersecting) return;
        entry.target.classList.replace('is-waiting', 'is-visible');
        observer?.unobserve(entry.target);
      }), { threshold: 0.06 });
      targets.forEach(target => {
        target.classList.add('is-waiting');
        observer!.observe(target);
      });
    };
    const showFocus = (event: FocusEvent) => {
      if (event.target instanceof Element) event.target.closest('[data-reveal]')?.classList.remove('is-waiting', 'is-visible');
    };
    setup();
    preference.addEventListener('change', setup);
    page.addEventListener('focusin', showFocus);
    return () => {
      reset();
      preference.removeEventListener('change', setup);
      page.removeEventListener('focusin', showFocus);
    };
  }, []);

  return <div className="landing-page" ref={pageRef} id="top">
    <a className="landing-skip" href="#landing-main">Skip to content</a>
    <header className="landing-header"><div className="landing-container landing-header-inner">
      <a href="#top" className="landing-brand" aria-label="BW Operations home"><img src={data.assets.logo} width={62} height={62} alt="BW Superbakeshop" /><span>Operations<small>THE STORE WORKSPACE</small></span></a>
      <nav aria-label="Page sections" className="landing-navigation"><a href="#coverage">What we track</a><a href="#workflow">The process</a><a href="#teams">Your team</a></nav>
      <a href={data.urls.login} className="landing-button landing-header-login">Log in</a>
    </div></header>
    <main id="landing-main" tabIndex={-1}>
      <section className="landing-hero" aria-labelledby="landing-title">
        <div className="landing-container">
          <div className="landing-hero-copy" data-reveal>
            <p className="landing-eyebrow"><span>BW OPERATIONS</span> A little order. A lot of progress.</p>
            <h1 id="landing-title">Great stores run on<br /><span>clear next steps.</span></h1>
            <p className="landing-hero-intro">Bring every bill, every renewal, and every handoff together.<br className="landing-desktop-break" /> One organized workspace for the people behind your stores.</p>
            <div className="landing-hero-actions"><a className="landing-button" href={data.urls.login}>Enter your workspace</a><a className="landing-secondary-button" href="#workflow">Explore the process</a></div>
            <p className="landing-access-note"><LockKeyhole size={14} aria-hidden="true" /> For authorized BW Superbakeshop team members</p>
          </div>
          <figure className="landing-board" data-reveal aria-label="Illustrative operations board with example requests at three stages">
            <div className="landing-board-rail" aria-hidden="true"><span className="landing-rail-monogram">bw.</span><LayoutDashboard /><FileText /><Building2 /><History /><ShieldCheck className="landing-rail-bottom" /></div>
            <div className="landing-board-content">
              <div className="landing-board-top"><span>Workspace <span className="landing-slash">/</span> <strong>Overview</strong></span><span className="landing-example-label">Illustrative preview</span></div>
              <div className="landing-board-heading"><div><p className="landing-board-kicker">EVERYTHING IN ITS PLACE</p><h2>Your operations, at a glance.</h2></div><span className="landing-board-tag"><ShieldCheck size={15} aria-hidden="true" /> One shared workspace</span></div>
              <div className="landing-board-columns">{stages.map(({ title, team, name, category, amount, icon: Icon, status, tone }) => <div className={`landing-board-column landing-tone-${tone}`} key={title}>
                <div className="landing-column-heading"><h3>{title}</h3><span>{team}</span></div>
                <div className="landing-request-card"><div className="landing-request-category"><span><Icon size={16} aria-hidden="true" />{category}</span><FileText size={16} aria-hidden="true" /></div><h4>{name}</h4><p>Sample store · Monthly billing</p><strong className="landing-request-amount">{amount}</strong><div className="landing-request-status"><Check size={13} aria-hidden="true" />{status}</div></div>
              </div>)}</div>
              <figcaption><History size={14} aria-hidden="true" /> From the first statement to the final payment. Every handoff kept on record.</figcaption>
            </div>
          </figure>
        </div>
      </section>
      <section id="coverage" className="landing-coverage" aria-labelledby="landing-coverage-title"><div className="landing-container">
        <div className="landing-coverage-heading"><p className="landing-eyebrow">FIVE AREAS. ONE WORKSPACE.</p><h2 id="landing-coverage-title">All the essentials. All accounted for.</h2></div>
        <div className="landing-module-list">{modules.map(({ name, detail, icon: Icon }) => <article key={name} data-reveal><Icon size={25} strokeWidth={1.6} aria-hidden="true" /><h3>{name}</h3><p>{detail}</p></article>)}</div>
      </div></section>
      <section id="workflow" className="landing-workflow landing-container" aria-labelledby="landing-workflow-title">
        <div className="landing-section-heading" data-reveal><div><p className="landing-eyebrow">LESS CHASING. MORE CLARITY.</p><h2 id="landing-workflow-title">A simple rhythm.<br />A stronger operation.</h2></div><p>Everyone knows what’s ready, who owns the next step, and where to find the details.</p></div>
        <ol className="landing-process">{steps.map(({ icon: Icon, team, title, text }) => <li key={team} data-reveal><div className="landing-process-icon"><Icon size={25} strokeWidth={1.5} aria-hidden="true" /></div><p className="landing-eyebrow">{team}</p><h3>{title}</h3><p>{text}</p></li>)}</ol>
        <div className="landing-record-note" data-reveal><History size={23} aria-hidden="true" /><div><strong>The details stay with the work.</strong><p>Comments, supporting files, corrections, and payment history come together in one activity timeline.</p></div></div>
      </section>
      <section id="teams" className="landing-team-section" aria-labelledby="landing-team-title"><div className="landing-container landing-team-layout">
        <div className="landing-team-intro" data-reveal><span className="landing-team-emblem"><ShieldCheck size={30} strokeWidth={1.4} aria-hidden="true" /></span><p className="landing-eyebrow">INDIVIDUAL ROLES. SHARED PROGRESS.</p><h2 id="landing-team-title">Good teamwork<br />starts with clarity.</h2><p>Your assigned account brings the right requests, actions, and notifications into view, so you can focus on your part.</p><a className="landing-text-link" href={data.urls.login}>Find your workspace</a></div>
        <dl className="landing-role-list">{roles.map(([role, detail]) => <div key={role} data-reveal><dt>{role}</dt><dd>{detail}</dd></div>)}</dl>
      </div></section>
      <section className="landing-entry-section landing-container" aria-labelledby="landing-entry-title" data-reveal><div><p className="landing-eyebrow">A CLEAR START TO YOUR DAY</p><h2 id="landing-entry-title">Let’s keep things moving.</h2><p>Your stores. Your team. Your next step.</p></div><div className="landing-entry-actions"><a className="landing-button" href={data.urls.login}>Log in to BW Operations</a><a href={data.urls.forgotPassword}>Forgot your password?</a></div></section>
    </main>
    <footer className="landing-footer"><div className="landing-container"><a className="landing-footer-brand" href="#top">BW <span>Operations</span></a><p>Need access? Contact your workspace administrator.</p><span>© {new Date().getFullYear()} · Developed by JAJ</span></div></footer>
  </div>;
}
