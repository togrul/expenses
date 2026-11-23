/**
 * Native-app feel for the installed iPhone app: instant navigation feedback,
 * skeleton screens, touch prefetching, bottom-sheet pickers and swipe-to-dismiss.
 */

const isPhone = () => window.matchMedia('(max-width: 1023px)').matches;

/**
 * Run once Livewire is available (its script may start before or after this module).
 */
const whenLivewire = (callback) => (window.Livewire ? callback() : document.addEventListener('livewire:init', callback, { once: true }));

/**
 * Slide a sheet out, then close the dialog.
 */
export function closeSheet(dialog) {
    if (!dialog?.open) {
        return Promise.resolve();
    }

    return new Promise((resolve) => {
        dialog.classList.add('is-closing');

        setTimeout(() => {
            dialog.classList.remove('is-closing');
            dialog.style.transform = '';
            dialog.style.transition = '';
            dialog.close();
            resolve();
        }, 180);
    });
}

/**
 * Page changes: the tapped tab lights up at once, links start loading on touchstart
 * (not on the late synthetic mousedown iOS sends), a skeleton replaces the page when
 * the server is slow, and a thin gold bar shows any in-page Livewire request.
 */
export function setUpNavigationFeedback() {
    const root = document.documentElement;
    let skeletonTimer = null;

    document.addEventListener('livewire:navigate', (event) => {
        if (event.detail?.history) {
            return;
        }

        clearTimeout(skeletonTimer);
        skeletonTimer = setTimeout(() => root.classList.add('is-navigating'), 120);
    });

    const finish = () => {
        clearTimeout(skeletonTimer);
        root.classList.remove('is-navigating', 'is-busy');
    };

    const syncTabs = () => {
        const path = window.location.pathname;
        const tabs = [...document.querySelectorAll('[data-tab-link]')];
        const current = tabs.find((tab) => {
            const tabPath = new URL(tab.href).pathname;

            return tabPath === path || (tabPath !== '/' && path.startsWith(tabPath + '/'));
        });

        if (current) {
            tabs.forEach((tab) => (tab === current ? tab.setAttribute('aria-current', 'page') : tab.removeAttribute('aria-current')));
        }
    };

    document.addEventListener('livewire:navigating', (event) => event.detail?.onSwap?.(finish));
    document.addEventListener('livewire:navigated', () => {
        finish();
        syncTabs();
    });

    document.addEventListener(
        'click',
        (event) => {
            const tab = event.target.closest('[data-tab-link]');

            if (!tab || event.metaKey || event.ctrlKey) {
                return;
            }

            tab.parentElement.querySelectorAll('[data-tab-link]').forEach((link) => link.removeAttribute('aria-current'));
            tab.setAttribute('aria-current', 'page');
        },
        true,
    );

    document.addEventListener(
        'touchstart',
        (event) => {
            const link = event.target.closest?.('a[href]');

            if (!link || !(link.hasAttribute('wire:navigate.hover') || link.hasAttribute('x-navigate.hover'))) {
                return;
            }

            link.dispatchEvent(new MouseEvent('mouseenter'));
            link.addEventListener('touchmove', () => link.dispatchEvent(new MouseEvent('mouseleave')), { once: true, passive: true });
        },
        { passive: true },
    );

    whenLivewire(() => {
        let pending = 0;
        let busyTimer = null;

        const done = () => {
            pending = Math.max(0, pending - 1);

            if (pending === 0) {
                clearTimeout(busyTimer);
                root.classList.remove('is-busy');
            }
        };

        window.Livewire.hook('request', ({ respond, fail }) => {
            pending++;
            clearTimeout(busyTimer);
            busyTimer = setTimeout(() => pending > 0 && root.classList.add('is-busy'), 250);
            respond(done);
            fail(done);
        });
    });
}

/**
 * Drag a bottom sheet (dialog.sheet-on-mobile or dialog.picker-sheet) down to dismiss it.
 */
