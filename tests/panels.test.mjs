import test from 'node:test';
import assert from 'node:assert/strict';
import {createColumn, createPanel, createRowAction} from '../assets/panels.js';
import {editorPath, lengthState, LIMITS, sourceFrom} from '../assets/components.js';

const componentNames = ['DataTable', 'TableActionsMenu', 'DropdownMenu', 'DropdownOption', 'DropdownChevron', 'CheckboxField', 'Input', 'Textarea', 'LoadingButton', 'CollapsibleSection', 'MediaPicker'];

function harness(initialStates, requests = []) {
  const queue = [...initialStates];
  const rendered = [];
  const effects = [];
  const React = {
    Fragment: 'fragment',
    useState: (initial) => [queue.length ? queue.shift() : (typeof initial === 'function' ? initial() : initial), () => {}],
    useEffect: (effect) => effects.push(effect),
    useRef: (initial) => ({current: initial}),
    useId: () => ':r1:',
    createElement(type, props, ...children) {
      assert.ok(type, 'Component must exist in the platform host API');
      if (typeof type === 'function') return type({...props, children});
      rendered.push({type, props, children});
      return {type, props, children};
    },
  };
  const components = Object.fromEntries(componentNames.map((name) => [name, (props) => {
    rendered.push({type: name, props});
    return {component: name, ...props};
  }]));
  const icons = Object.fromEntries(['Settings', 'Save', 'Eye', 'Images', 'Image', 'X'].map((name) => [name, () => null]));
  const host = {
    React, components, icons, toast: () => {},
    request: async (...args) => { requests.push(args); return {records: [{id: 2, analysis: {status: 'warning', checks: [{level: 'warning', message: 'Кратко заглавие.'}, {level: 'info', message: 'Инфо'}]}}]}; },
    useWorkspaceChanged: () => () => {},
    useWorkspaceSaved: () => () => {},
  };
  return {host, rendered, effects};
}

const item = {
  type: 'page', id: 2, name: 'За нас', status: 'published', path: '/about-us',
  preview: {title: 'За нас | Магазин', description: 'Описание', url: 'https://shop.example/about-us', og_title: 'За нас', og_description: 'Описание', image: null, robots: null, site_name: 'Магазин'},
  analysis: {status: 'error', checks: [{level: 'error', code: 'description_missing', message: 'Липсва описание.'}]},
  meta: {},
};

test('helpers describe lengths, unsaved sources and editor paths', () => {
  assert.equal(lengthState(45, LIMITS.title), 'good');
  assert.deepEqual(sourceFrom({type: 'page', values: {title: 'Нова', slug: 'nova', content: '<p>x</p>', featured_media_id: null, parent_id: 3}}), {title: 'Нова', slug: 'nova', content: '<p>x</p>'});
  assert.deepEqual(sourceFrom({type: 'product', values: {name: 'Чаша', price: '12.50', images: [1], media: [{id: 1}]}}), {name: 'Чаша', price: '12.50', images: [1]});
  assert.equal(editorPath({type: 'page', id: 4}), '/pages/4/edit');
  assert.equal(editorPath({type: 'product', id: 7}), '/extension-pages/flex-commerce-products/7/edit');
  assert.equal(editorPath({type: 'category', id: 3}), '/extension-pages/flex-commerce-categories/3/edit');
});

test('the editor panel shows the fields, the search preview and the checks', () => {
  const values = {seo_title: '', meta_description: '', focus_keyword: '', og_title: '', og_description: '', canonical_url: '', og_image_media_id: null};
  const {host, rendered} = harness([values, null, item, '']);
  const Panel = createPanel(host, {location: 'page.editor'});
  Panel({context: {type: 'page', id: 2, values: {title: 'За нас'}}, registerSave: () => () => {}, changed: () => {}});
  assert.equal(rendered.filter((node) => node.type === 'Input' || node.type === 'Textarea').length, 6);
  // The groups are the platform's collapsible sections, nested in the panel section.
  const groups = rendered.filter((node) => node.type === 'CollapsibleSection' && node.props?.className === 'seo-group');
  assert.deepEqual(groups.map((node) => node.props.title), ['Резултати в търсачките', 'Ключова дума', 'Споделяне в социални мрежи', 'Разширени настройки', 'Преглед в търсачките', 'Преглед при споделяне', 'Проверки']);
  assert.deepEqual(groups.map((node) => node.props.storageKey), ['seo-editor-search', 'seo-editor-keyword', 'seo-editor-social', 'seo-editor-advanced', 'seo-editor-preview-search', 'seo-editor-preview-social', 'seo-editor-checks'], 'Each group remembers whether it is open.');
  for (const group of groups) assert.equal(typeof group.props.icon, 'function', 'Each group has an icon.');
  assert.ok(rendered.some((node) => node.type === 'span' && node.props?.className === 'seo-serp-url'));
  assert.ok(rendered.some((node) => node.type === 'li' && node.props?.className === 'seo-check seo-check-error'));
});

