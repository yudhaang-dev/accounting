import '@testing-library/jest-dom/vitest';
import { cleanup, configure } from '@testing-library/react';
import { afterEach, beforeAll, vi } from 'vitest';
import { initI18n, setLocale } from '@/i18n';

// Waiting for a screen to settle is not a failure: a loaded machine needs more than the default 1s.
configure({ asyncUtilTimeout: 5000 });

beforeAll(async () => {
    initI18n();
    await setLocale('id');
});

afterEach(() => {
    cleanup();
    localStorage.clear();
    document.cookie =
        'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/';
    vi.unstubAllGlobals();
    vi.useRealTimers();
});

// jsdom lacks these browser APIs, which the sidebar and responsive hooks touch.
Object.defineProperty(window, 'matchMedia', {
    writable: true,
    value: (query: string) => ({
        matches: false,
        media: query,
        onchange: null,
        addEventListener: () => undefined,
        removeEventListener: () => undefined,
        addListener: () => undefined,
        removeListener: () => undefined,
        dispatchEvent: () => false,
    }),
});

class ResizeObserverStub {
    observe() {}
    unobserve() {}
    disconnect() {}
}

globalThis.ResizeObserver = ResizeObserverStub;
window.scrollTo = () => undefined;

// input-otp probes the element under the pointer; jsdom has no layout.
document.elementFromPoint = () => null;

// Recent Node versions ship a global `localStorage` that is unusable without a backing file and
// shadows jsdom's; a small in-memory Storage keeps tests independent of the Node version.
class MemoryStorage implements Storage {
    private store = new Map<string, string>();

    get length() {
        return this.store.size;
    }

    clear() {
        this.store.clear();
    }

    getItem(key: string) {
        return this.store.get(key) ?? null;
    }

    key(index: number) {
        return [...this.store.keys()][index] ?? null;
    }

    removeItem(key: string) {
        this.store.delete(key);
    }

    setItem(key: string, value: string) {
        this.store.set(key, String(value));
    }
}

Object.defineProperty(globalThis, 'localStorage', {
    configurable: true,
    value: new MemoryStorage(),
});