export function setUpSheetGestures() {
    let drag = null;

    const settle = (dialog) => {
        dialog.style.transition = 'transform 0.28s cubic-bezier(0.22, 1, 0.36, 1)';
        dialog.style.transform = '';
        setTimeout(() => (dialog.style.transition = ''), 300);
    };

    document.addEventListener(
        'touchstart',
        (event) => {
            const dialog = event.target.closest?.('dialog.sheet-on-mobile[open], dialog.picker-sheet[open]');

            if (!dialog || !isPhone() || event.touches.length !== 1 || event.target.closest('input, textarea, select, [data-no-drag]')) {
                drag = null;

                return;
            }

            const scroller = event.target.closest('[data-sheet-scroll]') ?? dialog;

            drag = scroller.scrollTop > 0 ? null : { dialog, scroller, startY: event.touches[0].clientY, startedAt: performance.now(), distance: 0, active: false };
        },
        { passive: true },
    );

    document.addEventListener(
        'touchmove',
        (event) => {
            if (!drag) {
                return;
            }

            const distance = event.touches[0].clientY - drag.startY;

            if (!drag.active) {
                if (distance < -4 || drag.scroller.scrollTop > 0) {
                    drag = null;

                    return;
                }

                if (distance < 8) {
                    return;
                }

                drag.active = true;
                drag.dialog.style.transition = 'none';
            }

            event.preventDefault();
            drag.distance = Math.max(0, distance - 8);
            drag.dialog.style.transform = `translateY(${drag.distance}px)`;
        },
        { passive: false },
    );

    document.addEventListener('touchend', () => {
        if (!drag?.active) {
            drag = null;

            return;
        }

        const { dialog, distance, startedAt } = drag;
        const velocity = distance / Math.max(1, performance.now() - startedAt);
        drag = null;

        if (distance > 110 || (distance > 30 && velocity > 0.6)) {
            dialog.style.transition = 'transform 0.2s ease-in';
            dialog.style.transform = 'translateY(100%)';
            setTimeout(() => {
                dialog.close();
                dialog.style.transform = '';
                dialog.style.transition = '';
            }, 200);
        } else {
            settle(dialog);
        }
    });
}

/**
 * <x-picker>: a select replacement that opens a bottom sheet on phones
 * (a small centred list on desktop). Bound with wire:model through x-modelable.
 */
export const picker = (placeholder = '') => ({
    value: null,
    query: '',
    version: 0,
    placeholder,
    observer: null,

    init() {
        this.observer = new MutationObserver(() => this.version++);
        this.observer.observe(this.list(), { childList: true, subtree: true, attributes: true, attributeFilter: ['data-value', 'data-label'] });
    },

    destroy() {
        this.observer?.disconnect();
    },

    list() {
        return this.$root.querySelector('[role="listbox"]');
    },

    key(value) {
        return value === null || value === undefined ? '' : String(value);
    },

    get selected() {
        this.version;
        const key = this.key(this.value);
        const option = [...this.list().querySelectorAll('[data-value]')].find((element) => element.dataset.value === key);

        return option ? { label: option.dataset.label, color: option.dataset.color } : null;
    },

    isSelected(value) {
        return this.key(value) === this.key(this.value);
    },

    matches(label) {
        return !this.query || label.includes(this.query.toLocaleLowerCase('az'));
    },

    get hasMatches() {
        return !this.query || [...this.list().querySelectorAll('[data-label]')].some((element) => this.matches(element.dataset.label.toLocaleLowerCase('az')));
    },

    open() {
        const sheet = this.$refs.sheet;
        sheet.showModal();
        requestAnimationFrame(() => sheet.querySelector('[aria-selected="true"]')?.scrollIntoView({ block: 'center' }));
    },

    choose(value) {
        this.value = value;
        this.close();
    },

    close() {
        closeSheet(this.$refs.sheet);
    },
});


/**
 * iOS gives an installed web app no back gesture: drag from the left edge to go back.
 * Only offered when this app has a page to go back to.
 */
