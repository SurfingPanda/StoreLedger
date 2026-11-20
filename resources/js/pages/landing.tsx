import { BellRing, Building2, Check, ChevronDown, ClipboardCheck, CreditCard, Droplets, FileText, History, LockKeyhole, Monitor, Paperclip, ShieldCheck, Wifi, Zap } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { AppData } from '@/lib/auth';

const modules = [
  { name: 'Rental', detail: 'Store rent & lease payments', icon: Building2 },
  { name: 'Electricity', detail: 'Monthly electricity statements', icon: Zap },
  { name: 'Water', detail: 'Water bills & account details', icon: Droplets },
  { name: 'Internet', detail: 'Providers & billing cycles', icon: Wifi },
  { name: 'UTAK POS', detail: 'Subscriptions & renewals', icon: Monitor },
];
const steps = [
  { id: 'submit', icon: FileText, team: 'ADMIN / RSO', title: 'A clear start.\nEvery single bill.', text: 'Add the store, billing period, amount, and due date. Attach the statement so Accounting has the full picture from the start.', status: 'SOA submitted', next: 'Ready for Accounting', detail: 'Statement of account attached', file: 'Statement of account', reference: 'Request ready for review' },
  { id: 'review', icon: ClipboardCheck, team: 'ACCOUNTING', title: 'Less back-and-forth.\nMore moving forward.', text: 'Review the statement and prepare the voucher. Pass it to Finance, or return it with a clear reason when something needs correcting.', status: 'Voucher prepared', next: 'Ready for Finance', detail: 'Statement reviewed · Voucher recorded', file: 'Statement + voucher reference', reference: 'Voucher reference: VCH-SAMPLE' },
  { id: 'payment', icon: CreditCard, team: 'FINANCE', title: 'Payment recorded.\nLoop closed.', text: 'Save the payment date, method, and reference. Attach proof of payment, with every document and handoff kept together for the next time you need it.', status: 'Payment recorded', next: 'Complete & on record', detail: 'Payment details and proof saved', file: 'Statement + payment proof', reference: 'Payment reference: PAY-SAMPLE' },
];
const roles = [
  ['Admin', 'Manage requests, stores, suppliers, user access, and notification recipients.'],
  ['Accounting', 'Review statements, prepare vouchers, and return requests for correction.'],
  ['Finance', 'Review vouchers, record payments, and attach payment proof.'],
  ['RSO', 'Submit and correct Rental, Water, and Electricity bills, and follow their progress.'],
  ['IT', 'View monitoring records and follow request progress across the workspace.'],
];

