import {
  createElement,
  Fragment,
  useMemo,
  useState
} from '@wordpress/element';

import { getAdminConfig } from './config';

type SummaryItem = {
  label: string;
  value: string;
  detail: string;
};

const summaryItems: SummaryItem[] = [
  {
    label: 'Sources',
    value: '0',
    detail: 'No connected sources'
  },
  {
    label: 'Queue',
    value: '0',
    detail: 'No pending jobs'
  },
  {
    label: 'Last sync',
    value: 'Never',
    detail: 'Waiting for the first run'
  }
];

export const App = (): JSX.Element => {
  const [selectedSource, setSelectedSource] = useState('WordPress Media Library');
  const config = useMemo(() => getAdminConfig(), []);

  return (
    <main className="min-h-[calc(100vh-96px)] bg-slate-50 px-4 py-6 text-slate-950 sm:px-6 lg:px-8">
      <div className="mx-auto flex max-w-6xl flex-col gap-6">
        <header className="flex flex-col gap-4 border-b border-slate-200 pb-5 md:flex-row md:items-end md:justify-between">
          <div>
            <p className="text-sm font-medium text-cyan-700">DocSync WP</p>
            <h1 className="mt-2 text-3xl font-semibold tracking-normal text-slate-950">
              Sync Control
            </h1>
          </div>
          <div className="rounded border border-slate-200 bg-white px-3 py-2 text-sm text-slate-600">
            Version {config.version}
          </div>
        </header>

        <section className="grid gap-4 md:grid-cols-3" aria-label="Sync summary">
          {summaryItems.map((item) => (
            <article
              className="rounded border border-slate-200 bg-white p-5 shadow-sm"
              key={item.label}
            >
              <p className="text-sm font-medium text-slate-500">{item.label}</p>
              <p className="mt-3 text-2xl font-semibold text-slate-950">{item.value}</p>
              <p className="mt-2 text-sm text-slate-600">{item.detail}</p>
            </article>
          ))}
        </section>

        <section className="grid gap-5 lg:grid-cols-[minmax(0,1fr)_320px]">
          <div className="rounded border border-slate-200 bg-white p-5 shadow-sm">
            <div className="flex flex-col gap-3 border-b border-slate-200 pb-4 sm:flex-row sm:items-center sm:justify-between">
              <div>
                <h2 className="text-lg font-semibold text-slate-950">Sources</h2>
                <p className="mt-1 text-sm text-slate-600">
                  {selectedSource}
                </p>
              </div>
              <select
                className="h-10 rounded border border-slate-300 bg-white px-3 text-sm text-slate-900 shadow-sm focus:border-cyan-600 focus:outline-none focus:ring-2 focus:ring-cyan-600/20"
                onChange={(event) => setSelectedSource(event.currentTarget.value)}
                value={selectedSource}
              >
                <option>WordPress Media Library</option>
                <option>External provider</option>
                <option>Local import folder</option>
              </select>
            </div>

            <div className="mt-5 overflow-hidden rounded border border-slate-200">
              <table className="w-full border-collapse text-left text-sm">
                <thead className="bg-slate-100 text-slate-600">
                  <tr>
                    <th className="px-4 py-3 font-medium">Name</th>
                    <th className="px-4 py-3 font-medium">Status</th>
                    <th className="px-4 py-3 font-medium">Updated</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-200 bg-white">
                  <tr>
                    <td className="px-4 py-4 text-slate-950">{selectedSource}</td>
                    <td className="px-4 py-4">
                      <span className="inline-flex rounded bg-slate-100 px-2 py-1 text-xs font-medium text-slate-700">
                        Not configured
                      </span>
                    </td>
                    <td className="px-4 py-4 text-slate-600">Never</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

          <aside className="rounded border border-slate-200 bg-white p-5 shadow-sm">
            <h2 className="text-lg font-semibold text-slate-950">API Context</h2>
            <dl className="mt-4 space-y-4 text-sm">
              <div>
                <dt className="font-medium text-slate-500">REST namespace</dt>
                <dd className="mt-1 break-all text-slate-900">{config.restUrl || 'Unavailable'}</dd>
              </div>
              <div>
                <dt className="font-medium text-slate-500">Runtime</dt>
                <dd className="mt-1 text-slate-900">wp-element</dd>
              </div>
            </dl>
          </aside>
        </section>
      </div>
    </main>
  );
};
