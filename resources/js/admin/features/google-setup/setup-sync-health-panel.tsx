import { Fragment, createElement, useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { getSettingsHealth, requestSettingsHealthCron, type SettingsHealthCheck, type SettingsHealthResponse } from '../../api/settings-health-api';
import { AdminButton } from '../../shared/ui/admin-button';
import { buildSettingsHealthReport, formatHealthDay, formatHealthDuration } from './setup-health-summary';

const domain = 'brasth-document-sync-for-google-docs';
const guideUrl = 'https://docsyncwp.com/user-guide/';
const quotaUrl = 'https://console.cloud.google.com/apis/api/drive.googleapis.com/quotas';
const issuesUrl = 'https://github.com/Brasth/DocSync-WP/issues/new';
const icons = { pass: '✓', warning: '!', error: '!', unknown: '?' };
const statusLabels = {
  pass: __('Passing', domain),
  warning: __('Needs attention', domain),
  error: __('Problem', domain),
  unknown: __('Unknown', domain)
};

type CronState = 'idle' | 'busy' | 'requested' | 'failed';
type Props = {
  busy: boolean;
  onEditCredentials: () => void;
  onOpenConnections: () => void;
  onReconnect: () => Promise<void>;
};

const copyText = async (value: string): Promise<boolean> => {
  try {
    await navigator.clipboard.writeText(value);
    return true;
  } catch {
    const area = document.createElement('textarea');
    area.value = value;
    area.setAttribute('readonly', '');
    area.style.position = 'fixed';
    area.style.opacity = '0';
    document.body.appendChild(area);
    area.select();
    let copied = false;
    try { copied = document.execCommand('copy'); } catch { copied = false; }
    document.body.removeChild(area);
    return copied;
  }
};

export const SetupSyncHealthPanel = ({ busy, onEditCredentials, onOpenConnections, onReconnect }: Props): JSX.Element => {
  const [health, setHealth] = useState<SettingsHealthResponse | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [cron, setCron] = useState<CronState>('idle');
  const [cronError, setCronError] = useState('');
  const [copyNote, setCopyNote] = useState('');
  const [manualReport, setManualReport] = useState('');
  const mounted = useRef(true);
  const latest = useRef(0);

  const load = useCallback(() => {
    const ticket = ++latest.current;
    setLoading(true);
    setError('');
    getSettingsHealth().then((result) => {
      if (mounted.current && ticket === latest.current) { setHealth(result); }
    }).catch((caught) => {
      if (mounted.current && ticket === latest.current) { setError(caught instanceof Error ? caught.message : __('Could not load sync health.', domain)); }
    }).finally(() => {
      if (mounted.current && ticket === latest.current) { setLoading(false); }
    });
  }, []);

  useEffect(() => {
    mounted.current = true;
    load();
    return () => { mounted.current = false; };
  }, [load]);

  const runCron = async () => {
    setCron('busy');
    setCronError('');
    try {
      await requestSettingsHealthCron();
      if (mounted.current) { setCron('requested'); }
    } catch (caught) {
      if (mounted.current) {
        setCronError(caught instanceof Error ? caught.message : __('Could not request a cron run.', domain));
        setCron('failed');
      }
    }
  };

  const copyReport = async () => {
    if (!health) { return; }
    const report = buildSettingsHealthReport(health);
    const copied = await copyText(report);
    if (!mounted.current) { return; }
    setManualReport(copied ? '' : report);
    setCopyNote(copied ? __('Report copied. It has no account, settings or private content.', domain) : __('Could not copy automatically. Select the report below and copy it manually.', domain));
  };

  const renderAction = (check: SettingsHealthCheck) => {
    if (check.action === 'cron') {
      return <AdminButton disabled={cron === 'busy'} onClick={runCron} size="small">{cron === 'busy' ? __('Requesting…', domain) : cron === 'failed' ? __('Retry cron run', domain) : __('Run cron now', domain)}</AdminButton>;
    }
    if (check.action === 'connections') { return <AdminButton onClick={onOpenConnections} size="small">{__('View connections', domain)}</AdminButton>; }
    if (check.action === 'credentials') { return <AdminButton disabled={busy} onClick={onEditCredentials} size="small">{__('Review credentials', domain)}</AdminButton>; }
    if (check.action === 'account') { return <AdminButton disabled={busy} onClick={() => void onReconnect()} size="small">{__('Reconnect Google', domain)}</AdminButton>; }
    if (check.action === 'quota') { return <a className="button button-secondary docsync-wp-button" href={quotaUrl} rel="noreferrer" target="_blank">{__('Open Cloud console', domain)} ↗</a>; }
    return null;
  };

  const activity = health?.activity;
  const peak = Math.max(1, ...(activity?.days.map((day) => day.completed + day.failed) ?? [0]));
  const passing = health?.checks.filter((check) => check.status === 'pass').length ?? 0;
  const checkedAtMs = health?.checkedAt ? new Date(health.checkedAt).getTime() : NaN;
  const checkedLabel = Number.isNaN(checkedAtMs) ? __('Checked', domain) : Date.now() - checkedAtMs < 60000 ? __('Checked just now', domain) : sprintf(__('Checked %s', domain), new Date(checkedAtMs).toLocaleString());
  const firstDay = activity?.days[0];
  const lastDay = activity && activity.days.length > 1 ? activity.days[activity.days.length - 1] : undefined;

  return (
    <div className="docsync-wp-health">
      <section aria-labelledby="docsync-wp-health-title" aria-busy={loading} className="docsync-wp-health__main">
        <header className="docsync-wp-health__header">
          <div>
            <h2 id="docsync-wp-health-title">{__('Sync health', domain)}</h2>
            <p>{health ? sprintf(__('%1$s · %2$d of %3$d passing', domain), checkedLabel, passing, health.checks.length) : loading ? __('Running local checks…', domain) : __('Checks unavailable', domain)}</p>
            <p className="docsync-wp-health__scope">{__('These checks run on this site and do not contact Google.', domain)}</p>
          </div>
          <AdminButton disabled={loading} onClick={load}><svg aria-hidden="true" className="docsync-wp-health__refresh" fill="none" height="14" stroke="currentColor" strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" viewBox="0 0 24 24" width="14"><path d="M21 12a9 9 0 1 1-3-6.7" /><path d="M21 3v6h-6" /></svg>{loading ? __('Checking…', domain) : __('Run checks again', domain)}</AdminButton>
        </header>
        {error ? <div className="docsync-wp-health__error" role="alert"><span>{error}</span><AdminButton onClick={load} size="small">{__('Retry', domain)}</AdminButton></div> : null}
        {loading && !health ? <p className="docsync-wp-health__loading" role="status">{__('Running local checks…', domain)}</p> : null}
        {health ? <ul className="docsync-wp-health__rows">{health.checks.map((check) => (
          <li className={`docsync-wp-health__row is-${check.status}`} key={check.id}>
            <span aria-hidden="true" className="docsync-wp-health__icon">{icons[check.status]}</span>
            <div className="docsync-wp-health__body">
              <strong>{check.title}<span className="screen-reader-text">{`: ${statusLabels[check.status]}`}</span></strong>
              <p>{check.description}</p>
              {check.action === 'cron' && cron === 'requested' ? <p className="docsync-wp-health__note" role="status">{__('Cron run requested. It runs in the background; refresh the checks to see the latest status.', domain)}</p> : null}
              {check.action === 'cron' && cron === 'failed' ? <p className="docsync-wp-health__note is-error" role="alert">{cronError}</p> : null}
              {check.action === 'cron' ? <a href={guideUrl} rel="noreferrer" target="_blank">{__('Read the cron guide', domain)} ↗</a> : null}
              {check.fix && check.fix.length > 0 ? <details className="docsync-wp-health__fix"><summary>{__('How to fix', domain)}</summary><ol>{check.fix.map((step, index) => <li key={index}>{step}</li>)}</ol></details> : null}
            </div>
            <div className="docsync-wp-health__actions">{renderAction(check)}</div>
          </li>
        ))}</ul> : null}
      </section>
      <aside className="docsync-wp-health__aside">
        <section aria-labelledby="docsync-wp-health-activity" className="docsync-wp-health__card">
          <h2 id="docsync-wp-health-activity">{__('Last 7 days', domain)}</h2>
          {activity ? <Fragment>
            <dl className="docsync-wp-health__metrics">
              <div><dt>{__('Syncs completed', domain)}</dt><dd>{activity.completed}</dd></div>
              <div className="is-failed"><dt>{__('Syncs failed', domain)}</dt><dd>{activity.failed}</dd></div>
              <div><dt>{__('Median sync time', domain)}</dt><dd>{activity.medianSeconds === null ? '—' : formatHealthDuration(activity.medianSeconds)}</dd></div>
              <div><dt>{__('Waiting on cron', domain)}</dt><dd>{activity.waiting}</dd></div>
            </dl>
            {activity.medianSeconds === null ? <p className="docsync-wp-health__hint">{__('Median time needs a recorded start and finish for the same job.', domain)}</p> : null}
            {activity.days.length > 0 ? <ol aria-label={__('Completed and failed syncs per day', domain)} className="docsync-wp-health__chart">{activity.days.map((day) => (
              <li key={day.date} title={sprintf(__('%1$s: %2$d completed, %3$d failed', domain), formatHealthDay(day.date), day.completed, day.failed)}>
                <span className="docsync-wp-health__bar">
                  {day.failed > 0 ? <span className="is-failed" style={{ height: `${(day.failed / peak) * 100}%` }} /> : null}
                  {day.completed > 0 ? <span className="is-completed" style={{ height: `${(day.completed / peak) * 100}%` }} /> : null}
                </span>
                <span className="screen-reader-text">{sprintf(__('%1$s: %2$d completed, %3$d failed', domain), formatHealthDay(day.date), day.completed, day.failed)}</span>
              </li>
            ))}</ol> : null}
            {firstDay ? <p aria-hidden="true" className="docsync-wp-health__chart-days"><span>{formatHealthDay(firstDay.date)}</span>{lastDay ? <span>{formatHealthDay(lastDay.date)}</span> : null}</p> : null}
            {activity.limited ? <p className="docsync-wp-health__hint">{__('Partial view: activity history is retained for a limited time and at most 500 sources are read, so older or additional events may be missing.', domain)}</p> : null}
          </Fragment> : <p className="docsync-wp-health__hint">{loading ? __('Loading activity…', domain) : __('Activity is unavailable until the checks load.', domain)}</p>}
        </section>
        <section aria-labelledby="docsync-wp-health-help" className="docsync-wp-health__card">
          <h2 id="docsync-wp-health-help">{__('Need help?', domain)}</h2>
          <p>{__('The report has check results, version numbers and recent sync step codes. It never includes accounts, emails, settings, URLs or document content.', domain)}</p>
          {health ? <p className="docsync-wp-health__versions">{sprintf(__('WordPress %1$s · PHP %2$s · Plugin %3$s', domain), health.versions.wordpress || '—', health.versions.php || '—', health.versions.plugin || '—')}</p> : null}
          <div className="docsync-wp-health__help-actions">
            <AdminButton disabled={!health} onClick={copyReport}>{__('Copy report', domain)}</AdminButton>
            <a className="button button-secondary docsync-wp-button" href={issuesUrl} rel="noreferrer noopener" target="_blank">{__('Report an issue', domain)} ↗</a>
          </div>
          {copyNote ? <p className="docsync-wp-health__hint" role="status">{copyNote}</p> : null}
          {manualReport ? <label className="docsync-wp-health__manual"><span>{__('Report (redacted)', domain)}</span><textarea onFocus={(event) => event.currentTarget.select()} readOnly rows={8} value={manualReport} /></label> : null}
        </section>
      </aside>
    </div>
  );
};
