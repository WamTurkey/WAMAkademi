(function () {
    'use strict';

    if (document.body.dataset.wamMenuReady === '1') {
        return;
    }

    var MAX_RETRIES = 25;
    var retryCount = 0;

    function getConfig() {
        if (typeof window.wamMenuConfig === 'object' && window.wamMenuConfig) {
            return window.wamMenuConfig;
        }
        var root =
            typeof M !== 'undefined' && M.cfg && M.cfg.wwwroot
                ? M.cfg.wwwroot
                : '';
        return {
            wwwroot: root,
            homeurl: root + '/local/wamakademi/index.php',
            profileurl: root + '/user/profile.php',
            active: 'home',
            links: [
                {
                    id: 'home',
                    label: 'Ana Sayfa',
                    url: root + '/local/wamakademi/index.php',
                },
                {
                    id: 'catalog',
                    label: 'Eğitimler',
                    url: root + '/local/wamakademi/catalog.php',
                },
                {
                    id: 'mycourses',
                    label: 'Eğitimlerim',
                    url: root + '/local/wamakademi/mycourses.php',
                },
                {
                    id: 'calendar',
                    label: 'Takvim',
                    url: root + '/local/wamakademi/calendar.php',
                },
            ],
            groups: [
                {
                    id: 'preferences',
                    label: 'Tercihler',
                    items: [
                        {
                            id: 'preferences',
                            label: 'Tercihler',
                            url: root + '/user/preferences.php',
                        },
                    ],
                },
            ],
            settings: [],
            banner: '',
            unreadConversationsTemplate: '{$a} okunmamış konuşma',
        };
    }

    function escapeHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function redirectAvatarToProfile(cfg) {
        var toggle = document.getElementById('user-menu-toggle');
        if (!toggle) {
            return;
        }
        toggle.removeAttribute('data-bs-toggle');
        toggle.removeAttribute('aria-expanded');
        toggle.removeAttribute('aria-controls');
        toggle.removeAttribute('aria-haspopup');
        toggle.setAttribute('href', cfg.profileurl || '/user/profile.php');
        toggle.setAttribute('aria-label', 'Profil');
        toggle.setAttribute('title', 'Profil');
        toggle.addEventListener('click', function (event) {
            // Bootstrap dropdown açılmasın; normal gezinmeye izin ver.
            event.stopPropagation();
        });
        document.body.classList.add('wam-usermenu-redirected');
    }

    function buildGearMenu(cfg, gearMenu) {
        gearMenu.innerHTML = '';

        if (cfg.banner) {
            var banner = document.createElement('div');
            banner.className = 'wam-gear__banner';
            banner.textContent = cfg.banner;
            gearMenu.appendChild(banner);
        }

        var groups = cfg.groups;
        if (!groups || !groups.length) {
            // Eski düz settings listesine düş.
            (cfg.settings || []).forEach(function (item) {
                var a = document.createElement('a');
                a.href = item.url;
                a.setAttribute('role', 'menuitem');
                a.textContent = item.label;
                gearMenu.appendChild(a);
            });
            return;
        }

        groups.forEach(function (group) {
            var hasItems = (group.items && group.items.length) ||
                (group.actions && group.actions.length) ||
                (group.languages && group.languages.length);
            if (!hasItems) {
                return;
            }

            var wrap = document.createElement('div');
            wrap.className = 'wam-gear__group';
            wrap.setAttribute('role', 'group');
            wrap.setAttribute('aria-label', group.label || group.id);

            if (group.label) {
                var title = document.createElement('div');
                title.className = 'wam-gear__group-title';
                title.textContent = group.label;
                wrap.appendChild(title);
            }

            (group.items || []).forEach(function (item) {
                var a = document.createElement('a');
                a.href = item.url;
                a.setAttribute('role', 'menuitem');
                a.className = 'wam-gear__item';
                a.textContent = item.label;
                wrap.appendChild(a);
            });

            if (group.languages && group.languages.length) {
                var langTitle = document.createElement('div');
                langTitle.className = 'wam-gear__group-title';
                langTitle.textContent = group.languageLabel || 'Dil';
                wrap.appendChild(langTitle);

                var langList = document.createElement('div');
                langList.className = 'wam-gear__langs';
                group.languages.forEach(function (lang) {
                    var a = document.createElement('a');
                    a.href = lang.url || '#';
                    a.setAttribute('role', 'menuitem');
                    a.textContent = lang.label;
                    if (lang.active) {
                        a.classList.add('is-active');
                        a.setAttribute('aria-current', 'true');
                    }
                    langList.appendChild(a);
                });
                wrap.appendChild(langList);
            }

            if (group.actions && group.actions.length) {
                if ((group.items && group.items.length) || (group.languages && group.languages.length)) {
                    var hr = document.createElement('hr');
                    hr.className = 'wam-gear__divider';
                    wrap.appendChild(hr);
                }
                group.actions.forEach(function (item) {
                    var a = document.createElement('a');
                    a.href = item.url;
                    a.setAttribute('role', 'menuitem');
                    a.className = 'wam-gear__item wam-gear__item--action';
                    a.textContent = item.label;
                    wrap.appendChild(a);
                });
            }

            gearMenu.appendChild(wrap);
        });
    }

    function setupGearKeyboard(gearBtn, gearMenu) {
        gearMenu.addEventListener('keydown', function (event) {
            var items = Array.prototype.slice.call(
                gearMenu.querySelectorAll('[role="menuitem"]')
            );
            if (!items.length) {
                return;
            }
            var index = items.indexOf(document.activeElement);
            if (event.key === 'ArrowDown') {
                event.preventDefault();
                items[(index + 1) % items.length].focus();
            } else if (event.key === 'ArrowUp') {
                event.preventDefault();
                items[(index - 1 + items.length) % items.length].focus();
            } else if (event.key === 'Home') {
                event.preventDefault();
                items[0].focus();
            } else if (event.key === 'End') {
                event.preventDefault();
                items[items.length - 1].focus();
            } else if (event.key === 'Tab') {
                gearMenu.hidden = true;
                gearBtn.setAttribute('aria-expanded', 'false');
            }
        });
    }

    function protectMessageCountA11y(cfg) {
        var template = cfg.unreadConversationsTemplate || '{$a} okunmamış konuşma';

        function fixContainer(container) {
            if (!container || container.classList.contains('hidden')) {
                return;
            }
            var visible = container.querySelector('[aria-hidden="true"]');
            var sr = container.querySelector('.visually-hidden');
            var raw = (visible ? visible.textContent : container.textContent || '').trim();
            var count = parseInt(raw, 10);
            if (isNaN(count)) {
                return;
            }

            if (!visible || !sr) {
                var id = sr ? sr.id : '';
                if (!id) {
                    var toggle = container.closest('a, button');
                    id = toggle ? toggle.getAttribute('aria-describedby') : '';
                    if (!id) {
                        id = 'unread-messages-count-wam';
                    }
                }
                container.innerHTML =
                    '<span aria-hidden="true">' +
                    escapeHtml(String(count)) +
                    '</span>' +
                    '<span class="visually-hidden" id="' +
                    escapeHtml(id) +
                    '">' +
                    escapeHtml(template.replace('{$a}', String(count))) +
                    '</span>';
                return;
            }

            if (visible.textContent.trim() !== String(count)) {
                visible.textContent = String(count);
            }
            var expected = template.replace('{$a}', String(count));
            if (sr.textContent.trim() !== expected) {
                sr.textContent = expected;
            }
        }

        function scan() {
            document
                .querySelectorAll(
                    '[data-region="popover-region-messages"] [data-region="count-container"]'
                )
                .forEach(fixContainer);
        }

        scan();

        var root = document.querySelector('[data-region="popover-region-messages"]');
        if (!root || typeof MutationObserver === 'undefined') {
            return;
        }
        var observer = new MutationObserver(function () {
            scan();
        });
        observer.observe(root, {
            childList: true,
            subtree: true,
            characterData: true,
        });
    }

    function init() {
        var brand = document.querySelector('.navbar .navbar-brand');
        var nav = document.querySelector('.navbar .container-fluid');
        if (!brand || !nav) {
            retryCount += 1;
            if (retryCount > MAX_RETRIES) {
                return;
            }
            setTimeout(init, 200);
            return;
        }

        document.body.dataset.wamMenuReady = '1';
        document.body.classList.add('wam-menu-ready');

        var cfg = getConfig();
        var brandHtml = brand.innerHTML;

        var toggle = document.createElement('button');
        toggle.type = 'button';
        toggle.className = 'navbar-brand wam-menu-toggle aabtn m-0 me-4 p-0';
        toggle.setAttribute('aria-expanded', 'false');
        toggle.setAttribute('aria-controls', 'wam-side-menu');
        toggle.setAttribute('aria-label', 'Menüyü aç');
        toggle.innerHTML = brandHtml;

        brand.replaceWith(toggle);

        var toggler = document.querySelector('.navbar-toggler');
        if (toggler) {
            toggler.classList.add('wam-hide-bootstrap-toggler');
            toggler.setAttribute('hidden', 'hidden');
        }

        var overlay = document.createElement('div');
        overlay.className = 'wam-menu-overlay';
        overlay.hidden = true;

        var panel = document.createElement('aside');
        panel.id = 'wam-side-menu';
        panel.className = 'wam-menu-panel';
        panel.setAttribute('role', 'dialog');
        panel.setAttribute('aria-modal', 'true');
        panel.setAttribute('aria-label', 'WAM menü');
        panel.hidden = true;

        var linksHtml = (cfg.links || [])
            .map(function (link) {
                var current =
                    link.id === cfg.active
                        ? ' aria-current="page" class="is-active"'
                        : '';
                return (
                    '<a href="' +
                    escapeHtml(link.url) +
                    '"' +
                    current +
                    '>' +
                    escapeHtml(link.label) +
                    '</a>'
                );
            })
            .join('');

        panel.innerHTML =
            '<div class="wam-menu-panel__head">' +
            '<div class="wam-menu-panel__brand">WAM</div>' +
            '<button type="button" class="wam-menu-close" aria-label="Menüyü kapat">×</button>' +
            '</div>' +
            '<nav class="wam-menu-panel__nav">' +
            linksHtml +
            '</nav>';

        document.body.appendChild(overlay);
        document.body.appendChild(panel);

        var userNav = document.querySelector('#usernavigation');
        var gearWrap = document.createElement('div');
        gearWrap.className = 'wam-gear';
        gearWrap.innerHTML =
            '<button type="button" class="wam-gear__btn" aria-expanded="false" aria-haspopup="true" aria-label="Ayarlar">' +
            '<svg width="18" height="18" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="1.8">' +
            '<circle cx="12" cy="12" r="3"></circle>' +
            '<path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9c.1.7.7 1.2 1.5 1.2H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"></path>' +
            '</svg></button>' +
            '<div class="wam-gear__menu" hidden role="menu" aria-label="Hesap ayarları"></div>';

        var gearMenu = gearWrap.querySelector('.wam-gear__menu');
        buildGearMenu(cfg, gearMenu);

        if (userNav) {
            userNav.insertBefore(gearWrap, userNav.firstChild);
        } else {
            nav.appendChild(gearWrap);
        }

        redirectAvatarToProfile(cfg);
        protectMessageCountA11y(cfg);

        var gearBtn = gearWrap.querySelector('.wam-gear__btn');
        setupGearKeyboard(gearBtn, gearMenu);

        var closeBtn = panel.querySelector('.wam-menu-close');
        var lastFocus = null;

        function openMenu() {
            lastFocus = document.activeElement;
            panel.hidden = false;
            overlay.hidden = false;
            document.body.classList.add('wam-menu-open');
            toggle.setAttribute('aria-expanded', 'true');
            toggle.setAttribute('aria-label', 'Menüyü kapat');
            closeBtn.focus();
        }

        function closeMenu() {
            panel.hidden = true;
            overlay.hidden = true;
            document.body.classList.remove('wam-menu-open');
            toggle.setAttribute('aria-expanded', 'false');
            toggle.setAttribute('aria-label', 'Menüyü aç');
            if (lastFocus && typeof lastFocus.focus === 'function') {
                lastFocus.focus();
            } else {
                toggle.focus();
            }
        }

        function toggleMenu() {
            if (panel.hidden) {
                openMenu();
            } else {
                closeMenu();
            }
        }

        function closeGear() {
            gearMenu.hidden = true;
            gearBtn.setAttribute('aria-expanded', 'false');
        }

        function openGear() {
            gearMenu.hidden = false;
            gearBtn.setAttribute('aria-expanded', 'true');
            var first = gearMenu.querySelector('[role="menuitem"]');
            if (first) {
                first.focus();
            }
        }

        toggle.addEventListener('click', function (event) {
            event.preventDefault();
            toggleMenu();
        });

        closeBtn.addEventListener('click', closeMenu);
        overlay.addEventListener('click', closeMenu);

        panel.addEventListener('click', function (event) {
            var link = event.target.closest('a');
            if (link) {
                closeMenu();
            }
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                if (!panel.hidden) {
                    closeMenu();
                }
                if (!gearMenu.hidden) {
                    closeGear();
                    gearBtn.focus();
                }
            }
        });

        gearBtn.addEventListener('click', function (event) {
            event.stopPropagation();
            if (gearMenu.hidden) {
                openGear();
            } else {
                closeGear();
            }
        });

        document.addEventListener('click', function (event) {
            if (!gearWrap.contains(event.target)) {
                closeGear();
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
