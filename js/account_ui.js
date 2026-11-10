(function () {
    'use strict';

    if (document.body.dataset.wamAccountUi === '1') {
        return;
    }
    document.body.dataset.wamAccountUi = '1';

    function cfg() {
        return (typeof window.wamAccountConfig === 'object' && window.wamAccountConfig)
            ? window.wamAccountConfig
            : {};
    }

    function str(key, fallback) {
        var s = cfg().strings || {};
        return s[key] || fallback || key;
    }

    function qs(root, sel) {
        return (root || document).querySelector(sel);
    }

    function qsa(root, sel) {
        return Array.prototype.slice.call((root || document).querySelectorAll(sel));
    }

    /* ---------------- Profil ---------------- */

    function enhanceProfile() {
        document.documentElement.classList.add('wam-acc-ready');
        document.body.classList.add('wam-acc-ready');
        var page = document.getElementById('page-user-profile');
        if (page) {
            page.classList.add('wam-acc-ready');
        }

        var header = qs(document, '.page-context-header');
        if (header) {
            var actions = qs(header, '.wam-acc-header-actions');
            if (!actions) {
                actions = document.createElement('div');
                actions.className = 'wam-acc-header-actions';
                header.appendChild(actions);
            }

            // Profili düzenle — çekirdek bağlantıyı taşı (yetki korunur).
            var editLi = qs(document, '.userprofile .profile_tree li.editprofile a');
            if (editLi && !qs(actions, '.wam-acc-btn--edit')) {
                var editBtn = document.createElement('a');
                editBtn.className = 'wam-acc-btn wam-acc-btn--primary wam-acc-btn--edit';
                editBtn.href = editLi.getAttribute('href');
                editBtn.textContent = editLi.textContent.trim() || str('editprofile', 'Profili düzenle');
                actions.appendChild(editBtn);
                var li = editLi.closest('li');
                if (li) {
                    li.classList.add('wam-acc-hidden');
                }
            }

            // Başlıktaki diğer düğmeleri koru (Mesaj / Rehbere ekle).
            var btnGroup = qs(header, '.header-button-group');
            if (btnGroup && btnGroup.parentNode !== actions) {
                actions.appendChild(btnGroup);
            }

            // Bölüm satırı.
            var headings = qs(header, '.page-header-headings');
            var deptSrc = qs(document, '[data-wam-department]');
            if (headings && deptSrc && !qs(headings, '.wam-acc-dept')) {
                var dept = document.createElement('div');
                dept.className = 'wam-acc-dept';
                dept.textContent = deptSrc.textContent.trim();
                headings.appendChild(dept);
            }
        }

        // Kendi profilinde Mesaj düğmesini gizle.
        var currentId = parseInt(cfg().currentUserId, 10) || 0;
        var profileId = parseInt(cfg().profileUserId, 10) || 0;
        if (currentId && profileId && currentId === profileId) {
            var msgBtn = document.getElementById('message-user-button');
            if (msgBtn) {
                msgBtn.hidden = true;
                msgBtn.setAttribute('aria-hidden', 'true');
                msgBtn.style.display = 'none';
            }
        }

        // Erişim satırlarında göreli süreyi soluk göster.
        qsa(document, '.userprofile .profile_tree .contentnode dd').forEach(function (dd) {
            var html = dd.innerHTML;
            if (!html || dd.dataset.wamReltime === '1') {
                return;
            }
            var updated = html.replace(
                /&nbsp;\s*\(([^)]+)\)|\s+\(([^)]+)\)\s*$/,
                function (m, a, b) {
                    var t = a || b;
                    return ' <span class="wam-acc-reltime">· ' + t + '</span>';
                }
            );
            if (updated !== html) {
                dd.innerHTML = updated;
                dd.dataset.wamReltime = '1';
            }
        });
    }

    /* ---------------- Tercihler ---------------- */

    function prefsBucket(href) {
        var h = String(href || '').toLowerCase();
        if (
            h.indexOf('/user/edit.php') !== -1 ||
            h.indexOf('/user/editadvanced.php') !== -1 ||
            h.indexOf('/login/change_password.php') !== -1 ||
            h.indexOf('/user/language.php') !== -1 ||
            h.indexOf('/auth/oauth2/linkedlogins.php') !== -1 ||
            h.indexOf('/admin/tool/mfa/') !== -1
        ) {
            return 'account';
        }
        if (
            h.indexOf('/message/notificationpreferences.php') !== -1 ||
            h.indexOf('/message/edit.php') !== -1
        ) {
            return 'notifications';
        }
        return 'advanced';
    }

    function prefsIcon(bucket) {
        var paths = {
            account: '<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
            notifications: '<path d="M18 8a6 6 0 1 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/>',
            advanced: '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9c.1.7.7 1.2 1.5 1.2H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>'
        };
        return (
            '<svg class="wam-acc-icon" viewBox="0 0 24 24" aria-hidden="true">' +
            (paths[bucket] || paths.advanced) +
            '</svg>'
        );
    }

    function enhancePreferences() {
        var page = document.getElementById('page-user-preferences');
        if (page) {
            page.classList.add('wam-acc-ready');
        }
        document.body.classList.add('wam-acc-ready');

        var source = qs(document, '.container-fluid.p-sm-0');
        if (!source || source.classList.contains('wam-acc-prefs-built')) {
            return;
        }

        var links = [];
        qsa(source, 'a[href]').forEach(function (a) {
            var href = a.getAttribute('href');
            if (!href || href === '#') {
                return;
            }
            links.push({
                href: href,
                label: (a.textContent || '').trim(),
                title: a.getAttribute('title') || '',
                bucket: prefsBucket(href),
            });
        });

        if (!links.length) {
            return;
        }

        var order = [
            { id: 'account', title: str('account', 'Kullanıcı hesabı') },
            { id: 'notifications', title: str('notifications', 'Bildirimler ve mesajlar') },
            { id: 'advanced', title: str('advanced', 'Gelişmiş ayarlar') },
        ];

        var wrap = document.createElement('div');
        wrap.className = 'wam-acc-prefs';
        wrap.setAttribute('data-region', 'wam-preferences');

        order.forEach(function (group) {
            var items = links.filter(function (l) { return l.bucket === group.id; });
            if (!items.length) {
                return;
            }
            var section = document.createElement('section');
            section.className = 'wam-acc-prefs__section';
            section.setAttribute('aria-labelledby', 'wam-pref-' + group.id);

            var h = document.createElement('h3');
            h.className = 'wam-acc-prefs__title';
            h.id = 'wam-pref-' + group.id;
            h.textContent = group.title;
            section.appendChild(h);

            var ul = document.createElement('ul');
            ul.className = 'wam-acc-prefs__list';

            items.forEach(function (item) {
                var li = document.createElement('li');
                var a = document.createElement('a');
                a.className = 'wam-acc-prefs__link';
                a.href = item.href;
                a.innerHTML =
                    prefsIcon(group.id) +
                    '<span class="wam-acc-prefs__text">' +
                    '<span class="wam-acc-prefs__label"></span>' +
                    (item.title
                        ? '<span class="wam-acc-prefs__desc"></span>'
                        : '') +
                    '</span>' +
                    '<svg class="wam-acc-prefs__chev wam-acc-icon" viewBox="0 0 24 24" aria-hidden="true">' +
                    '<path d="M9 18l6-6-6-6"/>' +
                    '</svg>';
                a.querySelector('.wam-acc-prefs__label').textContent = item.label;
                var desc = a.querySelector('.wam-acc-prefs__desc');
                if (desc) {
                    desc.textContent = item.title;
                }
                li.appendChild(a);
                ul.appendChild(li);
            });

            section.appendChild(ul);
            wrap.appendChild(section);
        });

        source.classList.add('wam-acc-prefs-source');
        source.classList.add('wam-acc-prefs-built');
        source.parentNode.insertBefore(wrap, source);
    }

    /* ---------------- Profili düzenle ---------------- */

    function findFitemById(id) {
        var el = document.getElementById(id);
        if (!el) {
            return null;
        }
        return el.closest('.fitem') || el.closest('[data-groupname]') || el;
    }

    function moveInto(container, node) {
        if (node && container && node.parentNode !== container) {
            container.appendChild(node);
        }
    }

    function enhanceEdit() {
        var page = document.getElementById('page-user-edit');
        if (page) {
            page.classList.add('wam-acc-ready');
        }
        document.body.classList.add('wam-acc-ready');

        var form = qs(document, 'form.mform');
        if (!form || form.dataset.wamEditReady === '1') {
            return;
        }
        form.dataset.wamEditReady = '1';

        function groupBox(title) {
            var box = document.createElement('section');
            box.className = 'wam-acc-edit-group';
            var t = document.createElement('h3');
            t.className = 'wam-acc-edit-group__title';
            t.textContent = title;
            box.appendChild(t);
            return box;
        }

        var pictureBox = groupBox(str('picture', 'Kullanıcı resmi'));
        var basicBox = groupBox(str('basic', 'Temel bilgiler'));
        var locationBox = groupBox(str('location', 'Konum'));
        var descBox = groupBox(str('description', 'Açıklama'));

        var advanced = document.createElement('details');
        advanced.className = 'wam-acc-advanced';
        var summary = document.createElement('summary');
        summary.textContent = str('advanced', 'Gelişmiş ayarlar');
        advanced.appendChild(summary);
        var advBody = document.createElement('div');
        advBody.className = 'wam-acc-advanced__body';
        advanced.appendChild(advBody);

        var rowNames = document.createElement('div');
        rowNames.className = 'wam-acc-edit-row wam-acc-edit-row--2';
        basicBox.appendChild(rowNames);

        var rowOrg = document.createElement('div');
        rowOrg.className = 'wam-acc-edit-row wam-acc-edit-row--2';
        basicBox.appendChild(rowOrg);

        var rowLoc = document.createElement('div');
        rowLoc.className = 'wam-acc-edit-row wam-acc-edit-row--2';
        locationBox.appendChild(rowLoc);

        // Fotoğraf alanı (filemanager) — imagealt gelişmişe.
        var pictureFs = document.getElementById('id_moodle_picture');
        if (pictureFs) {
            qsa(pictureFs, '.fitem').forEach(function (item) {
                if (item.id === 'fitem_id_imagealt' || qs(item, '#id_imagealt')) {
                    moveInto(advBody, item);
                } else {
                    moveInto(pictureBox, item);
                }
            });
        }

        moveInto(rowNames, findFitemById('fitem_id_firstname') || findFitemById('id_firstname'));
        moveInto(rowNames, findFitemById('fitem_id_lastname') || findFitemById('id_lastname'));
        moveInto(basicBox, findFitemById('fitem_id_email') || findFitemById('id_email'));
        moveInto(rowOrg, findFitemById('fitem_id_institution') || findFitemById('id_institution'));
        moveInto(rowOrg, findFitemById('fitem_id_department') || findFitemById('id_department'));

        moveInto(rowLoc, findFitemById('fitem_id_city') || findFitemById('id_city'));
        moveInto(rowLoc, findFitemById('fitem_id_country') || findFitemById('id_country'));

        var descItem = findFitemById('fitem_id_description_editor') || findFitemById('id_description_editor');
        moveInto(descBox, descItem);

        // Gelişmiş alanlar.
        [
            'fitem_id_maildisplay',
            'fitem_id_timezone',
            'fitem_id_lang',
            'fitem_id_imagealt',
            'id_moodle_additional_names',
            'id_moodle_interests',
            'fitem_id_idnumber',
            'fitem_id_phone1',
            'fitem_id_phone2',
            'fitem_id_address',
        ].forEach(function (id) {
            var node = document.getElementById(id);
            if (!node) {
                return;
            }
            if (node.tagName === 'FIELDSET') {
                qsa(node, '.fitem').forEach(function (item) {
                    moveInto(advBody, item);
                });
            } else {
                moveInto(advBody, node.closest('.fitem') || node);
            }
        });

        // Genel fieldset içindeki kalan öğeleri temel/gelişmiş olarak dağıt.
        var generalFs = document.getElementById('id_moodle');
        if (generalFs) {
            qsa(generalFs, '.fitem').forEach(function (item) {
                if (pictureBox.contains(item) || basicBox.contains(item) || locationBox.contains(item)
                        || descBox.contains(item) || advBody.contains(item)) {
                    return;
                }
                var id = item.id || '';
                if (
                    id.indexOf('maildisplay') !== -1 ||
                    id.indexOf('timezone') !== -1 ||
                    id.indexOf('lang') !== -1 ||
                    id.indexOf('theme') !== -1
                ) {
                    moveInto(advBody, item);
                }
            });
        }

        var buttonar = document.getElementById('fgroup_id_buttonar');
        var insertBefore = buttonar || null;

        [pictureBox, basicBox, locationBox, descBox, advanced].forEach(function (box) {
            var hasContent = box.querySelector('.fitem, .felement, .form-group, .wam-acc-edit-row .fitem');
            if (box === advanced) {
                hasContent = advBody.querySelector('.fitem, .felement, .form-group');
            }
            if (!hasContent) {
                return;
            }
            if (insertBefore) {
                form.insertBefore(box, insertBefore);
            } else {
                form.appendChild(box);
            }
        });

        // Hata varsa gelişmiş bölümü aç.
        if (advBody.querySelector('.is-invalid, .invalid-feedback:not(:empty), .error, .text-danger')) {
            advanced.open = true;
        }
    }

    /* ---------------- Başarı notları ---------------- */

    function enhanceGrades() {
        document.documentElement.classList.add('wam-acc-ready');
        document.body.classList.add('wam-acc-ready');
        var page = document.getElementById('page-grade-report-overview-index');
        if (page) {
            page.classList.add('wam-acc-ready');
        }

        var region = document.getElementById('region-main') || document;
        var tables = qsa(region, 'table#overview-grade, table.generaltable');
        var studentTable = null;
        var i;

        for (i = 0; i < tables.length; i++) {
            if (tables[i].id === 'overview-grade' || qs(tables[i], 'td.c1, th.c1')) {
                studentTable = tables[i];
                break;
            }
        }

        if (studentTable) {
            studentTable.classList.add('wam-acc-grades-table');
            var headers = qsa(studentTable, 'thead th, thead td');
            var headerTexts = headers.map(function (th) {
                return (th.textContent || '').replace(/\s+/g, ' ').trim();
            });

            qsa(studentTable, 'tbody tr').forEach(function (row) {
                var cells = qsa(row, 'td');
                cells.forEach(function (cell, idx) {
                    if (headerTexts[idx]) {
                        cell.setAttribute('data-label', headerTexts[idx]);
                    }
                });

                // Not sütunu: genelde c1. Yoksa ikinci hücre.
                var gradeCell = qs(row, 'td.c1') || cells[1];
                if (!gradeCell) {
                    return;
                }
                var raw = (gradeCell.textContent || '').replace(/\s+/g, ' ').trim();
                if (raw === '-') {
                    gradeCell.setAttribute('title', '-');
                    gradeCell.classList.add('wam-acc-grade-empty');
                    gradeCell.innerHTML = '';
                    var chip = document.createElement('span');
                    chip.className = 'wam-acc-grade-chip';
                    chip.textContent = str('nogradeyet', 'Henüz not girilmedi');
                    gradeCell.appendChild(chip);
                } else {
                    gradeCell.classList.add('wam-acc-grade-value');
                }
            });
        }

        var hasRows = studentTable && qsa(studentTable, 'tbody tr').length > 0;
        var notes = qsa(region, '.alert, .notifymessage, .notifyproblem');
        var onlyEmptyNotice = !hasRows && notes.some(function (n) {
            var t = (n.textContent || '').toLowerCase();
            return t.indexOf('rapor') !== -1 || t.indexOf('kayıt') !== -1 ||
                t.indexOf('enrol') !== -1 || t.indexOf('report') !== -1 ||
                t.indexOf('not') !== -1;
        });

        if (!hasRows) {
            if (qs(region, '.wam-acc-empty')) {
                return;
            }
            var empty = document.createElement('div');
            empty.className = 'wam-acc-empty wam-acc-section';
            var p = document.createElement('p');
            p.className = 'wam-acc-empty__text';
            p.textContent = str(
                'gradesempty',
                'Henüz notlandırılmış eğitimin yok. Notlar, eğitmen değerlendirme girdikçe burada görünür.'
            );
            empty.appendChild(p);
            var myUrl = cfg().mycoursesUrl || '';
            if (myUrl) {
                var a = document.createElement('a');
                a.className = 'wam-acc-btn wam-acc-btn--primary';
                a.href = myUrl;
                a.textContent = str('mycourses', 'Eğitimlerim');
                empty.appendChild(a);
            }
            var insertAfter = qs(region, 'h2, .page-header-headings, h3') || null;
            if (insertAfter && insertAfter.parentNode) {
                insertAfter.parentNode.insertBefore(empty, insertAfter.nextSibling);
            } else {
                region.appendChild(empty);
            }
            if (onlyEmptyNotice) {
                notes.forEach(function (n) {
                    n.classList.add('wam-acc-hidden');
                });
            }
        }
    }

    /* ---------------- Tarayıcı oturumları ---------------- */

    function enhanceSessions() {
        document.documentElement.classList.add('wam-acc-ready');
        document.body.classList.add('wam-acc-ready');
        var page = document.getElementById('page-report-usersessions-user');
        if (page) {
            page.classList.add('wam-acc-ready');
        }

        var region = document.getElementById('region-main') || document;
        var table = qs(region, 'table.generaltable, table');
        if (!table) {
            return;
        }
        table.classList.add('wam-acc-sessions-table');

        var thisSession = str('thissession', 'Bu oturum');
        var thisDevice = str('thisdevice', 'Bu cihaz');
        var headers = qsa(table, 'thead th, thead td');
        var headerTexts = headers.map(function (th) {
            return (th.textContent || '').replace(/\s+/g, ' ').trim();
        });

        var tbody = qs(table, 'tbody') || table;
        var rows = qsa(tbody, 'tr');
        var currentRow = null;

        rows.forEach(function (row) {
            var cells = qsa(row, 'td');
            cells.forEach(function (cell, idx) {
                if (headerTexts[idx]) {
                    cell.setAttribute('data-label', headerTexts[idx]);
                }
            });

            var lastAccess = cells[1];
            if (lastAccess) {
                var txt = (lastAccess.textContent || '').replace(/\s+/g, ' ').trim();
                if (txt === thisSession || txt.toLowerCase() === thisSession.toLowerCase()) {
                    currentRow = row;
                    row.classList.add('wam-acc-session-current');
                    if (!qs(lastAccess, '.wam-acc-session-badge')) {
                        lastAccess.textContent = '';
                        var badge = document.createElement('span');
                        badge.className = 'wam-acc-session-badge';
                        badge.textContent = thisDevice;
                        lastAccess.appendChild(badge);
                        var sr = document.createElement('span');
                        sr.className = 'visually-hidden';
                        sr.textContent = ' ' + thisSession;
                        lastAccess.appendChild(sr);
                    }
                }
            }

            var actionCell = cells[cells.length - 1];
            if (actionCell) {
                var link = qs(actionCell, 'a');
                if (link) {
                    link.classList.add('wam-acc-btn', 'wam-acc-btn--danger-outline', 'wam-acc-session-logout');
                }
            }
        });

        if (currentRow && currentRow.parentNode && currentRow !== currentRow.parentNode.firstElementChild) {
            currentRow.parentNode.insertBefore(currentRow, currentRow.parentNode.firstElementChild);
        }

        // "Diğer tüm oturumlardan çıkış yap" düğmesini ayrı eylem satırına al.
        var logoutAll =
            qs(region, 'form[action*="usersessions"] .singlebutton, .singlebutton form, .singlebutton');
        if (logoutAll && !qs(region, '.wam-acc-session-actions')) {
            var wrap = document.createElement('div');
            wrap.className = 'wam-acc-session-actions';
            var formEl = logoutAll.closest('form') || logoutAll;
            if (formEl.parentNode) {
                formEl.parentNode.insertBefore(wrap, formEl);
                wrap.appendChild(formEl);
            }
            var btn = qs(wrap, 'button, input[type="submit"], .btn');
            if (btn) {
                btn.classList.add('wam-acc-btn', 'wam-acc-btn--danger-outline');
            }
        }
    }

    function init() {
        var pagetype = (cfg().pagetype || document.body.id || '').replace(/^page-/, '');
        var bodyId = document.body.id || '';
        var path = (window.location && window.location.pathname) || '';

        if (
            bodyId === 'page-user-profile' ||
            pagetype === 'user-profile' ||
            /\/user\/profile\.php$/.test(path)
        ) {
            enhanceProfile();
        } else if (
            bodyId === 'page-user-edit' ||
            pagetype === 'user-edit' ||
            /\/user\/edit\.php$/.test(path)
        ) {
            enhanceEdit();
        } else if (
            bodyId === 'page-user-preferences' ||
            pagetype === 'user-preferences' ||
            /\/user\/preferences\.php$/.test(path)
        ) {
            enhancePreferences();
        } else if (
            bodyId === 'page-grade-report-overview-index' ||
            pagetype === 'grade-report-overview-index' ||
            /\/grade\/report\/overview\/index\.php$/.test(path)
        ) {
            enhanceGrades();
        } else if (
            bodyId === 'page-report-usersessions-user' ||
            pagetype === 'report-usersessions-user' ||
            /\/report\/usersessions\/user\.php$/.test(path)
        ) {
            enhanceSessions();
        } else if (
            bodyId === 'page-local-wamakademi-calendar' ||
            pagetype === 'local-wamakademi-calendar' ||
            /\/local\/wamakademi\/calendar\.php$/.test(path)
        ) {
            document.documentElement.classList.add('wam-acc-ready');
            document.body.classList.add('wam-acc-ready');
            if (document.getElementById('page-local-wamakademi-calendar')) {
                document.getElementById('page-local-wamakademi-calendar').classList.add('wam-acc-ready');
            }
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
