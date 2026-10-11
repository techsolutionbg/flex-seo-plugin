export const LIMITS = {title: [30, 60], description: [70, 160]};
export const BASE = '/admin/plugins/flex/seo';
export const FIELD_KEYS = ['seo_title', 'meta_description', 'focus_keyword', 'og_title', 'og_description', 'canonical_url', 'og_image_media_id'];

export function lengthState(length, [min, max]) {
  if (length === 0) return 'empty';
  return length < min ? 'short' : length > max ? 'long' : 'good';
}

/** The record values the editor has not saved yet, which the preview and the checks use. */
export function sourceFrom(context) {
  const values = context?.values ?? {};
  const pick = (keys) => Object.fromEntries(keys.filter((key) => values[key] !== undefined && values[key] !== null).map((key) => [key, values[key]]));
  if (context?.type === 'page') return pick(['title', 'slug', 'content', 'featured_media_id', 'status']);
  if (context?.type === 'product') return pick(['name', 'slug', 'description', 'sku', 'price', 'sale_price', 'currency', 'stock', 'images', 'status']);
  return pick(['name', 'slug', 'description', 'status']);
}

/** Where the SEO section of a page, product or category is edited. */
export function editorPath(row) {
  if (row.type === 'page') return '/pages/' + row.id + '/edit';
  return '/extension-pages/flex-commerce-' + (row.type === 'product' ? 'products' : 'categories') + '/' + row.id + '/edit';
}

const cache = new WeakMap();

