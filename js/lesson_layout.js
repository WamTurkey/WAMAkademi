(function () {
    'use strict';

    async function createLessonLayout() {
        if (document.body.dataset.wamLessonReady === '1') {
            return;
        }

        var main = document.querySelector('#region-main');
        var video = document.querySelector('.mediaplugin_videojs');
        var resource = document.querySelector('#resourceobject');
        var heading = document.querySelector('.page-header-headings h1');

        if (!main || !heading || (!video && !resource)) {
            setTimeout(createLessonLayout, 300);
            return;
        }

        document.body.dataset.wamLessonReady = '1';
        document.body.classList.add('wam-lesson-page');

        var lessonName = heading.textContent.trim();
        var params = new URLSearchParams(window.location.search);
        var cmid = parseInt(params.get('id'), 10);
        var wampreviewRequested = params.get('wampreview') === '1' ? '1' : '0';

        var courseData = null;

        try {
            var response = await fetch(
                M.cfg.wwwroot +
                    '/local/wamakademi/get_course_lessons.php?cmid=' +
                    encodeURIComponent(cmid) +
                    '&wampreview=' +
                    encodeURIComponent(wampreviewRequested),
                {
                    method: 'GET',
                    credentials: 'same-origin',
                }
            );
            courseData = await response.json();
        } catch (error) {
            console.error('WAM Akademi: Ders listesi alınamadı.', error);
        }

        if (!courseData || !courseData.success) {
            console.error('WAM Akademi: Geçerli ders verisi bulunamadı.');
            return;
        }

        var courseName = courseData.course.name;
        var totalLessons = courseData.total;
        var completedLessons = courseData.completed;

        function escapeHtml(value) {
            return String(value == null ? '' : value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }

        function formatDuration(seconds) {
            seconds = Number(seconds || 0);
            if (!seconds || seconds <= 0) {
                return '';
            }
            var minutes = Math.floor(seconds / 60);
            var remainingSeconds = Math.floor(seconds % 60);
            return (
                String(minutes).padStart(2, '0') +
                ':' +
                String(remainingSeconds).padStart(2, '0')
            );
        }

        function lessonStateClass(lesson) {
            if (lesson.active) {
                return 'is-active';
            }
            if (lesson.completed) {
                return 'is-completed';
            }
            return 'is-todo';
        }

        function renderLessonItem(lesson, globalIndex) {
            var localIndex = lesson.sectionlessonindex || globalIndex + 1;
            var duration = formatDuration(lesson.duration);
            var durationHtml = duration
                ? '<em class="wam-lp-item__duration">' +
                  escapeHtml(duration) +
                  '</em>'
                : '';

            return (
                '<a class="wam-lp-item ' +
                lessonStateClass(lesson) +
                '" href="' +
                escapeHtml(lesson.url) +
                '">' +
                '<span class="wam-lp-item__dot" aria-hidden="true"></span>' +
                '<span class="wam-lp-item__body">' +
                '<strong>' +
                escapeHtml(String(localIndex)) +
                '. ' +
                escapeHtml(lesson.name) +
                '</strong>' +
                durationHtml +
                '</span>' +
                '</a>'
            );
        }

        function buildLessonsHtml() {
            if (
                Array.isArray(courseData.sections) &&
                courseData.sections.length > 0
            ) {
                return courseData.sections
                    .map(function (section) {
                        var header =
                            '<div class="wam-lp-section">' +
                            '<h3 class="wam-lp-section__title">' +
                            escapeHtml(section.sectionname) +
                            '</h3>';
                        var items = (section.lessons || [])
                            .map(function (lesson, idx) {
                                return renderLessonItem(lesson, idx);
                            })
                            .join('');
                        return header + '<div class="wam-lp-rail">' + items + '</div></div>';
                    })
                    .join('');
            }

            var lessons = Array.isArray(courseData.lessons)
                ? courseData.lessons
                : [];
            return (
                '<div class="wam-lp-section"><div class="wam-lp-rail">' +
                lessons
                    .map(function (lesson, index) {
                        return renderLessonItem(lesson, index);
                    })
                    .join('') +
                '</div></div>'
            );
        }

        var shell = document.createElement('div');
        shell.className = 'wam-lp';
        shell.innerHTML =
            '<div class="wam-lp__layout">' +
            '<section class="wam-lp__main">' +
            '<header class="wam-lp__header">' +
            '<div class="wam-lp__crumb">' +
            '<a href="' +
            M.cfg.wwwroot +
            '/local/wamakademi/mycourses.php">Eğitimlerim</a>' +
            '<span>/</span>' +
            '<span>' +
            escapeHtml(courseName) +
            '</span>' +
            '</div>' +
            '<h1 class="wam-lp__title">' +
            escapeHtml(lessonName) +
            '</h1>' +
            '</header>' +
            '<div class="wam-lp__video"></div>' +
            '<div class="wam-lp__nav">' +
            '<button type="button" class="wam-lp__prev">← Önceki</button>' +
            '<button type="button" class="wam-lp__next">Sonraki →</button>' +
            '</div>' +
            '</section>' +
            '<aside class="wam-lp__side">' +
            '<div class="wam-lp__side-head">' +
            '<h2>Eğitim İçeriği</h2>' +
            '<p>' +
            completedLessons +
            ' / ' +
            totalLessons +
            ' ders tamamlandı</p>' +
            '</div>' +
            '<div class="wam-lp__list">' +
            buildLessonsHtml() +
            '</div>' +
            '</aside>' +
            '</div>';

        var videoSlot = shell.querySelector('.wam-lp__video');
        if (video) {
            videoSlot.appendChild(video);
        } else {
            videoSlot.innerHTML =
                '<iframe class="wam-lp__pptx" src="' +
                M.cfg.wwwroot +
                '/local/wamakademi/pptx_view.php?id=' +
                encodeURIComponent(cmid) +
                '" title="Sunum" allowfullscreen></iframe>';
        }

        // Moodle region-main içeriğini yeni kabukla değiştir; navbar korunur.
        main.innerHTML = '';
        main.appendChild(shell);

        var flatLessons = Array.isArray(courseData.lessons)
            ? courseData.lessons
            : [];
        var currentLessonIndex = flatLessons.findIndex(function (lesson) {
            return lesson.active;
        });

        var prevButton = shell.querySelector('.wam-lp__prev');
        var nextButton = shell.querySelector('.wam-lp__next');

        if (prevButton) {
            if (currentLessonIndex <= 0) {
                prevButton.disabled = true;
            } else {
                prevButton.addEventListener('click', function () {
                    var previousLesson = flatLessons[currentLessonIndex - 1];
                    if (previousLesson && previousLesson.url) {
                        window.location.href = previousLesson.url;
                    }
                });
            }
        }

        if (nextButton) {
            var nextLesson = flatLessons[currentLessonIndex + 1];
            if (!nextLesson) {
                nextButton.textContent = 'Eğitimi tamamla';
                nextButton.addEventListener('click', function () {
                    window.location.href =
                        M.cfg.wwwroot + '/local/wamakademi/mycourses.php';
                });
            } else {
                nextButton.addEventListener('click', function () {
                    window.location.href = nextLesson.url;
                });
            }
        }
    }

    createLessonLayout();
})();
