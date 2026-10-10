import test from 'node:test';
import assert from 'node:assert/strict';
import {createPage, lengthState, LIMITS} from '../assets/admin.js';

const componentNames = ['DataTable', 'TableActionsMenu', 'Breadcrumbs', 'DropdownMenu', 'DropdownOption', 'DropdownChevron', 'CheckboxField', 'Input', 'Textarea', 'LoadingButton', 'CollapsibleSection', 'MediaPicker'];

function render(initialStates) {
  const queue = [...initialStates];
  const rendered = [];
  const React = {
    Fragment: 'fragment',
    useState: (initial) => [queue.length ? queue.shift() : initial, () => {}],
    useEffect: () => {},
    createElement(type, props, ...children) {
      assert.ok(type, 'Component must exist in the platform host API');
      if (typeof type === 'function') return type({...props, children});
      rendered.push({type, props});
      return {type, props, children};
    },
  };
  const components = Object.fromEntries(componentNames.map((name) => [name, (props) => {
    rendered.push({type: name, props});
    return {component: name, ...props};
  }]));
  const icons = Object.fromEntries(['Settings', 'Save', 'Eye', 'Images', 'Image', 'X'].map((name) => [name, () => null]));
  const Page = createPage({React, components, icons, request: () => Promise.resolve({}), toast: () => {}});
  Page({navigate: () => {}});
  return rendered;
}

const preview = {
  title: 'За нас | Магазин', description: 'Описание', url: 'https://shop.example/about-us/team',
  og_title: 'За нас', og_description: 'Описание', image: null, robots: null, site_name: 'Магазин',
};

test('length state follows the recommended ranges', () => {
  assert.equal(lengthState(0, LIMITS.title), 'empty');
  assert.equal(lengthState(10, LIMITS.title), 'short');
  assert.equal(lengthState(45, LIMITS.title), 'good');
  assert.equal(lengthState(200, LIMITS.description), 'long');
});

test('the list renders totals, filters and the table', () => {
  const data = {records: [{type: 'page', id: 2, name: 'За нас', status: 'published', path: '/about-us', preview, analysis: {status: 'warning', checks: []}}], totals: {good: 1, warning: 1, error: 0}, page: 1, pages: 1};
  const rendered = render([{name: 'list'}, 'all', 'all', '', 1, data, '']);
  const table = rendered.find((node) => node.type === 'DataTable');
  assert.equal(table.props.data.length, 1);
  assert.equal(table.props.columns.find((c) => c.key === 'status').sortValue(data.records[0]), 1);
  assert.ok(rendered.some((node) => node.type === 'button' && node.props.className === 'seo-total seo-total-warning'));
});

test('the editor renders fields, the search preview and the checks', () => {
  const item = {type: 'page', id: 2, name: 'За нас', preview, analysis: {status: 'error', checks: [{level: 'error', code: 'description_missing', message: 'Липсва описание.'}]}, meta: {}};
  const values = {seo_title: '', meta_description: '', focus_keyword: '', og_title: '', og_description: '', canonical_url: '', og_image_media_id: null};
  const rendered = render([{name: 'edit', type: 'page', id: 2}, item, values, null, item, false, '']);
  assert.equal(rendered.filter((node) => node.type === 'Input' || node.type === 'Textarea').length, 6);
  const url = rendered.find((node) => node.type === 'span' && node.props.className === 'seo-serp-url');
  assert.ok(url, 'search preview URL is rendered');
  assert.ok(rendered.some((node) => node.type === 'li' && node.props.className === 'seo-check seo-check-error'));
});

test('settings render sitemap options and links', () => {
  const settings = {title_template: '{title} | {site}', home_title: '', default_image_media_id: 0, twitter_site: '', site_url: '', sitemap_pages: true, sitemap_products: true, sitemap_categories: false, robots_extra: '', delete_on_uninstall: false};
  const rendered = render([{name: 'settings'}, settings, null, false, '']);
  assert.equal(rendered.filter((node) => node.type === 'CheckboxField').length, 4);
  assert.ok(rendered.some((node) => node.type === 'a' && node.props.href === '/sitemap.xml'));
});