export function setUpEdgeSwipeBack() {
    if (!('ontouchstart' in window)) {
        return;
    }

    const depthKey = 'maliyye:nav-depth';
    const depth = () => Number(sessionStorage.getItem(depthKey) || 0);
    const setDepth = (value) => sessionStorage.setItem(depthKey, String(Math.max(0, value)));

    if (!performance.getEntriesByType('navigation')[0] || performance.getEntriesByType('navigation')[0].type !== 'back_forward') {
        setDepth(0);
    }

    document.addEventListener('livewire:navigate', (event) => {
        const url = event.detail?.url?.href ?? String(event.detail?.url ?? '');

        if (event.detail?.history) {
            setDepth(depth() - 1);
        } else if (url && url !== window.location.href) {
            setDepth(depth() + 1);
        }
    });

    const indicator = document.createElement('div');
    indicator.className = 'edge-back';
    indicator.setAttribute('aria-hidden', 'true');
    indicator.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></svg>';

    let swipe = null;
    const page = () => document.querySelector('[data-flux-main]') ?? document.querySelector('main');

    document.addEventListener(
        'touchstart',
        (event) => {
            const touch = event.touches[0];

            if (event.touches.length !== 1 || touch.clientX > 22 || depth() < 1 || document.querySelector('dialog[open]') || !page()) {
                swipe = null;

                return;
            }

            swipe = { x: touch.clientX, y: touch.clientY, at: performance.now(), dx: 0, active: false, el: page() };
        },
        { passive: true },
    );

    document.addEventListener(
        'touchmove',
        (event) => {
            if (!swipe) {
                return;
            }

            const touch = event.touches[0];
            const dx = touch.clientX - swipe.x;
            const dy = touch.clientY - swipe.y;

            if (!swipe.active) {
                if (Math.abs(dy) > 10 && Math.abs(dy) > dx) {
                    swipe = null;

                    return;
                }

                if (dx < 10) {
                    return;
                }

                swipe.active = true;
                swipe.el.style.transition = 'none';
                swipe.el.classList.add('is-edge-dragging');

                if (!indicator.isConnected) {
                    document.body.appendChild(indicator);
                }
            }

            event.preventDefault();
            swipe.dx = Math.max(0, dx);
            swipe.el.style.transform = `translateX(${swipe.dx}px)`;
            indicator.style.opacity = String(Math.min(swipe.dx / 90, 1));
            indicator.style.transform = `translate(${Math.min(swipe.dx / 2, 40)}px, -50%)`;
            indicator.classList.toggle('is-ready', swipe.dx > 90);
        },
        { passive: false },
    );

    document.addEventListener('touchend', () => {
        if (!swipe?.active) {
            swipe = null;

            return;
        }

        const { el, dx, at } = swipe;
        const velocity = dx / Math.max(1, performance.now() - at);
        swipe = null;
        indicator.style.opacity = '0';

        const reset = () => {
            el.style.transition = '';
            el.style.transform = '';
            el.classList.remove('is-edge-dragging');
        };

        if (dx > 90 || (dx > 40 && velocity > 0.5)) {
            el.style.transition = 'transform 0.2s ease-out';
            el.style.transform = 'translateX(100vw)';
            setTimeout(() => window.history.back(), 120);
            setTimeout(reset, 1500);
        } else {
            el.style.transition = 'transform 0.25s cubic-bezier(0.22, 1, 0.36, 1)';
            el.style.transform = '';
            setTimeout(reset, 260);
        }
    });
}

/**
 * iOS "large title": once the page heading scrolls under the header, it appears small in the header.
 */
export function setUpCompactTitle() {
    let observer = null;

    const apply = () => {
        observer?.disconnect();

        const header = document.querySelector('[data-mobile-header]');
        const slot = header?.querySelector('[data-compact-title]');
        const heading = document.querySelector('[data-page] h1');

        if (!header || !slot) {
            return;
        }

        header.classList.remove('is-compact');

        if (!heading) {
            return;
        }

        slot.textContent = heading.textContent.trim();
        observer = new IntersectionObserver(([entry]) => header.classList.toggle('is-compact', !entry.isIntersecting && entry.boundingClientRect.top < 80), {
            rootMargin: `-${header.offsetHeight}px 0px 0px 0px`,
        });
        observer.observe(heading);
    };

    document.addEventListener('livewire:navigated', apply);
}

/**
 * Follow the iPhone's light/dark setting live (unless a theme was picked in Settings → Görünüş),
 * including after Livewire swaps the page, and keep the status bar colour in step.
 */
export function setUpSystemTheme() {
    const media = window.matchMedia('(prefers-color-scheme: dark)');

    const apply = () => {
        const choice = window.localStorage.getItem('flux.appearance') || 'system';
        const dark = choice === 'dark' || (choice === 'system' && media.matches);

        document.documentElement.classList.toggle('dark', dark);
        document.querySelectorAll('meta[name="theme-color"]').forEach((meta) => (meta.content = dark ? '#09090b' : '#f5f4f0'));
    };

    media.addEventListener('change', apply);
    document.addEventListener('livewire:navigating', (event) => event.detail?.onSwap?.(apply));
    document.addEventListener('livewire:navigated', apply);
    window.addEventListener('storage', apply);
    apply();
}

/**
 * Swipe a list row left to reveal its actions (Redaktə / Sil), like iOS Mail.
 */
