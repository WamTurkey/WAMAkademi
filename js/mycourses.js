/**
 * WAM Akademi - Eğitimlerim filters and search.
 * Plain plugin JS (same pattern as lesson_layout.js). No AMD build required.
 */
(function () {
    'use strict';

    function normalizeSearch(value) {
        var text = String(value || '').toLocaleLowerCase('tr-TR');
        return text
            .replace(/ı/g, 'i')
            .replace(/İ/g, 'i')
            .replace(/ş/g, 's')
            .replace(/ğ/g, 'g')
            .replace(/ü/g, 'u')
            .replace(/ö/g, 'o')
            .replace(/ç/g, 'c');
    }

    function init(root) {
        if (!root || root.getAttribute('data-wam-ed-ready') === '1') {
            return;
        }
        root.setAttribute('data-wam-ed-ready', '1');

        var searchInput = root.querySelector('#wamEdSearch');
        var filterButtons = Array.prototype.slice.call(
            root.querySelectorAll('.wam-ed__filter')
        );
        var cards = Array.prototype.slice.call(
            root.querySelectorAll('.wam-ed__card')
        );
        var countEl = root.querySelector('#wamEdCount');
        var filterEmpty = root.querySelector('#wamEdFilterEmpty');
        var grid = root.querySelector('#wamEdGrid');
        var activeFilter = 'all';

        function setFilter(next) {
            activeFilter = next;
            filterButtons.forEach(function (button) {
                var pressed = button.getAttribute('data-filter') === activeFilter;
                button.setAttribute('aria-pressed', pressed ? 'true' : 'false');
                button.classList.toggle('is-active', pressed);
            });
            applyFilters();
        }

        function applyFilters() {
            var searchValue = normalizeSearch(searchInput ? searchInput.value : '');
            var visibleCount = 0;

            cards.forEach(function (card) {
                var searchText = card.getAttribute('data-search') || '';
                var status = card.getAttribute('data-status') || '';
                var matchesSearch =
                    searchValue === '' || searchText.indexOf(searchValue) !== -1;
                var matchesStatus =
                    activeFilter === 'all' || status === activeFilter;
                var show = matchesSearch && matchesStatus;

                if (show) {
                    card.removeAttribute('hidden');
                    visibleCount += 1;
                } else {
                    card.setAttribute('hidden', 'hidden');
                }
            });

            if (countEl) {
                countEl.textContent = visibleCount + ' eğitim';
            }

            if (filterEmpty) {
                var showEmpty = cards.length > 0 && visibleCount === 0;
                if (showEmpty) {
                    filterEmpty.removeAttribute('hidden');
                } else {
                    filterEmpty.setAttribute('hidden', 'hidden');
                }
            }

            if (grid) {
                if (visibleCount === 0 && cards.length > 0) {
                    grid.setAttribute('hidden', 'hidden');
                } else {
                    grid.removeAttribute('hidden');
                }
            }
        }

        if (searchInput) {
            searchInput.addEventListener('input', applyFilters);
            searchInput.addEventListener('keydown', function (event) {
                if (event.key === 'Escape') {
                    if (searchInput.value !== '') {
                        searchInput.value = '';
                        applyFilters();
                    }
                    searchInput.blur();
                }
            });
        }

        filterButtons.forEach(function (button) {
            button.addEventListener('click', function () {
                setFilter(button.getAttribute('data-filter') || 'all');
            });
        });

        // Continue-empty CTA can jump to Assigned filter.
        root.querySelectorAll('[data-wam-ed-filter]').forEach(function (el) {
            el.addEventListener('click', function (event) {
                var filter = el.getAttribute('data-wam-ed-filter');
                if (!filter) {
                    return;
                }
                event.preventDefault();
                setFilter(filter);
                var filters = root.querySelector('#wam-ed-filters');
                if (filters && typeof filters.focus === 'function') {
                    filters.setAttribute('tabindex', '-1');
                    filters.focus();
                }
            });
        });

        // Initialise active class from aria-pressed.
        filterButtons.forEach(function (button) {
            if (button.getAttribute('aria-pressed') === 'true') {
                button.classList.add('is-active');
                activeFilter = button.getAttribute('data-filter') || 'all';
            }
        });

        applyFilters();
    }

    function boot() {
        var root = document.querySelector('[data-region="wam-mycourses"]');
        if (root) {
            init(root);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
