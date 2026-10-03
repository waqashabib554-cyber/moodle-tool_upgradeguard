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

/**
 * Preserve the selected Upgrade Guard dashboard tab in the URL.
 *
 * @module    tool_upgradeguard/dashboard
 * @copyright 2026 Upgrade Guard
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

const SELECTORS = {
    dashboard: '.tool-upgradeguard',
    tablist: '.upgradeguard-tabs',
    tab: '[data-bs-toggle="tab"]',
    disclosure: '[data-bs-toggle="collapse"][data-showlabel]',
};

/**
 * Make the action disclosure say what pressing it will do.
 *
 * Bootstrap's collapse shows and hides the region and keeps aria-expanded
 * correct, but it never touches the control's own text. A button that still
 * reads "Show all 4 actions" after the four actions are on screen gives the
 * reader no way back and no hint that the same press closes it again.
 *
 * The two wordings travel in data attributes so the language of the label is
 * decided on the server, next to every other translated word on the page.
 *
 * @param {HTMLElement} dashboard The dashboard root.
 * @return {void}
 */
const initDisclosure = dashboard => {
    const buttons = [...dashboard.querySelectorAll(SELECTORS.disclosure)];

    buttons.forEach(button => {
        if (button.dataset.upgradeguardDisclosureInitialised === 'true') {
            return;
        }
        button.dataset.upgradeguardDisclosureInitialised = 'true';

        const region = document.getElementById(button.getAttribute('aria-controls'));
        if (!region) {
            return;
        }

        // The events fire on the region being collapsed, not on the button.
        region.addEventListener('shown.bs.collapse', () => {
            button.textContent = button.dataset.hidelabel;
        });
        region.addEventListener('hidden.bs.collapse', () => {
            button.textContent = button.dataset.showlabel;
        });
    });
};

/**
 * Initialise URL state for the dashboard tabs.
 */
export const init = () => {
    const dashboard = document.querySelector(SELECTORS.dashboard);
    if (!dashboard) {
        return;
    }

    initDisclosure(dashboard);

    const tablist = dashboard.querySelector(SELECTORS.tablist);
    if (!tablist || tablist.dataset.upgradeguardTabsInitialised === 'true') {
        return;
    }

    // The page normally initialises this module once, but guarding the tablist
    // keeps repeated calls from adding duplicate shown.bs.tab listeners.
    tablist.dataset.upgradeguardTabsInitialised = 'true';
    const tabs = [...tablist.querySelectorAll(SELECTORS.tab)];

    tablist.addEventListener('shown.bs.tab', event => {
        const tab = event.target;
        if (!tablist.contains(tab)) {
            return;
        }

        const hash = tab.getAttribute('href');
        if (!hash?.startsWith('#ug-pane-')) {
            return;
        }

        if (typeof window.history.replaceState === 'function') {
            // A relative hash retains the current path and query string without
            // adding a browser history entry for every tab click.
            window.history.replaceState(window.history.state, '', hash);
        } else {
            window.location.hash = hash;
        }
    });

    const requestedHash = window.location.hash;
    if (!requestedHash) {
        return;
    }

    const requestedTab = tabs.find(tab => tab.getAttribute('href') === requestedHash);
    if (requestedTab && !requestedTab.classList.contains('active')) {
        // Bootstrap owns tab activation. A click keeps this module compatible
        // with the current Bootstrap implementation and accessibility events.
        requestedTab.click();
    }
};