export const swipeRow = () => ({
    offset: 0,
    start: null,
    base: 0,
    axis: null,
    onOtherOpened: null,

    init() {
        this.onOtherOpened = (event) => event.detail !== this.$el && this.close();
        window.addEventListener('swipe-row-opened', this.onOtherOpened);
    },

    destroy() {
        window.removeEventListener('swipe-row-opened', this.onOtherOpened);
    },

    get width() {
        return this.$refs.actions?.offsetWidth ?? 0;
    },

    touchStart(event) {
        const touch = event.touches[0];

        if (touch.clientX < 28 || !this.width) {
            return;
        }

        this.start = { x: touch.clientX, y: touch.clientY };
        this.base = this.offset;
        this.axis = null;
        this.$refs.content.style.transition = 'none';
    },

    touchMove(event) {
        if (!this.start) {
            return;
        }

        const touch = event.touches[0];
        const dx = touch.clientX - this.start.x;
        const dy = touch.clientY - this.start.y;

        if (this.axis === null) {
            if (Math.abs(dx) < 8 && Math.abs(dy) < 8) {
                return;
            }

            this.axis = Math.abs(dx) > Math.abs(dy) ? 'x' : 'y';
        }

        if (this.axis !== 'x') {
            return;
        }

        event.preventDefault();
        let next = Math.min(0, this.base + dx);

        if (next < -this.width) {
            next = -this.width + (next + this.width) * 0.3;
        }

        this.offset = next;
    },

    touchEnd() {
        if (!this.start) {
            return;
        }

        this.start = null;
        this.$refs.content.style.transition = '';

        if (this.axis !== 'x') {
            return;
        }

        this.offset = this.offset < -this.width * 0.4 ? -this.width : 0;

        if (this.offset !== 0) {
            window.dispatchEvent(new CustomEvent('swipe-row-opened', { detail: this.$el }));
        }
    },

    close() {
        this.offset = 0;
    },

    guardClick(event) {
        if (this.offset !== 0 && !event.target.closest('[data-row-actions]')) {
            event.preventDefault();
            event.stopPropagation();
            this.close();
        }
    },
});

/**
 * Amount field with a calculator keypad on phones: "12+8,5" becomes 20,50.
 * Bound with wire:model / x-model through x-modelable; the bound value is always the result.
 */
export const amountPad = () => ({
    value: '',
    expr: '',
    padOpen: false,
    touch: window.matchMedia('(pointer: coarse)').matches,

    init() {
        this.expr = this.value ?? '';
        this.$watch('value', (value) => {
            if ((value ?? '') !== this.result()) {
                this.expr = value ?? '';
            }
        });
    },

    get hasOperator() {
        return /[+\-]/.test(this.expr.replace(/^-/, '').replace(/[+\-]$/, ''));
    },

    get display() {
        return this.expr.replace(/([+\-])/g, ' $1 ').replace(/-/g, '−').trim();
    },

    evaluate() {
        const parts = this.expr.replace(/\s/g, '').match(/[+-]?[^+-]+/g);

        if (!parts) {
            return null;
        }

        let sum = 0;

        for (const part of parts) {
            const number = Number(part.replace(',', '.'));

            if (!Number.isFinite(number)) {
                return null;
            }

            sum += number;
        }

        return Math.round(sum * 100) / 100;
    },

    format(number) {
        return (Number.isInteger(number) ? String(number) : number.toFixed(2)).replace('.', ',');
    },

    result() {
        if (!this.expr) {
            return '';
        }

        if (!/[+-]/.test(this.expr.replace(/^-/, ''))) {
            return this.expr;
        }

        const total = this.evaluate();

        return total === null ? this.expr : this.format(total);
    },

    get preview() {
        return this.hasOperator ? this.result() : '';
    },

    sync() {
        this.value = this.result();
    },

    typed(event) {
        this.expr = event.target.value.replace(/[^\d,.+\-\s]/g, '').replace(/−/g, '-');
        this.sync();
    },

    press(key) {
        const segment = this.expr.split(/[+-]/).pop() ?? '';
        const lastIsOperator = /[+-]$/.test(this.expr);

        if (/^\d$/.test(key)) {
            if (/,\d{2}$/.test(segment) || (segment === '0' && key === '0')) {
                return;
            }

            this.expr = segment === '0' ? this.expr.slice(0, -1) + key : this.expr + key;
        } else if (key === ',') {
            if (!segment.includes(',')) {
                this.expr += segment === '' ? '0,' : ',';
            }
        } else if (key === '+' || key === '-') {
            if (this.expr === '' || this.expr === '-') {
                return;
            }

            this.expr = lastIsOperator ? this.expr.slice(0, -1) + key : this.expr + key;
        } else if (key === 'back') {
            this.expr = this.expr.slice(0, -1);
        } else if (key === 'clear') {
            this.expr = '';
        } else if (key === 'done') {
            this.finish();
            this.$refs.input.blur();

            return;
        }

        this.sync();
    },

    finish() {
        this.expr = this.result().replace(/[+-]$/, '');
        this.sync();
        this.padOpen = false;
    },
});

