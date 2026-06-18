import { mount } from '@vue/test-utils';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import SwitchPortGrid from '@/Components/Admin/SwitchPortGrid.vue';

vi.mock('@inertiajs/vue3', () => ({
    router: {
        visit: vi.fn(),
    },
    Link: {
        template: '<a :href="href"><slot /></a>',
        props: ['href'],
    },
    usePage: vi.fn(() => ({
        props: {},
    })),
}));

globalThis.route = vi.fn((...args) => `/mocked/${args[0]}/${JSON.stringify(args[1] || {})}`);

const themeVars = {
    '--color-danger': 'oklch(65% 0.2 25)',
    '--color-success': 'oklch(72% 0.17 155)',
    '--color-warning': 'oklch(78% 0.15 85)',
    '--color-primary': 'oklch(76% 0.16 55)',
    '--color-text-muted': 'oklch(55% 0.006 60)',
    '--color-border': 'oklch(26% 0.004 60)',
};

const origGetComputedStyle = window.getComputedStyle;
vi.spyOn(window, 'getComputedStyle').mockImplementation((el) => {
    const real = origGetComputedStyle(el);
    return new Proxy(real, {
        get(target, prop) {
            if (prop === 'getPropertyValue') {
                return (name) => themeVars[name] || target.getPropertyValue(name);
            }
            return target[prop];
        },
    });
});

const samplePorts = [
    {
        id: 1,
        interface: 'Gi0/1',
        status: 'connected',
        admin_status: 'up',
        speed: '1000',
        description: 'Server 1',
    },
    {
        id: 2,
        interface: 'Gi0/2',
        status: 'connected',
        admin_status: 'up',
        speed: '100',
        description: 'Printer',
    },
    {
        id: 3,
        interface: 'Gi0/3',
        status: 'connected',
        admin_status: 'up',
        speed: '10',
        description: 'Legacy device',
    },
    {
        id: 4,
        interface: 'Gi0/4',
        status: 'err-disabled',
        admin_status: 'up',
        speed: null,
        description: 'Error port',
    },
    {
        id: 5,
        interface: 'Gi0/5',
        status: 'disabled',
        admin_status: 'down',
        speed: null,
        description: 'Admin down port',
    },
    {
        id: 6,
        interface: 'Gi0/6',
        status: 'notconnect',
        admin_status: 'up',
        speed: null,
        description: null,
    },
    {
        id: 7,
        interface: 'Gi0/7',
        status: 'connected',
        admin_status: 'up',
        speed: 'a-1000',
        description: 'Auto-negotiated 1G',
    },
    {
        id: 8,
        interface: 'Gi0/8',
        status: 'connected',
        admin_status: 'up',
        speed: 'a-100',
        description: 'Auto-negotiated 100M',
    },
];

function mountGrid(propsOverride = {}) {
    return mount(SwitchPortGrid, {
        props: {
            ports: samplePorts,
            switchId: 1,
            ...propsOverride,
        },
        global: {
            mocks: {
                route: globalThis.route,
            },
        },
    });
}

function normalizeOklch(val) {
    return val.replace(/(\d+)%/g, (_, n) => String(Number(n) / 100));
}

function expectBgColor(cell, color) {
    const style = cell.attributes('style');
    const normalized = normalizeOklch(color);
    expect(style).toContain(`background-color: ${normalized}`);
}

