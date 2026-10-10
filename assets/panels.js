// Flex SEO inside the platform screens: the page, product and category editors, Settings → SEO and the pages list.
import {BASE, createUi} from './components.js';

export function createPanel(host, info) {
  const ui = createUi(host);
  return info?.location === 'settings.tab' ? ui.SettingsTab : ui.EditorPanel;
}

/** The SEO column in the pages list: one request for the ratings of every page. */
export function createColumn(host) {
  const ui = createUi(host);
  const h = host.React.createElement;
  const order = {error: 0, warning: 1, good: 2};
  return {
    label: 'SEO',
    async load() {
      const data = await host.request(BASE + '/items?type=page&per_page=1000');
      return new Map((data.records ?? []).map((row) => [row.id, row]));
    },
    render(page, ratings) {
      const row = ratings?.get(page.id);
      if (!row) return '—';
      const issues = row.analysis.checks.filter((check) => check.level !== 'info');
      return h('span', {title: issues.length ? issues.map((check) => check.message).join('\n') : 'Всички основни проверки са изпълнени.'}, h(ui.Badge, {status: row.analysis.status}));
    },
    sortValue(page, ratings) {
      const row = ratings?.get(page.id);
      return row ? order[row.analysis.status] : 3;
    },
  };
}