test('the save handler writes only edited SEO values after the record is saved', async () => {
  const values = {seo_title: 'Ново', meta_description: '', focus_keyword: '', og_title: '', og_description: '', canonical_url: '', og_image_media_id: null};
  const requests = [];
  const {host, effects} = harness([values, null, item, ''], requests);
  let handler = null;
  const Panel = createPanel(host, {location: 'commerce.product.editor'});
  Panel({context: {type: 'product', id: null, values: {name: 'Чаша'}}, registerSave: (fn) => { handler = fn; return () => {}; }, changed: () => {}});
  effects.forEach((effect) => effect());
  assert.equal(typeof handler, 'function');
  await handler({id: 9});
  assert.equal(requests.filter((args) => args[1] === 'PUT').length, 0, 'Unedited panels do not save.');
});

test('settings tab renders the global settings and the site check', () => {
  const settings = {title_template: '{title} | {site}', home_title: '', default_image_media_id: 0, twitter_site: '', site_url: '', sitemap_pages: true, sitemap_products: true, sitemap_categories: false, robots_extra: '', delete_on_uninstall: false};
  const {host, rendered} = harness([settings, null, false, '', 'all', 'all', '', 1, {records: [item], totals: {good: 0, warning: 0, error: 1}, page: 1, pages: 1}, '']);
  const Tab = createPanel(host, {location: 'settings.tab'});
  Tab({navigate: () => {}});
  assert.equal(rendered.filter((node) => node.type === 'CheckboxField').length, 4);
  assert.ok(rendered.some((node) => node.type === 'DataTable'));
  assert.ok(rendered.some((node) => node.type === 'a' && node.props?.href === '/sitemap.xml'));
});

test('the pages list column loads every rating once and sorts problems first', async () => {
  const requests = [];
  const {host} = harness([], requests);
  const column = createColumn(host);
  const ratings = await column.load([{id: 2}]);
  assert.match(requests[0][0], /items\?type=page&per_page=1000$/);
  assert.equal(column.sortValue({id: 2}, ratings), 1);
  assert.equal(column.sortValue({id: 5}, ratings), 3);
  assert.equal(column.render({id: 5}, ratings), '—');
  const cell = column.render({id: 2}, ratings);
  assert.equal(cell.props.title, 'Кратко заглавие.');
});

test('fields show the generated text as real text until the user writes their own', () => {
  const values = {seo_title: '', meta_description: 'Мое описание', focus_keyword: '', og_title: '', og_description: '', canonical_url: '', og_image_media_id: null};
  const custom = {meta_description: true};
  const {host, rendered} = harness([values, null, item, '', custom]);
  createPanel(host, {location: 'page.editor'})({context: {type: 'page', id: 2, values: {}}, registerSave: () => () => {}, changed: () => {}});
  const field = (label) => rendered.find((node) => node.type === 'label' && node.children[0] === label);
  const control = (label) => field(label).children[1];
  assert.equal(control('SEO заглавие').value, 'За нас | Магазин', 'The generated title is real text, not a placeholder.');
  assert.equal(control('SEO заглавие').placeholder, undefined);
  assert.equal(control('Meta описание').value, 'Мое описание', 'Own text is shown as written.');
  assert.equal(control('Canonical URL').value, 'https://shop.example/about-us');
  assert.ok(field('SEO заглавие').props.className.includes('is-auto'));
  assert.ok(field('Meta описание').props.className.includes('is-custom'));
  assert.ok(rendered.some((node) => node.type === 'button' && node.props?.className === 'seo-auto-reset'), 'Own text can be reset to the generated text.');
});

test('the pages list row action opens the page editor', () => {
  const action = createRowAction();
  const opened = [];
  assert.equal(action.label, 'Редактирай SEO');
  action.onSelect({id: 7}, {navigate: (path) => opened.push(path)});
  assert.deepEqual(opened, ['/pages/7/edit']);
});

test('the settings tab is chosen by the screen.tab slot', () => {
  const {host} = harness([]);
  assert.notEqual(createPanel(host, {point: 'settings', slot: 'screen.tab'}), createPanel(host, {point: 'page.editor', slot: 'screen.section'}));
});