export function createUi(host) {
  if (cache.has(host)) return cache.get(host);
  const {React, components: C, request, toast, icons} = host;
  const h = React.createElement;
  const statusLabels = {good: 'Добре', warning: 'Предупреждения', error: 'Проблеми'};
  const typeLabels = {page: 'Страница', product: 'Продукт', category: 'Категория'};
  const badgeClass = {good: 'active', warning: 'draft', error: 'inactive'};
  const levelLabels = {error: 'Проблем', warning: 'Предупреждение', info: 'Информация'};
  const stylesheet = '/extensions/flex/seo/assets/assets/admin.css';

  function Styles() {
    return h('link', {rel: 'stylesheet', href: stylesheet});
  }

  function Badge({status}) {
    return h('span', {className: 'react-status-badge status-' + badgeClass[status]}, statusLabels[status]);
  }

  function Select({value, options, onChange, label}) {
    return h('label', {className: 'react-form-field'}, label,
      h(C.DropdownMenu, {ariaLabel: label, trigger: h(React.Fragment, null, h('span', null, options[value]), h(C.DropdownChevron)), triggerClassName: 'react-form-dropdown'},
        ...Object.entries(options).map(([key, text]) => h(C.DropdownOption, {key, selected: value === key, onClick: () => onChange(key)}, text))));
  }

  function Counter({value, limits}) {
    const length = value.length;
    return h('small', {className: 'seo-counter seo-counter-' + lengthState(length, limits), 'aria-live': 'polite'},
      length + ' символа · препоръчително ' + limits[0] + '–' + limits[1]);
  }

  function TextField({label, hint, value, onChange, maxLength, multiline, limits, placeholder}) {
    const Control = multiline ? C.Textarea : C.Input;
    return h('label', {className: 'react-form-field'}, label,
      h(Control, {value, maxLength, placeholder, onChange: (e) => onChange(e.target.value)}),
      limits && h(Counter, {value: value || placeholder || '', limits}),
      hint && h('small', {className: 'react-field-hint'}, hint));
  }

  /**
   * A field that shows the generated text as real, editable text. While the user has not changed it the value is
   * not stored and keeps following the template and the content; editing makes it the user's own text.
   */
  function AutoTextField({label, hint, value, auto, custom, onChange, onReset, maxLength, multiline, limits}) {
    const Control = multiline ? C.Textarea : C.Input;
    const shown = custom ? value : (auto ?? '');
    return h('label', {className: 'react-form-field seo-auto-field' + (custom ? ' is-custom' : ' is-auto')}, label,
      h(Control, {value: shown, maxLength, onChange: (e) => onChange(e.target.value)}),
      limits && h(Counter, {value: shown, limits}),
      h('span', {className: 'seo-auto-state'},
        custom
          ? h(React.Fragment, null,
            h('small', {className: 'react-field-hint'}, 'Ваш текст.'),
            h('button', {type: 'button', className: 'seo-auto-reset', onClick: onReset}, 'Върни автоматичния текст'))
          : h('small', {className: 'react-field-hint'}, 'Автоматичен текст, обновява се със съдържанието. Редактирайте го, за да зададете свой.')),
      hint && h('small', {className: 'react-field-hint'}, hint));
  }

  function ImageField({label, hint, image, onChange}) {
    const [open, setOpen] = React.useState(false);
    return h('div', {className: 'react-form-field seo-image-field'}, label,
      image && h('img', {src: image.thumbnail_url || image.url, alt: image.alt || '', className: 'seo-image-thumb'}),
      h('div', {className: 'seo-actions'},
        h(C.LoadingButton, {type: 'button', icon: h(icons.Image), onClick: () => setOpen(true)}, image ? 'Смени изображението' : 'Избери изображение'),
        image && h(C.LoadingButton, {type: 'button', icon: h(icons.X), onClick: () => onChange(null)}, 'Премахни')),
      hint && h('small', {className: 'react-field-hint'}, hint),
      h(C.MediaPicker, {open, imagesOnly: true, onClose: () => setOpen(false), onSelect: (record) => { setOpen(false); onChange(record); }}));
  }

  function SearchPreview({preview}) {
    let url = preview.url;
    try {
      const parsed = new URL(preview.url);
      url = parsed.host + parsed.pathname.replace(/\/$/, '').split('/').filter(Boolean).map((part) => ' › ' + decodeURIComponent(part)).join('');
    } catch { /* keep the raw value */ }
    return h(Group, {id: 'preview-search', icon: icons.Search ?? icons.Eye, title: 'Преглед в търсачките'},
      h('div', {className: 'seo-serp'},
        h('span', {className: 'seo-serp-site'}, preview.site_name),
        h('span', {className: 'seo-serp-url'}, url),
        h('span', {className: 'seo-serp-title'}, preview.title),
        h('span', {className: 'seo-serp-description'}, preview.description || 'Няма описание. Търсачката ще избере текст от страницата.')),
      preview.robots && h('p', {className: 'seo-note'}, 'Скрита от търсачките (' + preview.robots + ').'));
  }

  function SocialPreview({preview}) {
    let site = '';
    try { site = new URL(preview.url).host; } catch { /* no host */ }
    return h(Group, {id: 'preview-social', icon: icons.Share2 ?? icons.Images, title: 'Преглед при споделяне'},
      h('div', {className: 'seo-social'},
        preview.image ? h('img', {src: preview.image, alt: ''}) : h('div', {className: 'seo-social-empty'}, 'Няма изображение'),
        h('div', {className: 'seo-social-text'},
          h('span', {className: 'seo-social-host'}, site),
          h('strong', null, preview.og_title),
          h('span', null, preview.og_description))));
  }

  function Checks({analysis}) {
    return h(Group, {id: 'checks', icon: icons.ListChecks ?? icons.Eye, title: 'Проверки'},
      h('p', {className: 'seo-checks-status'}, 'Оценка: ', h(Badge, {status: analysis.status})),
      h('ul', {className: 'seo-checks'},
        ...analysis.checks.map((check) => h('li', {key: check.code, className: 'seo-check seo-check-' + check.level},
          h('span', {className: 'visually-hidden'}, levelLabels[check.level] + ': '), check.message))));
  }

  /**
   * A group of fields inside the SEO section of an editor: the platform's collapsible section, nested in the panel's
   * own section. The open state is remembered per group, like every other section of the admin.
   */
  function Group({id, title, description, icon, children}) {
    return h(C.CollapsibleSection, {title, icon, storageKey: 'seo-editor-' + id, defaultOpen: true, className: 'seo-group'},
      description && h('p', {className: 'seo-group-hint'}, description),
      h('div', {className: 'seo-fields'}, ...[].concat(children)));
  }

  const blankValues = () => Object.fromEntries(FIELD_KEYS.map((key) => [key, key === 'og_image_media_id' ? null : '']));

  /**
   * The SEO section inside the page, product and category editors. The platform saves the record first and then
   * calls the registered save handler with its ID.
   */
  function EditorPanel({context, registerSave, changed}) {
    const type = context?.type ?? 'page';
    const id = context?.id ?? null;
    const [values, setValues] = React.useState(null);
    const [image, setImage] = React.useState(null);
    const [item, setItem] = React.useState(null);
    const [error, setError] = React.useState('');
    // Fields the user has given their own text; the others show the generated text and are not stored.
    const [custom, setCustom] = React.useState({});
    const latest = React.useRef(null);
    const edited = React.useRef(false);
    latest.current = values;

    React.useEffect(() => {
      let alive = true;
      edited.current = false;
      setError('');
      if (id === null) {
        setValues(blankValues());
        setCustom({});
        setImage(null);
        return undefined;
      }
      request(BASE + '/items/' + type + '/' + id).then((data) => {
        if (!alive) return;
        const meta = data.item.meta;
        setValues(Object.fromEntries(FIELD_KEYS.map((key) => [key, meta[key] ?? (key === 'og_image_media_id' ? null : '')])));
        setCustom(Object.fromEntries(FIELD_KEYS.map((key) => [key, typeof meta[key] === 'string' && meta[key] !== ''])));
        setImage(meta.og_image_media_id ? {id: meta.og_image_media_id, url: '/media-files/' + meta.og_image_media_id + '/original'} : null);
        setItem(data.item);
      }).catch((e) => { if (alive) setError(e.message); });
      return () => { alive = false; };
    }, [type, id]);

    const source = JSON.stringify(sourceFrom(context));
    React.useEffect(() => {
      if (!values) return undefined;
      const abort = new AbortController();
      const timer = setTimeout(() => {
        request(BASE + '/items/' + type + '/' + (id ?? 'new') + '/preview', 'POST', {...values, source: JSON.parse(source)}, abort.signal)
          .then((data) => setItem(data.item)).catch(() => {});
      }, 400);
      return () => { clearTimeout(timer); abort.abort(); };
    }, [values, source, type, id]);

    React.useEffect(() => registerSave?.(async (saved) => {
      // Records without SEO changes are not written, so saving a page never creates empty rows.
      if (!edited.current || !latest.current || !saved?.id) return;
      try {
        const data = await request(BASE + '/items/' + type + '/' + saved.id, 'PUT', latest.current);
        edited.current = false;
        setItem(data.item);
      } catch (e) {
        throw new Error('SEO: ' + e.message);
      }
    }), [registerSave, type]);

    if (error) return h('p', {role: 'alert'}, error);
    if (!values) return h('p', {role: 'status'}, 'Зареждане на SEO…');
    const set = (key) => (value) => {
      edited.current = true;
      changed?.();
      setValues((current) => ({...current, [key]: value}));
    };
    const preview = item?.preview ?? {title: '', description: '', url: '', og_title: '', og_description: '', image: null, robots: null, site_name: ''};
    const autoField = (key, auto, props) => h(AutoTextField, {
      ...props,
      value: values[key],
      auto,
      custom: Boolean(custom[key]),
      onChange: (value) => { setCustom((current) => ({...current, [key]: true})); set(key)(value); },
      onReset: () => { setCustom((current) => ({...current, [key]: false})); set(key)(''); },
    });
    const issues = item ? item.analysis.checks.filter((check) => check.level !== 'info').length : 0;
    return h('div', {className: 'seo-panel'},
      h(Styles),
      item && h('p', {className: 'seo-panel-summary'}, h(Badge, {status: item.analysis.status}),
        issues ? ' ' + issues + (issues === 1 ? ' забележка' : ' забележки') + ' — вижте проверките вдясно.' : ' Всички основни проверки са изпълнени.'),
      h('div', {className: 'seo-edit'},
        h('div', {className: 'seo-groups'},
          h(Group, {id: 'search', icon: icons.Search ?? icons.Eye, title: 'Резултати в търсачките', description: 'Заглавието и описанието, които Google и другите търсачки показват.'},
            autoField('seo_title', preview.title, {label: 'SEO заглавие', maxLength: 190, limits: LIMITS.title, hint: 'Автоматичният текст следва шаблона от Настройки → SEO.'}),
            autoField('meta_description', preview.description, {label: 'Meta описание', maxLength: 320, multiline: true, limits: LIMITS.description, hint: 'Автоматичният текст е началото на съдържанието.'})),
          h(Group, {id: 'keyword', icon: icons.KeyRound ?? icons.Settings, title: 'Ключова дума', description: 'Думата или фразата, по която искате да бъдете намирани. Проверките вдясно следят къде се среща.'},
            h(TextField, {label: 'Основна ключова дума', value: values.focus_keyword, onChange: set('focus_keyword'), maxLength: 190, hint: 'Не се показва на сайта.'})),
          h(Group, {id: 'social', icon: icons.Share2 ?? icons.Images, title: 'Споделяне в социални мрежи', description: 'Как изглежда връзката във Facebook, LinkedIn, X и в съобщения.'},
            autoField('og_title', preview.og_title, {label: 'Заглавие при споделяне', maxLength: 190}),
            autoField('og_description', preview.og_description, {label: 'Описание при споделяне', maxLength: 320, multiline: true}),
            h(ImageField, {label: 'Изображение при споделяне', image, hint: 'Препоръчително 1200 × 630 px. Без избор се използва основното изображение.', onChange: (record) => { setImage(record); set('og_image_media_id')(record ? record.id : null); }})),
          h(Group, {id: 'advanced', icon: icons.SlidersHorizontal ?? icons.Settings, title: 'Разширени настройки', description: 'Нужни са рядко. Оставете празно, ако не сте сигурни.'},
            autoField('canonical_url', preview.url, {label: 'Canonical URL', maxLength: 500, hint: 'Променете само ако съдържанието е копие на друга страница.'}))),
        h('aside', {className: 'seo-side seo-groups'},
          h(SearchPreview, {preview}),
          h(SocialPreview, {preview}),
          item && h(Checks, {analysis: item.analysis}))));
  }

  /** Global settings in Settings → SEO. */
  function SettingsForm() {
    const [values, setValues] = React.useState(null);
    const [image, setImage] = React.useState(null);
    const [busy, setBusy] = React.useState(false);
    const [error, setError] = React.useState('');
    const changed = host.useWorkspaceChanged?.();
    const saved = host.useWorkspaceSaved?.();
    function load(data) {
      setValues(data.settings);
      setImage(data.default_image);
    }
    React.useEffect(() => {
      request(BASE + '/settings').then(load).catch((e) => setError(e.message));
    }, []);
    async function save(e) {
      e.preventDefault();
      setBusy(true);
      setError('');
      try {
        load(await request(BASE + '/settings', 'PUT', values));
        saved?.();
        toast?.('Настройките на SEO са запазени.');
      } catch (err) {
        setError(err.message);
      } finally {
        setBusy(false);
      }
    }
    const set = (key) => (value) => { changed?.(); setValues({...values, [key]: value}); };
    const check = (key, label, description) => h(C.CheckboxField, {key, checked: values[key], label, description, onChange: (e) => set(key)(e.target.checked)});
    if (!values) return h('p', {role: error ? 'alert' : 'status'}, error || 'Зареждане…');
    return h('form', {onSubmit: save, className: 'react-edit-form'},
      error && h('p', {role: 'alert'}, error),
      h('fieldset', {disabled: busy, className: 'seo-settings-fieldset'},
        h(C.CollapsibleSection, {title: 'Заглавия', icon: icons.Settings, storageKey: 'seo-settings-titles', defaultOpen: true},
          h('div', {className: 'seo-fields'},
            h(TextField, {label: 'Шаблон на заглавието', value: values.title_template, onChange: set('title_template'), maxLength: 190, hint: '{title} е заглавието на страницата или продукта, {site} е името на сайта. Пример: {title} | {site}'}),
            h(TextField, {label: 'Заглавие на началната страница', value: values.home_title, onChange: set('home_title'), maxLength: 190, limits: LIMITS.title, hint: 'Празно поле използва шаблона.'}))),
        h(C.CollapsibleSection, {title: 'Споделяне', icon: icons.Images, storageKey: 'seo-settings-social', defaultOpen: true},
          h('div', {className: 'seo-fields'},
            h(ImageField, {label: 'Изображение по подразбиране', image, hint: 'Използва се, когато страница или продукт нямат изображение.', onChange: (record) => { setImage(record); set('default_image_media_id')(record ? record.id : 0); }}),
            h(TextField, {label: 'Профил в X (Twitter)', value: values.twitter_site, onChange: set('twitter_site'), maxLength: 16, placeholder: '@profil'}))),
        h(C.CollapsibleSection, {title: 'Sitemap и robots.txt', icon: icons.Settings, storageKey: 'seo-settings-sitemap', defaultOpen: true},
          h('div', {className: 'seo-fields'},
            h('p', {className: 'react-field-hint'}, 'Публични адреси: ', h('a', {href: '/sitemap.xml', target: '_blank', rel: 'noopener'}, '/sitemap.xml'), ' и ', h('a', {href: '/robots.txt', target: '_blank', rel: 'noopener'}, '/robots.txt'), '.'),
            check('sitemap_pages', 'Страници в sitemap', 'Публикуваните страници без „Не индексирай“.'),
            check('sitemap_products', 'Продукти в sitemap', 'Активните продукти от Flex Commerce.'),
            check('sitemap_categories', 'Категории в sitemap', 'Активните категории от Flex Commerce.'),
            h(TextField, {label: 'Допълнителни правила за robots.txt', value: values.robots_extra, onChange: set('robots_extra'), maxLength: 2000, multiline: true, placeholder: 'Disallow: /private', hint: 'По един ред „Правило: стойност“. Админ панелът, количката, поръчката и профилите винаги са забранени.'}),
            h(TextField, {label: 'Адрес на сайта', value: values.site_url, onChange: set('site_url'), maxLength: 300, placeholder: 'https://example.com', hint: 'За абсолютни адреси в тагове и sitemap. Празно поле използва APP_URL или адреса на заявката.'}))),
        h(C.CollapsibleSection, {title: 'Данни', icon: icons.Settings, storageKey: 'seo-settings-data', defaultOpen: false},
          h('div', {className: 'seo-fields'},
            check('delete_on_uninstall', 'Изтриване на SEO данните при деинсталиране', 'По подразбиране данните се запазват.'))),
        h('div', {className: 'react-form-actions'},
          h(C.LoadingButton, {type: 'submit', loading: busy, disabled: busy, icon: h(icons.Save)}, 'Запази настройките'))));
  }

  /** The site check in Settings → SEO: every page, product and category with its rating. */
  function SiteCheck({navigate}) {
    const [type, setType] = React.useState('all');
    const [status, setStatus] = React.useState('all');
    const [search, setSearch] = React.useState('');
    const [page, setPage] = React.useState(1);
    const [data, setData] = React.useState(null);
    const [error, setError] = React.useState('');
    React.useEffect(() => {
      let alive = true;
      const abort = new AbortController();
      setData(null);
      const timer = setTimeout(() => {
        request(BASE + '/items?type=' + type + '&status=' + status + '&q=' + encodeURIComponent(search) + '&page=' + page, 'GET', undefined, abort.signal)
          .then((d) => { if (alive) { setData(d); setError(''); } })
          .catch((e) => { if (alive) setError(e.message); });
      }, search ? 300 : 0);
      return () => { alive = false; clearTimeout(timer); abort.abort(); };
    }, [type, status, search, page]);
    const open = (row) => navigate ? navigate(editorPath(row)) : undefined;
    const columns = [
      {key: 'name', label: 'Име', sortable: true, render: (r) => h('div', null,
        navigate ? h('a', {className: 'react-page-link', href: '#seo-' + r.type + '-' + r.id, onClick: (e) => { e.preventDefault(); open(r); }}, r.name || '(без заглавие)') : (r.name || '(без заглавие)'),
        h('small', {style: {display: 'block'}}, r.path))},
      {key: 'type', label: 'Тип', render: (r) => typeLabels[r.type] + (r.status && !['published', 'active'].includes(r.status) ? ' · чернова' : '')},
      {key: 'title', label: 'Заглавие в търсачките', render: (r) => h('span', null, r.preview.title, h('small', {style: {display: 'block'}}, r.preview.title.length + ' символа'))},
      {key: 'status', label: 'Оценка', sortable: true, sortValue: (r) => ({error: 0, warning: 1, good: 2})[r.analysis.status], render: (r) => h(Badge, {status: r.analysis.status})},
      {key: 'issues', label: 'Забележки', render: (r) => r.analysis.checks.filter((c) => c.level !== 'info').length || '—'},
      {key: 'actions', label: 'Действия', render: (r) => navigate ? h(C.TableActionsMenu, null, h(C.DropdownOption, {onClick: () => open(r)}, 'Редактирай SEO')) : null},
    ];
    return h(C.CollapsibleSection, {title: 'Проверка на сайта', icon: icons.Eye, storageKey: 'seo-settings-site-check', defaultOpen: true},
      data && h('p', {className: 'seo-totals'}, ...Object.entries(statusLabels).map(([key, label]) => h('button', {
        key, type: 'button', className: 'seo-total seo-total-' + key, 'aria-pressed': status === key,
        onClick: () => { setStatus(status === key ? 'all' : key); setPage(1); },
      }, h('strong', null, data.totals[key]), ' ', label))),
      error && h('p', {role: 'alert'}, error),
      h(C.DataTable, {
        data: data?.records ?? [],
        columns,
        rowKey: (r) => r.type + '-' + r.id,
        loading: !data,
        emptyMessage: 'Няма намерени страници или продукти.',
        pageSize: 25,
        filterStorageKey: 'flex-seo-items',
        toolbar: h('div', {className: 'seo-filters'},
          h(Select, {label: 'Тип', value: type, options: {all: 'Всички', page: 'Страници', product: 'Продукти', category: 'Категории'}, onChange: (v) => { setType(v); setPage(1); }}),
          h(Select, {label: 'Оценка', value: status, options: {all: 'Всички', ...statusLabels}, onChange: (v) => { setStatus(v); setPage(1); }}),
          h('label', {className: 'react-form-field'}, 'Търсене',
            h(C.Input, {type: 'search', value: search, maxLength: 200, placeholder: 'Име, заглавие или адрес…', onChange: (e) => { setSearch(e.target.value); setPage(1); }}))),
      }),
      data && data.pages > 1 && h('div', {className: 'seo-actions'},
        h(C.LoadingButton, {type: 'button', disabled: page <= 1, onClick: () => setPage(page - 1)}, 'Предишни'),
        h('span', null, page + ' / ' + data.pages),
        h(C.LoadingButton, {type: 'button', disabled: page >= data.pages, onClick: () => setPage(page + 1)}, 'Следващи')));
  }

  function SettingsTab({navigate}) {
    return h('div', {className: 'seo-settings-tab'}, h(Styles), h(SettingsForm), h(SiteCheck, {navigate}));
  }

  const ui = {Badge, EditorPanel, SettingsTab, SettingsForm, SiteCheck, SearchPreview, SocialPreview, Checks, TextField, ImageField};
  cache.set(host, ui);
  return ui;
}
