import { Passkeys, PasskeyExistsError, UserCancelledError } from '@laravel/passkeys';
import ApexCharts from 'apexcharts';
import { registerServiceWorker, setUpPullToRefresh, setUpQuickAddLinks } from './pwa';

const formatMoney = (value, symbol) =>
    `${Number(value).toLocaleString('az-AZ', { minimumFractionDigits: 0, maximumFractionDigits: 2 })} ${symbol}`;

const isDark = () => document.documentElement.classList.contains('dark');

const cssVar = (name) => getComputedStyle(document.documentElement).getPropertyValue(name).trim();

document.addEventListener('alpine:init', () => {
    const Alpine = window.Alpine;

    /**
     * Ad-hoc multi-select: pick any rows/cards and see their total.
     * Items: key => { amount (signed minor units), label }. Cleared on page navigation.
     */
    Alpine.store('selection', {
        items: {},

        has(key) {
            return key in this.items;
        },

        toggle(key, amount, label = '') {
            if (this.has(key)) {
                delete this.items[key];
            } else {
                this.items[key] = { amount: Number(amount), label };
            }
        },

        setMany(entries, selected) {
            entries.forEach(([key, amount, label]) => {
                if (selected) {
                    this.items[key] = { amount: Number(amount), label };
                } else {
                    delete this.items[key];
                }
            });
        },

        allSelected(entries) {
            return entries.length > 0 && entries.every(([key]) => this.has(key));
        },

        clear() {
            this.items = {};
        },

        get count() {
            return Object.keys(this.items).length;
        },

        get total() {
            return Object.values(this.items).reduce((sum, item) => sum + item.amount, 0);
        },

        get positive() {
            return Object.values(this.items).reduce((sum, item) => sum + Math.max(item.amount, 0), 0);
        },

        get negative() {
            return Object.values(this.items).reduce((sum, item) => sum + Math.min(item.amount, 0), 0);
        },

        format(minor, symbol) {
            const sign = minor < 0 ? '−' : '';
            const abs = Math.abs(minor);
            const major = Math.floor(abs / 100).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ' ');

            return `${sign}${major},${String(abs % 100).padStart(2, '0')} ${symbol}`;
        },
    });

    document.addEventListener('livewire:navigated', () => Alpine.store('selection').clear());

    /**
     * Face ID / Touch ID login button on the login page.
     */
    Alpine.data('passkeyLogin', () => ({
        supported: false,
        busy: false,
        error: null,

        init() {
            this.supported = Passkeys.isSupported();
        },

        async verify() {
            this.busy = true;
            this.error = null;

            try {
                const response = await Passkeys.verify({ remember: true });
                window.location.href = response.redirect ?? '/';
            } catch (error) {
                this.error = error instanceof UserCancelledError ? null : 'Passkey ilə giriş alınmadı. Yenidən cəhd edin.';
            } finally {
                this.busy = false;
            }
        },
    }));

    /**
     * Adds a passkey for the signed-in user (Settings → Security).
     */
    Alpine.data('passkeyRegister', (defaultName = 'iPhone') => ({
        supported: false,
        busy: false,
        error: null,
        name: defaultName,

        init() {
            this.supported = Passkeys.isSupported();
        },

        async register() {
            this.busy = true;
            this.error = null;

            try {
                await Passkeys.register({ name: this.name.trim() || defaultName });
                this.$dispatch('passkey-registered');
            } catch (error) {
                if (error instanceof UserCancelledError) {
                    this.error = null;
                } else if (error instanceof PasskeyExistsError) {
                    this.error = 'Bu cihaz üçün passkey artıq əlavə olunub.';
                } else {
                    this.error = 'Passkey əlavə olunmadı. Yenidən cəhd edin.';
                }
            } finally {
                this.busy = false;
            }
        },
    }));

    /**
     * <div x-data="apexChart(@js($options), '₼')"><div x-ref="canvas"></div></div>
     */
    Alpine.data('apexChart', (options, symbol = null) => ({
        chart: null,
        observer: null,

        init() {
            this.render();

            this.observer = new MutationObserver(() => this.render());
            this.observer.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
        },

        render() {
            this.chart?.destroy();

            const dark = isDark();
            const muted = cssVar('--faint');
            const config = {
                ...options,
                chart: {
                    background: 'transparent',
                    fontFamily: "'Google Sans Flex', ui-sans-serif, system-ui",
                    foreColor: muted,
                    toolbar: { show: false },
                    zoom: { enabled: false },
                    animations: { enabled: !window.matchMedia('(prefers-reduced-motion: reduce)').matches, speed: 400 },
                    ...options.chart,
                },
                theme: { mode: dark ? 'dark' : 'light' },
                grid: { borderColor: cssVar('--line'), strokeDashArray: 4, ...(options.grid ?? {}) },
                dataLabels: { enabled: false, ...(options.dataLabels ?? {}) },
                legend: { labels: { colors: muted }, markers: { shape: 'circle' }, ...(options.legend ?? {}) },
                tooltip: { theme: dark ? 'dark' : 'light', ...(options.tooltip ?? {}) },
            };

            if (symbol) {
                config.tooltip = { ...config.tooltip, y: { formatter: (value) => formatMoney(value, symbol) } };

                if (config.chart.type !== 'donut') {
                    config.yaxis = { ...(config.yaxis ?? {}), labels: { formatter: (value) => formatMoney(value, symbol) } };
                }
            }

            this.chart = new ApexCharts(this.$refs.canvas, config);
            this.chart.render();
        },

        destroy() {
            this.chart?.destroy();
            this.observer?.disconnect();
        },
    }));
});

registerServiceWorker();
setUpQuickAddLinks();
document.addEventListener('DOMContentLoaded', setUpPullToRefresh);