/**
 * Offline entries: transactions saved on the phone while there is no connection,
 * sent to the server as soon as it is back.
 */
const queueKey = 'maliyye:offline-transactions';

export function offlineQueueStore(csrfHeaders) {
    return {
        items: JSON.parse(window.localStorage.getItem(queueKey) || '[]'),
        syncing: false,

        get count() {
            return this.items.length;
        },

        persist() {
            window.localStorage.setItem(queueKey, JSON.stringify(this.items));
        },

        add(entry) {
            this.items.push({ id: crypto.randomUUID(), ...entry });
            this.persist();
        },

        async flush() {
            if (this.syncing || !this.items.length || !navigator.onLine) {
                return;
            }

            this.syncing = true;

            try {
                const response = await fetch('/transactions/offline', {
                    method: 'POST',
                    headers: csrfHeaders(),
                    credentials: 'same-origin',
                    body: JSON.stringify({ transactions: this.items }),
                });

                if (!response.ok) {
                    return;
                }

                const { synced = [], rejected = [] } = await response.json();
                const done = new Set([...synced, ...rejected]);
                this.items = this.items.filter((item) => !done.has(item.id));
                this.persist();

                if (synced.length) {
                    window.Flux?.toast?.({ variant: 'success', text: `${synced.length} offline əməliyyat göndərildi.` });
                    window.Livewire?.dispatch('transaction-saved');
                }
            } catch {
                // Still offline; try again on the next "online" event or page change.
            } finally {
                this.syncing = false;
            }
        },
    };
}

export function setUpOfflineMode() {
    const root = document.documentElement;
    const update = () => root.classList.toggle('is-offline', !navigator.onLine);

    window.addEventListener('online', () => {
        update();
        window.Alpine?.store('offlineQueue')?.flush();
    });
    window.addEventListener('offline', update);
    document.addEventListener('livewire:navigated', () => {
        update();
        window.Alpine?.store('offlineQueue')?.flush();
    });

    whenLivewire(() => {
        const dispatch = window.Livewire.dispatch.bind(window.Livewire);

        window.Livewire.dispatch = (name, params = {}) => {
            if (name === 'open-transaction-modal' && !navigator.onLine) {
                window.dispatchEvent(new CustomEvent('open-offline-entry', { detail: params }));

                return;
            }

            return dispatch(name, params);
        };
    });

    document.addEventListener('submit', (event) => {
        if (event.target.matches('form[action$="/logout"]')) {
            window.caches?.delete('maliyye-pages');
            window.localStorage.removeItem(queueKey);
        }
    });
}

/**
 * Alpine-only quick entry used while offline (no server round trips).
 */
export const offlineEntry = (categories) => ({
    categories,
    type: 'expense',
    amount: '',
    categoryId: null,
    occurredOn: '',
    note: '',
    error: null,

    get visibleCategories() {
        return this.categories.filter((category) => category.type === this.type);
    },

    open(detail = {}) {
        this.type = detail.type === 'income' ? 'income' : 'expense';
        this.amount = '';
        this.categoryId = null;
        this.note = '';
        this.error = null;
        this.occurredOn = new Date().toLocaleDateString('sv-SE');
        this.$refs.sheet.showModal();
    },

    close() {
        closeSheet(this.$refs.sheet);
    },

    save() {
        if (!this.amount || !(Number(this.amount.replace(',', '.')) > 0)) {
            this.error = 'Məbləği yazın.';

            return;
        }

        this.$store.offlineQueue.add({
            type: this.type,
            amount: this.amount,
            category_id: this.categoryId,
            occurred_on: this.occurredOn,
            note: this.note || null,
        });

        closeSheet(this.$refs.sheet);
        window.Flux?.toast?.({ text: 'Offline saxlanıldı — internet gələndə göndəriləcək.' });
    },
});