export default function Landing({ data }: { data: AppData }) {
  const pageRef = useRef<HTMLDivElement>(null);
  const [activeStep, setActiveStep] = useState(0);

  useEffect(() => {
    const page = pageRef.current;
    if (!page) return;
    const preference = window.matchMedia('(prefers-reduced-motion: reduce)');
    const targets = page.querySelectorAll<HTMLElement>('[data-reveal]');
    const storySteps = Array.from(page.querySelectorAll<HTMLElement>('[data-story-step]'));
    const hero = page.querySelector<HTMLElement>('.landing-hero');
    let observer: IntersectionObserver | undefined;
    let frame = 0;
    const updateScroll = () => {
      frame = 0;
      const maxScroll = document.documentElement.scrollHeight - window.innerHeight;
      page.style.setProperty('--page-progress', String(maxScroll > 0 ? Math.min(1, Math.max(0, window.scrollY / maxScroll)) : 0));
      if (hero && !preference.matches) {
        const rect = hero.getBoundingClientRect();
        const progress = Math.max(0, Math.min(1, -rect.top / rect.height));
        page.style.setProperty('--hero-shift', `${progress * 65}px`);
      }
      let current = 0;
      storySteps.forEach((step, index) => {
        if (step.getBoundingClientRect().top <= window.innerHeight * .52) current = index;
      });
      setActiveStep(previous => previous === current ? previous : current);
    };
    const queueScroll = () => { if (!frame) frame = window.requestAnimationFrame(updateScroll); };
    const setup = () => {
      observer?.disconnect();
      targets.forEach(target => target.classList.remove('is-waiting', 'is-visible'));
      page.style.setProperty('--hero-shift', '0px');
      if (!preference.matches && 'IntersectionObserver' in window) {
        observer = new IntersectionObserver(entries => entries.forEach(entry => {
          if (!entry.isIntersecting) return;
          entry.target.classList.replace('is-waiting', 'is-visible');
          observer?.unobserve(entry.target);
        }), { threshold: .08 });
        targets.forEach(target => { target.classList.add('is-waiting'); observer!.observe(target); });
      }
      queueScroll();
    };
    const showFocus = (event: FocusEvent) => {
      if (!(event.target instanceof Element)) return;
      let target = event.target.closest('[data-reveal]');
      while (target) {
        target.classList.remove('is-waiting', 'is-visible');
        target = target.parentElement?.closest('[data-reveal]') ?? null;
      }
    };
    setup();
    window.addEventListener('scroll', queueScroll, { passive: true });
    window.addEventListener('resize', queueScroll);
    window.addEventListener('load', queueScroll);
    preference.addEventListener('change', setup);
    page.addEventListener('focusin', showFocus);
    return () => {
      observer?.disconnect();
      if (frame) window.cancelAnimationFrame(frame);
      window.removeEventListener('scroll', queueScroll);
      window.removeEventListener('resize', queueScroll);
      window.removeEventListener('load', queueScroll);
      preference.removeEventListener('change', setup);
      page.removeEventListener('focusin', showFocus);
      targets.forEach(target => target.classList.remove('is-waiting', 'is-visible'));
    };
  }, []);

  return <div className="landing-page" ref={pageRef} id="top">
    <a className="landing-skip" href="#landing-main">Skip to content</a>
    <header className="landing-header"><div className="landing-container landing-header-inner">
      <a href="#top" className="landing-brand" aria-label="BW Operations home"><img src={data.assets.logo} width={90} height={90} alt="BW Superbakeshop" /><span>Operations<small>THE STORE WORKSPACE</small></span></a>
      <nav aria-label="Page sections" className="landing-navigation"><a href="#coverage">What we track</a><a href="#workflow">How it works</a><a href="#teams">Your team</a></nav>
      <a href={data.urls.login} className="landing-button landing-header-login">Log in</a>
    </div><div className="landing-scroll-progress" aria-hidden="true" /></header>
    <main id="landing-main" tabIndex={-1}>
      <section className="landing-hero" aria-labelledby="landing-title"><div className="landing-container landing-hero-grid">
        <div className="landing-hero-copy" data-reveal>
          <p className="landing-eyebrow">BUILT FOR THE PEOPLE BEHIND YOUR STORES</p>
          <h1 id="landing-title">Less chasing.<br />More clarity.<br /><span>Better days.</span></h1>
          <p className="landing-hero-intro">Keep every store bill and renewal moving. One connected workspace, from the first statement to the final payment.</p>
          <div className="landing-hero-actions"><a href={data.urls.login} className="landing-button">Enter your workspace</a><a href="#workflow" className="landing-text-link">See how it works</a></div>
          <p className="landing-access-note"><LockKeyhole size={14} aria-hidden="true" /> For authorized BW Superbakeshop team members</p>
        </div>
        <div className="landing-hero-art" data-reveal>
          <div className="landing-art-orbit" aria-hidden="true" />
          <div className="landing-art-word" aria-hidden="true">bw.</div>
          <div className="landing-art-parallax">
            <div className="landing-floating-label"><span><Building2 size={18} aria-hidden="true" /></span><div>Every store.<strong>One connected process.</strong></div></div>
            <figure className="landing-hero-receipt">
              <div className="landing-receipt-top"><span className="landing-mini-brand">BW <span>Operations</span></span><span>Example record</span></div>
              <div className="landing-receipt-body"><span className="landing-receipt-icon"><Zap size={26} strokeWidth={1.6} aria-hidden="true" /></span><p className="landing-eyebrow">MONTHLY ELECTRICITY</p><h2>A bill with a<br />clear next step.</h2><div className="landing-receipt-amount"><span>Sample store · Bill amount</span><strong>₱4,250<span>.00</span></strong></div><div className="landing-receipt-handoff"><span><Check size={14} aria-hidden="true" /> SOA submitted</span><span><Check size={14} aria-hidden="true" /> Voucher prepared</span></div><div className="landing-receipt-next"><CreditCard size={19} aria-hidden="true" /><div><small>UP NEXT</small><strong>Finance records payment</strong></div></div></div>
              <figcaption>Illustrative workflow · Your records stay inside</figcaption>
            </figure>
            <div className="landing-floating-complete"><span><Check size={19} aria-hidden="true" /></span><div>A little less follow-up.<strong>A lot more progress.</strong></div></div>
          </div>
        </div>
        <a className="landing-scroll-cue" href="#coverage"><span>Discover a clearer way to work</span><ChevronDown size={19} aria-hidden="true" /></a>
      </div></section>
      <section id="coverage" className="landing-coverage" aria-labelledby="landing-coverage-title"><div className="landing-container">
        <div className="landing-coverage-heading" data-reveal><p className="landing-eyebrow">ALL THE EVERYDAY ESSENTIALS</p><h2 id="landing-coverage-title">Different bills.<br className="landing-mobile-break" /> Same clear picture.</h2><p>Five areas to track. One familiar process.</p></div>
        <div className="landing-module-list">{modules.map(({ name, detail, icon: Icon }) => <article key={name} data-reveal><span><Icon size={27} strokeWidth={1.6} aria-hidden="true" /></span><h3>{name}</h3><p>{detail}</p></article>)}</div>
      </div></section>
      <section id="workflow" className="landing-workflow" aria-labelledby="landing-workflow-title"><div className="landing-container">
        <div className="landing-story-heading" data-reveal><p className="landing-eyebrow">FROM STATEMENT TO SETTLEMENT</p><h2 id="landing-workflow-title">Good work keeps moving.</h2><p>Follow a request through the people who make it happen.</p></div>
        <div className="landing-story-layout">
          <div className="landing-story-steps">{steps.map(({ id, icon: Icon, team, title, text, next }, index) => <article id={`step-${id}`} className={`landing-story-step ${activeStep === index ? 'is-active' : ''}`} data-story-step key={id}>
            <div className="landing-step-copy" data-reveal><span className="landing-step-icon"><Icon size={26} strokeWidth={1.5} aria-hidden="true" /></span><p className="landing-eyebrow">{team}</p><h3>{title.split('\n').map((line, i) => <span key={line}>{i > 0 && <br />}{line}</span>)}</h3><p>{text}</p><span className="landing-step-destination"><Check size={15} aria-hidden="true" />{next}</span></div>
          </article>)}</div>
          <div className="landing-story-preview"><div className={`landing-demo landing-demo-stage-${activeStep}`}>
            <div className="landing-demo-top"><span className="landing-mini-brand">BW <span>Request journey</span></span><span>Illustrative preview</span></div>
            <div className="landing-demo-body"><div className="landing-demo-title"><span><Zap size={22} aria-hidden="true" /></span><div><p>Electricity SOA</p><h3>Monthly electricity</h3></div></div><div className="landing-demo-amount"><span>Sample store · Amount due</span><strong>₱4,250.00</strong></div>
              <div className="landing-demo-panels">{steps.map(({ id, icon: Icon, status, detail, file, reference }, index) => <div className={`landing-demo-panel ${activeStep === index ? 'is-active' : ''}`} aria-hidden={activeStep !== index} key={id}><span className="landing-demo-status"><Icon size={16} aria-hidden="true" />{status}</span><h4>{detail}</h4><div className="landing-demo-file"><Paperclip size={17} aria-hidden="true" /><span>{file}</span><Check size={15} aria-hidden="true" /></div><p className="landing-demo-reference">{reference}</p></div>)}</div>
              <ol className="landing-demo-timeline" aria-label="Request stages">{steps.map(({ id, status }, index) => <li className={index <= activeStep ? 'is-complete' : ''} key={id}><span>{index <= activeStep ? <Check size={13} aria-hidden="true" /> : <span aria-hidden="true" />}</span><strong>{status}</strong></li>)}</ol>
            </div>
            <nav className="landing-story-navigation" aria-label="Explore request stages">{steps.map(({ id, team }, index) => <a href={`#step-${id}`} aria-current={activeStep === index ? 'step' : undefined} key={id}>{team}</a>)}</nav>
          </div></div>
        </div>
      </div></section>
      <section className="landing-benefits landing-container" aria-labelledby="landing-benefits-title"><div className="landing-section-heading" data-reveal><p className="landing-eyebrow">THE SMALL DETAILS. THE BIG DIFFERENCE.</p><h2 id="landing-benefits-title">Built for a smoother day.</h2></div><div className="landing-benefit-grid">
        <article className="landing-benefit-featured" data-reveal><History size={32} strokeWidth={1.4} aria-hidden="true" /><h3>Every handoff.<br />One shared story.</h3><p>Statements, vouchers, payments, comments, and corrections. Find the whole journey in one activity timeline.</p><div className="landing-mini-timeline" aria-hidden="true"><span><Check size={13} />Statement attached</span><span><Check size={13} />Voucher processed</span><span><Check size={13} />Payment recorded</span></div></article>
        <article data-reveal><BellRing size={30} strokeWidth={1.5} aria-hidden="true" /><h3>Know what needs<br />your attention.</h3><p>Personal notifications bring overdue bills and requests that need your action into view.</p><div className="landing-reminder-preview"><BellRing size={19} aria-hidden="true" /><span>Your next task.<strong>Always in view.</strong></span></div></article>
        <article data-reveal><Building2 size={30} strokeWidth={1.5} aria-hidden="true" /><h3>Connected records.<br />Clearer context.</h3><p>Keep stores, suppliers, billing accounts, and previous requests linked, even as the details change.</p><div className="landing-directory-preview" aria-hidden="true"><span>Stores</span><span>Suppliers</span><span>Requests</span></div></article>
      </div></section>
      <section id="teams" className="landing-team-section" aria-labelledby="landing-team-title"><div className="landing-container landing-team-layout"><div className="landing-team-intro" data-reveal><span className="landing-team-emblem"><ShieldCheck size={32} strokeWidth={1.4} aria-hidden="true" /></span><p className="landing-eyebrow">YOUR PART. EVERYONE’S PROGRESS.</p><h2 id="landing-team-title">One team.<br />A shared direction.</h2><p>Sign in with your assigned account. See the requests, actions, and notifications relevant to your role.</p><a href={data.urls.login} className="landing-text-link">Find your workspace</a></div><dl className="landing-role-list">{roles.map(([role, detail]) => <div key={role} data-reveal><dt>{role}</dt><dd>{detail}</dd></div>)}</dl></div></section>
      <section className="landing-entry-section" aria-labelledby="landing-entry-title"><div className="landing-container" data-reveal><p className="landing-eyebrow">A FRESH START TO YOUR DAY</p><h2 id="landing-entry-title">Ready for a little<br /><span>more clarity?</span></h2><p>Your stores. Your team. Your next step.</p><a href={data.urls.login} className="landing-button">Log in to BW Operations</a><a href={data.urls.forgotPassword} className="landing-recovery-link">Forgot your password?</a></div></section>
    </main>
    <footer className="landing-footer"><div className="landing-container"><a className="landing-footer-brand" href="#top">BW <span>Operations</span></a><p>Need access? Contact your workspace administrator.</p><span>© {new Date().getFullYear()} · Developed by JAJ</span></div></footer>
  </div>;
}
