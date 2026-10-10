export const LIMITS = {title: [30, 60], description: [70, 160]};

export function lengthState(length, [min, max]) {
  if (length === 0) return 'empty';
  return length < min ? 'short' : length > max ? 'long' : 'good';
}

export function createPage(host) {
  const {React, components: C, request, toast, icons} = host;
  const h = React.createElement, base = '/admin/plugins/flex/seo';
  const statusLabels = {good: 'Добре', warning: 'Предупреждения', error: 'Проблеми'};
  const typeLabels = {page: 'Страница', product: 'Продукт'};
  const badgeClass = {good: 'active', warning: 'draft', error: 'inactive'};
  const levelLabels = {error: 'Проблем', warning: 'Предупреждение', info: 'Информация'};

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
    const state = lengthState(length, limits);
    return h('small', {className: 'seo-counter seo-counter-' + state, 'aria-live': 'polite'},
      length + ' символа · препоръчително ' + limits[0] + '–' + limits[1]);
  }

  function TextField({label, hint, value, onChange, maxLength, multiline, limits, placeholder}) {
    const Control = multiline ? C.Textarea : C.Input;
    return h('label', {className: 'react-form-field'}, label,
      h(Control, {value, maxLength, placeholder, onChange: (e) => onChange(e.target.value)}),
      limits && h(Counter, {value: value || placeholder || '', limits}),
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
    return h('section', {className: 'seo-preview', 'aria-label': 'Преглед в Google'},
      h('h3', null, 'Преглед в търсачките'),
      h('div', {className: 'seo-serp'},
        h('span', {className: 'seo-serp-site'}, preview.site_name),
        h('span', {className: 'seo-serp-url'}, url),
        h('span', {className: 'seo-serp-title'}, preview.title),
        h('span', {className: 'seo-serp-description'}, preview.description || 'Няма описание. Търсачката ще избере текст от страницата.')),
      preview.robots && h('p', {className: 'seo-note'}, 'Скрита от търсачките (' + preview.robots + ').'));
  }

  function SocialPreview({preview}) {
    let host = '';
    try { host = new URL(preview.url).host; } catch { /* no host */ }
    return h('section', {className: 'seo-preview', 'aria-label': 'Преглед при споделяне'},
      h('h3', null, 'Преглед при споделяне'),
      h('div', {className: 'seo-social'},
        preview.image ? h('img', {src: preview.image, alt: ''}) : h('div', {className: 'seo-social-empty'}, 'Няма изображение'),
        h('div', {className: 'seo-social-text'},
          h('span', {className: 'seo-social-host'}, host),
          h('strong', null, preview.og_title),
          h('span', null, preview.og_description))));
  }

  function Checks({analysis}) {
    return h('section', {className: 'seo-preview', 'aria-label': 'Проверки'},
      h('h3', null, 'Проверки ', h(Badge, {status: analysis.status})),
      h('ul', {className: 'seo-checks'},
        ...analysis.checks.map((check) => h('li', {key: check.code, className: 'seo-check seo-check-' + check.level},
          h('span', {className: 'visually-hidden'}, levelLabels[check.level] + ': '), check.message))));
  }

  const FIELD_KEYS = ['seo_title', 'meta_description', 'focus_keyword', 'og_title', 'og_description', 'canonical_url', 'og_image_media_id'];

  function EditView({type, id, onBack, navigate}) {
    const [item, setItem] = React.useState(null);
    const [values, setValues] = React.useState(null);
    const [image, setImage] = React.useState(null);
    const [preview, setPreview] = React.useState(null);
    const [busy, setBusy] = React.useState(false);
    const [error, setError] = React.useState('');
    const url = base + '/items/' + type + '/' + id;
    function load(data) {
      setItem(data.item);
      setPreview(data.item);
      setValues(Object.fromEntries(FIELD_KEYS.map((key) => [key, data.item.meta[key] ?? (key === 'og_image_media_id' ? null : '')])));
    }
    React.useEffect(() => {
      request(url).then((data) => {
        load(data);
        const media = data.item.meta.og_image_media_id;
        setImage(media ? {id: media, url: '/media-files/' + media + '/original'} : null);
      }).catch((e) => setError(e.message));
    }, [url]);
    React.useEffect(() => {
      if (!values) return undefined;
      const abort = new AbortController();
      const timer = setTimeout(() => {
        request(url + '/preview', 'POST', values, abort.signal).then((data) => setPreview(data.item)).catch(() => {});
      }, 350);
      return () => { clearTimeout(timer); abort.abort(); };
    }, [values]);
    async function save(e) {
      e.preventDefault();
      setBusy(true);
      setError('');
      try {
        load(await request(url, 'PUT', values));
        toast?.('SEO настройките са запазени.');
      } catch (err) {
        setError(err.message);
      } finally {
        setBusy(false);
      }
    }
    const set = (key) => (value) => setValues({...values, [key]: value});
    const name = item?.name ?? '';
    return h(React.Fragment, null,
      h('div', {className: 'react-page-heading'},
        h('div', null,
          h('h1', null, name ? 'SEO: ' + name : 'SEO'),
          h(C.Breadcrumbs, {items: [{label: 'SEO', onClick: onBack}, {label: name || '…'}], onHomeClick: () => navigate?.('/')}))),
      error && h('p', {role: 'alert'}, error),
      !values || !preview
        ? h('p', {role: 'status'}, error ? 'Данните не могат да бъдат заредени.' : 'Зареждане…')
        : h('div', {className: 'seo-edit'},
          h('form', {onSubmit: save, className: 'react-edit-form'},
            h('fieldset', {disabled: busy},
              h(C.CollapsibleSection, {title: 'Търсачки', icon: icons.Eye, storageKey: 'seo-edit-search', defaultOpen: true},
                h('div', {className: 'seo-fields'},
                  h(TextField, {label: 'SEO заглавие', value: values.seo_title, onChange: set('seo_title'), maxLength: 190, limits: LIMITS.title, placeholder: preview.preview.title, hint: 'Празно поле използва шаблона от настройките.'}),
                  h(TextField, {label: 'Meta описание', value: values.meta_description, onChange: set('meta_description'), maxLength: 320, multiline: true, limits: LIMITS.description, placeholder: preview.preview.description, hint: 'Празно поле използва началото на съдържанието.'}),
                  h(TextField, {label: 'Основна ключова дума', value: values.focus_keyword, onChange: set('focus_keyword'), maxLength: 190, hint: 'Използва се само за проверките вдясно.'}))),
              h(C.CollapsibleSection, {title: 'Социални мрежи', icon: icons.Images, storageKey: 'seo-edit-social', defaultOpen: true},
                h('div', {className: 'seo-fields'},
                  h(TextField, {label: 'Заглавие при споделяне', value: values.og_title, onChange: set('og_title'), maxLength: 190, placeholder: preview.preview.title}),
                  h(TextField, {label: 'Описание при споделяне', value: values.og_description, onChange: set('og_description'), maxLength: 320, multiline: true, placeholder: preview.preview.description}),
                  h(ImageField, {label: 'Изображение при споделяне', image, hint: 'Препоръчително 1200 × 630 px. Без избор се използва основното изображение.', onChange: (record) => { setImage(record); setValues({...values, og_image_media_id: record ? record.id : null}); }}))),
              h(C.CollapsibleSection, {title: 'Разширени', icon: icons.Settings, storageKey: 'seo-edit-advanced', defaultOpen: false},
                h('div', {className: 'seo-fields'},
                  h(TextField, {label: 'Canonical URL', value: values.canonical_url, onChange: set('canonical_url'), maxLength: 500, placeholder: preview.preview.url, hint: 'Попълнете само ако съдържанието е копие на друга страница.'}))),
              h('div', {className: 'react-form-actions'},
                h(C.LoadingButton, {type: 'submit', loading: busy, disabled: busy, icon: h(icons.Save)}, 'Запази')))),
          h('aside', {className: 'seo-side'},
            h(SearchPreview, {preview: preview.preview}),
            h(SocialPreview, {preview: preview.preview}),
            h(Checks, {analysis: preview.analysis}))));
  }

  function SettingsView({onBack, navigate}) {
    const [values, setValues] = React.useState(null);
    const [image, setImage] = React.useState(null);
    const [busy, setBusy] = React.useState(false);
    const [error, setError] = React.useState('');
    function load(data) {
      setValues(data.settings);
      setImage(data.default_image);
    }
    React.useEffect(() => {
      request(base + '/settings').then(load).catch((e) => setError(e.message));
    }, []);
    async function save(e) {
      e.preventDefault();
      setBusy(true);
      setError('');
      try {
        load(await request(base + '/settings', 'PUT', values));
        toast?.('Настройките са запазени.');
      } catch (err) {
        setError(err.message);
      } finally {
        setBusy(false);
      }
    }
    const set = (key) => (value) => setValues({...values, [key]: value});
    const check = (key, label, description) => h(C.CheckboxField, {key, checked: values[key], label, description, onChange: (e) => set(key)(e.target.checked)});
    return h(React.Fragment, null,
      h('div', {className: 'react-page-heading'},
        h('div', null,
          h('h1', null, 'Настройки на SEO'),
          h(C.Breadcrumbs, {items: [{label: 'SEO', onClick: onBack}, {label: 'Настройки'}], onHomeClick: () => navigate?.('/')}))),
      error && h('p', {role: 'alert'}, error),
      !values
        ? h('p', {role: 'status'}, error ? 'Настройките не могат да бъдат заредени.' : 'Зареждане…')
        : h('form', {onSubmit: save, className: 'react-edit-form'},
          h('fieldset', {disabled: busy},
            h(C.CollapsibleSection, {title: 'Заглавия', icon: icons.Settings, storageKey: 'seo-settings-titles', defaultOpen: true},
              h('div', {className: 'seo-fields'},
                h(TextField, {label: 'Шаблон на заглавието', value: values.title_template, onChange: set('title_template'), maxLength: 190, hint: '{title} е заглавието на страницата или продукта, {site} е името на сайта. Пример: {title} | {site}'}),
                h(TextField, {label: 'Заглавие на началната страница', value: values.home_title, onChange: set('home_title'), maxLength: 190, limits: LIMITS.title, hint: 'Празно поле използва шаблона.'}))),
            h(C.CollapsibleSection, {title: 'Споделяне', icon: icons.Images, storageKey: 'seo-settings-social', defaultOpen: true},
              h('div', {className: 'seo-fields'},
                h(ImageField, {label: 'Изображение по подразбиране', image, hint: 'Използва се, когато страница или продукт нямат изображение.', onChange: (record) => { setImage(record); setValues({...values, default_image_media_id: record ? record.id : 0}); }}),
                h(TextField, {label: 'Профил в X (Twitter)', value: values.twitter_site, onChange: set('twitter_site'), maxLength: 16, placeholder: '@profil'}))),
            h(C.CollapsibleSection, {title: 'Sitemap и robots.txt', icon: icons.Settings, storageKey: 'seo-settings-sitemap', defaultOpen: true},
              h('div', {className: 'seo-fields'},
                h('p', {className: 'react-field-hint'}, 'Публични адреси: ', h('a', {href: '/sitemap.xml', target: '_blank', rel: 'noopener'}, '/sitemap.xml'), ' и ', h('a', {href: '/robots.txt', target: '_blank', rel: 'noopener'}, '/robots.txt'), '.'),
                check('sitemap_pages', 'Страници в sitemap', 'Публикуваните страници без „Не индексирай“ и с включено показване в sitemap.'),
                check('sitemap_products', 'Продукти в sitemap', 'Активните продукти от Flex Commerce.'),
                check('sitemap_categories', 'Категории в sitemap', 'Активните категории от Flex Commerce.'),
                h(TextField, {label: 'Допълнителни правила за robots.txt', value: values.robots_extra, onChange: set('robots_extra'), maxLength: 2000, multiline: true, placeholder: 'Disallow: /private', hint: 'По един ред „Правило: стойност“. Админ панелът, количката, поръчката и профилите винаги са забранени.'}),
                h(TextField, {label: 'Адрес на сайта', value: values.site_url, onChange: set('site_url'), maxLength: 300, placeholder: 'https://example.com', hint: 'За абсолютни адреси в тагове и sitemap. Празно поле използва APP_URL или адреса на заявката.'}))),
            h(C.CollapsibleSection, {title: 'Данни', icon: icons.Settings, storageKey: 'seo-settings-data', defaultOpen: false},
              h('div', {className: 'seo-fields'},
                check('delete_on_uninstall', 'Изтриване на SEO данните при деинсталиране', 'По подразбиране данните се запазват.'))),
            h('div', {className: 'react-form-actions'},
              h(C.LoadingButton, {type: 'submit', loading: busy, disabled: busy, icon: h(icons.Save)}, 'Запази настройките')))));
  }

  function ListView({navigate, onEdit, onSettings}) {
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
        request(base + '/items?type=' + type + '&status=' + status + '&q=' + encodeURIComponent(search) + '&page=' + page, 'GET', undefined, abort.signal)
          .then((d) => { if (alive) { setData(d); setError(''); } })
          .catch((e) => { if (alive) setError(e.message); });
      }, search ? 300 : 0);
      return () => { alive = false; clearTimeout(timer); abort.abort(); };
    }, [type, status, search, page]);
    const columns = [
      {key: 'name', label: 'Име', sortable: true, render: (r) => h('div', null,
        h('a', {className: 'react-page-link', href: '#seo-' + r.type + '-' + r.id, onClick: (e) => { e.preventDefault(); onEdit(r); }}, r.name || '(без заглавие)'),
        h('small', {style: {display: 'block'}}, r.path))},
      {key: 'type', label: 'Тип', render: (r) => typeLabels[r.type] + (r.status && !['published', 'active'].includes(r.status) ? ' · чернова' : '')},
      {key: 'title', label: 'Заглавие в търсачките', render: (r) => h('span', null, r.preview.title, h('small', {style: {display: 'block'}}, r.preview.title.length + ' символа'))},
      {key: 'status', label: 'Оценка', sortable: true, sortValue: (r) => ({error: 0, warning: 1, good: 2})[r.analysis.status], render: (r) => h(Badge, {status: r.analysis.status})},
      {key: 'issues', label: 'Забележки', render: (r) => r.analysis.checks.filter((c) => c.level !== 'info').length || '—'},
      {key: 'actions', label: 'Действия', render: (r) => h(C.TableActionsMenu, null, h(C.DropdownOption, {onClick: () => onEdit(r)}, 'Редактирай SEO'))},
    ];
    return h('div', null,
      h('div', {className: 'react-page-heading'},
        h('div', null,
          h('h1', null, 'SEO'),
          h(C.Breadcrumbs, {items: [{label: 'SEO'}], onHomeClick: () => navigate?.('/')})),
        h(C.LoadingButton, {type: 'button', icon: h(icons.Settings), onClick: onSettings}, 'Настройки')),
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
          h(Select, {label: 'Тип', value: type, options: {all: 'Всички', page: 'Страници', product: 'Продукти'}, onChange: (v) => { setType(v); setPage(1); }}),
          h(Select, {label: 'Оценка', value: status, options: {all: 'Всички', ...statusLabels}, onChange: (v) => { setStatus(v); setPage(1); }}),
          h('label', {className: 'react-form-field'}, 'Търсене',
            h(C.Input, {type: 'search', value: search, maxLength: 200, placeholder: 'Име, заглавие или адрес…', onChange: (e) => { setSearch(e.target.value); setPage(1); }}))),
      }),
      data && data.pages > 1 && h('div', {className: 'seo-actions'},
        h(C.LoadingButton, {disabled: page <= 1, onClick: () => setPage(page - 1)}, 'Предишни'),
        h('span', null, page + ' / ' + data.pages),
        h(C.LoadingButton, {disabled: page >= data.pages, onClick: () => setPage(page + 1)}, 'Следващи')));
  }

  return function SeoPage({navigate}) {
    const [view, setView] = React.useState({name: 'list'});
    const back = () => setView({name: 'list'});
    return h(React.Fragment, null,
      h('link', {rel: 'stylesheet', href: '/extensions/flex/seo/assets/assets/admin.css'}),
      view.name === 'settings'
        ? h(SettingsView, {navigate, onBack: back})
        : view.name === 'edit'
          ? h(EditView, {key: view.type + view.id, type: view.type, id: view.id, navigate, onBack: back})
          : h(ListView, {navigate, onSettings: () => setView({name: 'settings'}), onEdit: (row) => setView({name: 'edit', type: row.type, id: row.id})}));
  };
}
