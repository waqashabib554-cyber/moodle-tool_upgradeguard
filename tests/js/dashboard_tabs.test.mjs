// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

import assert from 'node:assert/strict';
import {readFile} from 'node:fs/promises';
import test, {afterEach} from 'node:test';

const originalDocument = globalThis.document;
const originalWindow = globalThis.window;

afterEach(() => {
    globalThis.document = originalDocument;
    globalThis.window = originalWindow;
});

class FakeClassList {
    constructor(...names) {
        this.names = new Set(names);
    }

    contains(name) {
        return this.names.has(name);
    }
}

class FakeTab {
    constructor(hash, active = false) {
        this.hash = hash;
        this.classList = new FakeClassList(...(active ? ['active'] : []));
        this.clickCount = 0;
    }

    getAttribute(name) {
        return name === 'href' ? this.hash : null;
    }

    click() {
        this.clickCount++;
    }
}

class FakeTablist {
    constructor(tabs) {
        this.tabs = tabs;
        this.dataset = {};
        this.listeners = new Map();
    }

    querySelectorAll(selector) {
        assert.equal(selector, '[data-bs-toggle="tab"]');
        return this.tabs;
    }

    contains(element) {
        return this.tabs.includes(element);
    }

    addEventListener(type, listener) {
        this.listeners.set(type, listener);
    }

    dispatch(type, target) {
        this.listeners.get(type)?.({target});
    }
}

const loadModule = async () => {
    const path = new URL('../../amd/src/dashboard.js', import.meta.url);
    const source = await readFile(path, 'utf8');
    const sourceUrl = `data:text/javascript;base64,${Buffer.from(source).toString('base64')}`;
    return import(sourceUrl);
};

const setEnvironment = (hash, {withHistory = true, activeHash = null} = {}) => {
    const tabs = ['#ug-pane-overview', '#ug-pane-checklist'].map(value => {
        return new FakeTab(value, value === activeHash);
    });
    const tablist = new FakeTablist(tabs);
    const dashboard = {
        querySelector: selector => selector === '.upgradeguard-tabs' ? tablist : null,
        querySelectorAll: () => [],
    };
    const historyCalls = [];
    const browserWindow = {
        location: {hash},
        history: {
            state: {page: 'dashboard'},
            replaceState: withHistory ? (...args) => historyCalls.push(args) : undefined,
        },
    };

    globalThis.document = {
        querySelector: selector => selector === '.tool-upgradeguard' ? dashboard : null,
    };
    globalThis.window = browserWindow;

    return {browserWindow, historyCalls, tablist, tabs};
};

test('a valid URL hash activates the matching dashboard tab', async () => {
    const {init} = await loadModule();
    const {tabs} = setEnvironment('#ug-pane-checklist');

    init();

    assert.equal(tabs[0].clickCount, 0);
    assert.equal(tabs[1].clickCount, 1);
});

test('missing, unknown, and already-active hashes do not trigger another activation', async () => {
    const {init} = await loadModule();

    for (const environment of [
        setEnvironment(''),
        setEnvironment('#ug-pane-does-not-exist'),
        setEnvironment('#ug-pane-overview', {activeHash: '#ug-pane-overview'}),
    ]) {
        init();
        assert.equal(environment.tabs[0].clickCount, 0);
        assert.equal(environment.tabs[1].clickCount, 0);
    }
});

test('showing a tab updates the hash without discarding existing history state', async () => {
    const {init} = await loadModule();
    const {historyCalls, tablist, tabs} = setEnvironment('');

    init();
    tablist.dispatch('shown.bs.tab', tabs[1]);

    assert.deepEqual(historyCalls, [[{page: 'dashboard'}, '', '#ug-pane-checklist']]);
});

test('showing a tab falls back to location.hash when replaceState is unavailable', async () => {
    const {init} = await loadModule();
    const {browserWindow, tablist, tabs} = setEnvironment('', {withHistory: false});

    init();
    tablist.dispatch('shown.bs.tab', tabs[1]);

    assert.equal(browserWindow.location.hash, '#ug-pane-checklist');
});

test('repeated initialisation does not add duplicate shown event listeners', async () => {
    const {init} = await loadModule();
    const {historyCalls, tablist, tabs} = setEnvironment('');

    init();
    init();
    tablist.dispatch('shown.bs.tab', tabs[1]);

    assert.equal(historyCalls.length, 1);
});