describe('SwitchPortGrid', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    describe('rendering', () => {
        it('renders the grid container', () => {
            const wrapper = mountGrid();
            expect(wrapper.find('[data-testid="switch-port-grid"]').exists()).toBe(true);
        });

        it('renders a port cell for each port', () => {
            const wrapper = mountGrid();
            const cells = wrapper.findAll('[data-testid^="port-cell-"]');
            expect(cells.length).toBe(samplePorts.length);
        });

        it('renders with empty ports array', () => {
            const wrapper = mountGrid({ ports: [] });
            expect(wrapper.find('[data-testid="switch-port-grid"]').exists()).toBe(true);
            expect(wrapper.findAll('[data-testid^="port-cell-"]').length).toBe(0);
        });

        it('each port cell is a link element', () => {
            const wrapper = mountGrid();
            const cells = wrapper.findAll('[data-testid^="port-cell-"]');
            cells.forEach((cell) => {
                expect(cell.element.tagName).toBe('A');
            });
        });
    });

    describe('color mapping', () => {
        it('applies success color for 1Gbps connected port', () => {
            const wrapper = mountGrid({
                ports: [
                    {
                        id: 1,
                        interface: 'Gi0/1',
                        status: 'connected',
                        admin_status: 'up',
                        speed: '1000',
                        description: 'Test',
                    },
                ],
            });
            const cell = wrapper.find('[data-testid="port-cell-Gi0/1"]');
            expectBgColor(cell, themeVars['--color-success']);
        });

        it('applies green for auto-negotiated 1Gbps (a-1000)', () => {
            const wrapper = mountGrid({
                ports: [
                    {
                        id: 1,
                        interface: 'Gi0/1',
                        status: 'connected',
                        admin_status: 'up',
                        speed: 'a-1000',
                        description: 'Test',
                    },
                ],
            });
            const cell = wrapper.find('[data-testid="port-cell-Gi0/1"]');
            expectBgColor(cell, themeVars['--color-success']);
        });

        it('applies warning color for 100Mbps connected port', () => {
            const wrapper = mountGrid({
                ports: [
                    {
                        id: 1,
                        interface: 'Gi0/1',
                        status: 'connected',
                        admin_status: 'up',
                        speed: '100',
                        description: 'Test',
                    },
                ],
            });
            const cell = wrapper.find('[data-testid="port-cell-Gi0/1"]');
            expectBgColor(cell, themeVars['--color-warning']);
        });

        it('applies yellow for auto-negotiated 100Mbps (a-100)', () => {
            const wrapper = mountGrid({
                ports: [
                    {
                        id: 1,
                        interface: 'Gi0/1',
                        status: 'connected',
                        admin_status: 'up',
                        speed: 'a-100',
                        description: 'Test',
                    },
                ],
            });
            const cell = wrapper.find('[data-testid="port-cell-Gi0/1"]');
            expectBgColor(cell, themeVars['--color-warning']);
        });

        it('applies primary color for 10Mbps connected port', () => {
            const wrapper = mountGrid({
                ports: [
                    {
                        id: 1,
                        interface: 'Gi0/1',
                        status: 'connected',
                        admin_status: 'up',
                        speed: '10',
                        description: 'Test',
                    },
                ],
            });
            const cell = wrapper.find('[data-testid="port-cell-Gi0/1"]');
            expectBgColor(cell, themeVars['--color-primary']);
        });

        it('applies danger color for error-disabled port', () => {
            const wrapper = mountGrid({
                ports: [
                    {
                        id: 1,
                        interface: 'Gi0/1',
                        status: 'err-disabled',
                        admin_status: 'up',
                        speed: null,
                        description: 'Test',
                    },
                ],
            });
            const cell = wrapper.find('[data-testid="port-cell-Gi0/1"]');
            expectBgColor(cell, themeVars['--color-danger']);
        });

        it('applies muted color for admin down port', () => {
            const wrapper = mountGrid({
                ports: [
                    {
                        id: 1,
                        interface: 'Gi0/1',
                        status: 'disabled',
                        admin_status: 'down',
                        speed: null,
                        description: 'Test',
                    },
                ],
            });
            const cell = wrapper.find('[data-testid="port-cell-Gi0/1"]');
            expectBgColor(cell, themeVars['--color-text-muted']);
        });

        it('applies border color for not connected port', () => {
            const wrapper = mountGrid({
                ports: [
                    {
                        id: 1,
                        interface: 'Gi0/1',
                        status: 'notconnect',
                        admin_status: 'up',
                        speed: null,
                        description: 'Test',
                    },
                ],
            });
            const cell = wrapper.find('[data-testid="port-cell-Gi0/1"]');
            expectBgColor(cell, themeVars['--color-border']);
        });

        it('applies border color for down port that is not admin down', () => {
            const wrapper = mountGrid({
                ports: [
                    {
                        id: 1,
                        interface: 'Gi0/1',
                        status: 'down',
                        admin_status: 'up',
                        speed: null,
                        description: 'Test',
                    },
                ],
            });
            const cell = wrapper.find('[data-testid="port-cell-Gi0/1"]');
            expectBgColor(cell, themeVars['--color-border']);
        });

        it('applies green for 10Gbps connected port', () => {
            const wrapper = mountGrid({
                ports: [
                    {
                        id: 1,
                        interface: 'Gi0/1',
                        status: 'connected',
                        admin_status: 'up',
                        speed: '10000',
                        description: 'Test',
                    },
                ],
            });
            const cell = wrapper.find('[data-testid="port-cell-Gi0/1"]');
            expectBgColor(cell, themeVars['--color-success']);
        });
    });

    describe('tooltip', () => {
        it('shows port name as title attribute', () => {
            const wrapper = mountGrid({
                ports: [
                    {
                        id: 1,
                        interface: 'Gi0/1',
                        status: 'connected',
                        admin_status: 'up',
                        speed: '1000',
                        description: 'Server 1',
                    },
                ],
            });
            const cell = wrapper.find('[data-testid="port-cell-Gi0/1"]');
            expect(cell.attributes('title')).toContain('Gi0/1');
        });

        it('includes description in title when present', () => {
            const wrapper = mountGrid({
                ports: [
                    {
                        id: 1,
                        interface: 'Gi0/1',
                        status: 'connected',
                        admin_status: 'up',
                        speed: '1000',
                        description: 'Server 1',
                    },
                ],
            });
            const cell = wrapper.find('[data-testid="port-cell-Gi0/1"]');
            expect(cell.attributes('title')).toContain('Server 1');
        });

        it('handles null description gracefully', () => {
            const wrapper = mountGrid({
                ports: [
                    {
                        id: 1,
                        interface: 'Gi0/1',
                        status: 'notconnect',
                        admin_status: 'up',
                        speed: null,
                        description: null,
                    },
                ],
            });
            const cell = wrapper.find('[data-testid="port-cell-Gi0/1"]');
            expect(cell.attributes('title')).toContain('Gi0/1');
        });
    });

    describe('navigation', () => {
        it('links to port detail page', () => {
            const wrapper = mountGrid({
                ports: [
                    {
                        id: 1,
                        interface: 'Gi0/1',
                        status: 'connected',
                        admin_status: 'up',
                        speed: '1000',
                        description: 'Test',
                    },
                ],
            });
            const cell = wrapper.find('[data-testid="port-cell-Gi0/1"]');
            expect(cell.attributes('href')).toBeTruthy();
        });
    });

    describe('layout', () => {
        it('uses single-row layout for fewer than 16 ports', () => {
            const wrapper = mountGrid();
            expect(wrapper.find('[data-testid="port-grid-dual"]').exists()).toBe(false);
        });

        it('uses dual-row layout for 16 or more ports', () => {
            const manyPorts = Array.from({ length: 24 }, (_, i) => ({
                id: i + 1,
                interface: `Gi0/${i + 1}`,
                status: 'connected',
                admin_status: 'up',
                speed: '1000',
                description: null,
            }));
            const wrapper = mountGrid({ ports: manyPorts });
            expect(wrapper.find('[data-testid="port-grid-dual"]').exists()).toBe(true);
        });

        it('splits odd-indexed ports to top row and even-indexed to bottom row', () => {
            const manyPorts = Array.from({ length: 24 }, (_, i) => ({
                id: i + 1,
                interface: `Gi0/${i + 1}`,
                status: 'connected',
                admin_status: 'up',
                speed: '1000',
                description: null,
            }));
            const wrapper = mountGrid({ ports: manyPorts });
            const rows = wrapper.findAll('[data-testid="port-grid-dual"] > .flex');
            expect(rows.length).toBe(2);
            expect(rows[0].findAll('a').length).toBe(12);
            expect(rows[1].findAll('a').length).toBe(12);
        });

        it('shows first and last port labels', () => {
            const manyPorts = Array.from({ length: 24 }, (_, i) => ({
                id: i + 1,
                interface: `Gi0/${i + 1}`,
                status: 'connected',
                admin_status: 'up',
                speed: '1000',
                description: null,
            }));
            const wrapper = mountGrid({ ports: manyPorts });
            const text = wrapper.text();
            expect(text).toContain('Gi0/1');
            expect(text).toContain('Gi0/24');
        });

        it('shows labels for single-row layout with multiple ports', () => {
            const wrapper = mountGrid();
            const text = wrapper.text();
            expect(text).toContain('Gi0/1');
            expect(text).toContain('Gi0/8');
        });
    });

    describe('legend', () => {
        it('renders the legend', () => {
            const wrapper = mountGrid();
            expect(wrapper.find('[data-testid="port-grid-legend"]').exists()).toBe(true);
        });

        it('shows all status labels in legend', () => {
            const wrapper = mountGrid();
            const legend = wrapper.find('[data-testid="port-grid-legend"]');
            expect(legend.text()).toContain('1Gbps+');
            expect(legend.text()).toContain('100Mbps');
            expect(legend.text()).toContain('10Mbps');
            expect(legend.text()).toContain('Error');
            expect(legend.text()).toContain('Admin Down');
            expect(legend.text()).toContain('Not Connected');
        });
    });
});
