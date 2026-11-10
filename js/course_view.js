(function () {
    'use strict';

    if (document.body.dataset.wamCourseViewReady === '1') {
        return;
    }

    var KEEP_UPPER = {
        ISG: 1,
        PDF: 1,
        MP4: 1,
        PPTX: 1,
        PPT: 1,
        DOC: 1,
        DOCX: 1,
        XLS: 1,
        XLSX: 1,
        HTML: 1,
        CSS: 1,
        JS: 1,
        API: 1,
        WAM: 1,
        IT: 1,
        HR: 1,
        FAQ: 1,
        QR: 1,
        URL: 1,
        ID: 1,
        AI: 1,
        UI: 1,
        UX: 1,
    };

    function visibleText(node) {
        if (!node) {
            return '';
        }
        var clone = node.cloneNode(true);
        clone.querySelectorAll('.accesshide, .sr-only, .visually-hidden').forEach(function (el) {
            el.remove();
        });
        return (clone.textContent || '').replace(/\s+/g, ' ').trim();
    }

    function isAllCaps(text) {
        var letters = text.replace(/[^A-Za-zÇĞİÖŞÜçğıöşü]/g, '');
        if (letters.length < 8) {
            return false;
        }
        return letters === letters.toLocaleUpperCase('tr-TR');
    }

    function titleCaseTr(text) {
        return text
            .split(/(\s+)/)
            .map(function (part) {
                if (!part.trim()) {
                    return part;
                }
                var plain = part.replace(/[^\wÇĞİÖŞÜçğıöşü]/gi, '');
                var upper = plain.toLocaleUpperCase('tr-TR');
                if (KEEP_UPPER[upper] || /\d/.test(plain)) {
                    return part;
                }
                var lower = part.toLocaleLowerCase('tr-TR');
                if (!lower) {
                    return part;
                }
                return (
                    lower.charAt(0).toLocaleUpperCase('tr-TR') +
                    lower.slice(1)
                );
            })
            .join('');
    }

    function setVisibleText(node, nextText) {
        var textNodes = [];
        var walker = document.createTreeWalker(node, NodeFilter.SHOW_TEXT, null);
        var current;
        while ((current = walker.nextNode())) {
            if (current.parentElement && current.parentElement.closest('.accesshide, .sr-only, .visually-hidden')) {
                continue;
            }
            if (current.nodeValue && current.nodeValue.trim()) {
                textNodes.push(current);
            }
        }
        if (textNodes.length === 1) {
            textNodes[0].nodeValue = nextText;
            return;
        }
        if (textNodes.length > 1) {
            textNodes[0].nodeValue = nextText;
            for (var i = 1; i < textNodes.length; i++) {
                textNodes[i].nodeValue = '';
            }
        }
    }

    function softenName(node) {
        if (!node || node.dataset.wamTitleSoftened === '1') {
            return;
        }
        var text = visibleText(node);
        if (!text || !isAllCaps(text)) {
            return;
        }
        node.dataset.wamTitleSoftened = '1';
        if (!node.getAttribute('title')) {
            node.setAttribute('title', text);
        }
        setVisibleText(node, titleCaseTr(text));
    }

    function run() {
        document.body.dataset.wamCourseViewReady = '1';
        document.body.classList.add('wam-course-view');

        var selectors = [
            '.activity-item .activityname .instancename',
            '.activity-item .activityname a',
            '.sectionname',
            '#page-header .page-header-headings h1',
            '.page-header-headings h1',
        ];

        selectors.forEach(function (selector) {
            document.querySelectorAll(selector).forEach(softenName);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', run);
    } else {
        run();
    }
})();
