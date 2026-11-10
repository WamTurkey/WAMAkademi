(function () {
    'use strict';

    console.log('WAM Tracker: Başlatılıyor...');

    const params = new URLSearchParams(window.location.search);
    const cmid = parseInt(params.get('id'), 10);

    if (!cmid) {
        console.log('WAM Tracker: CMID bulunamadı.');
        return;
    }

    if (typeof M === 'undefined' || !M.cfg || !M.cfg.sesskey) {
        console.log('WAM Tracker: Moodle sesskey bulunamadı.');
        return;
    }

    /*
     * wampreview URL parametresi tek başına güvenilir değildir.
     * Sunucu get_course_lessons üzerinden canpreview / ispreview doğrular.
     * Öğrenci ?wampreview=1 eklese bile sunucu canpreview=false döner ve takip sürer.
     */
    const wampreviewRequested = params.get('wampreview') === '1' ? '1' : '0';

    function startAfterPreviewCheck() {
        const lessonsUrl =
            M.cfg.wwwroot +
            '/local/wamakademi/get_course_lessons.php?cmid=' +
            encodeURIComponent(cmid) +
            '&wampreview=' +
            encodeURIComponent(wampreviewRequested);

        fetch(lessonsUrl, {
            method: 'GET',
            credentials: 'same-origin',
        })
            .then(function (response) {
                return response.json();
            })
            .then(function (data) {
                if (data && data.success && (data.ispreview === true || data.canpreview === true)) {
                    console.log(
                        'WAM Tracker: Sunucu önizleme/yönetim yetkisi doğruladı; öğrenci ilerleme kaydı oluşturulmayacak.'
                    );
                    return;
                }
                waitForVideo();
            })
            .catch(function (error) {
                console.error(
                    'WAM Tracker: Önizleme yetkisi kontrolü başarısız; takip başlatılacak.',
                    error
                );
                waitForVideo();
            });
    }

    let attempts = 0;
    const maxAttempts = 40;

    function waitForVideo() {
        const video = document.querySelector('video.vjs-tech');

        if (video) {
            console.log('WAM Tracker: Video bulundu. CMID:', cmid);
            initialiseTracker(video);
            return;
        }

        attempts++;

        if (attempts >= maxAttempts) {
            console.log('WAM Tracker: Video bulunamadı. Bekleme süresi doldu.');
            return;
        }

        setTimeout(waitForVideo, 500);
    }

    function initialiseTracker(video) {
        let saveTimer = null;

        // Bu oturumda gerçekten izlenen aralıklar.
        let watchedRanges = [];

        let segmentStart = null;
        let lastVideoTime = null;

        function closeCurrentSegment() {
            if (
                segmentStart !== null &&
                lastVideoTime !== null &&
                lastVideoTime > segmentStart
            ) {
                watchedRanges.push([
                    Number(segmentStart.toFixed(2)),
                    Number(lastVideoTime.toFixed(2)),
                ]);
            }

            segmentStart = null;
            lastVideoTime = null;
        }

        function trackPlayback() {
            if (video.paused || video.ended) {
                return;
            }

            const current = video.currentTime;

            if (segmentStart === null) {
                segmentStart = current;
                lastVideoTime = current;
                return;
            }

            /*
             * Normal oynatma sırasında zaman küçük adımlarla ilerler.
             * Büyük sıçrama varsa kullanıcı seek yapmıştır.
             */
            const jump = current - lastVideoTime;

            if (jump < -1 || jump > 3) {
                closeCurrentSegment();

                segmentStart = current;
                lastVideoTime = current;

                return;
            }

            lastVideoTime = current;
        }

        function saveProgress(force) {
            if (force === undefined) {
                force = false;
            }

            if (!video.duration || !Number.isFinite(video.duration)) {
                return;
            }

            if (!video.paused && !video.ended) {
                trackPlayback();
            }

            if (force) {
                closeCurrentSegment();
            }

            if (!force && watchedRanges.length === 0) {
                return;
            }

            const body = new URLSearchParams();

            body.append('sesskey', M.cfg.sesskey);
            body.append('cmid', cmid);
            body.append('currenttime', video.currentTime.toFixed(2));
            body.append('duration', video.duration.toFixed(2));
            body.append('watchedranges', JSON.stringify(watchedRanges));

            // Gönderilecek aralıkları sakla.
            const sentRanges = watchedRanges.slice();

            // Aynı aralıkları tekrar tekrar göndermeyelim.
            watchedRanges = [];

            fetch(M.cfg.wwwroot + '/local/wamakademi/save_progress.php', {
                method: 'POST',
                headers: {
                    'Content-Type':
                        'application/x-www-form-urlencoded; charset=UTF-8',
                },
                credentials: 'same-origin',
                body: body.toString(),
            })
                .then(function (response) {
                    return response.json();
                })
                .then(function (data) {
                    if (!data.success) {
                        console.error(
                            'WAM Tracker kayıt başarısız:',
                            data.message
                        );

                        watchedRanges = sentRanges.concat(watchedRanges);
                        return;
                    }

                    if (data.ispreview === true || data.written === false) {
                        console.log(
                            'WAM Tracker: Sunucu önizleme yanıtı; ilerleme yazılmadı.'
                        );
                        return;
                    }

                    console.log(
                        'WAM Tracker: Kaydedildi →',
                        data.progress + '%',
                        'Konum:',
                        Math.round(data.currenttime) +
                            '/' +
                            Math.round(data.duration) +
                            ' sn',
                        'Benzersiz izlenen:',
                        Math.round(data.watchedseconds) + ' sn'
                    );
                })
                .catch(function (error) {
                    console.error('WAM Tracker kayıt hatası:', error);

                    watchedRanges = sentRanges.concat(watchedRanges);
                });
        }

        video.addEventListener('play', function () {
            console.log('WAM Tracker: Video oynatılıyor.');

            segmentStart = video.currentTime;
            lastVideoTime = video.currentTime;

            if (saveTimer) {
                clearInterval(saveTimer);
            }

            saveTimer = setInterval(function () {
                if (!video.paused && !video.ended) {
                    trackPlayback();
                    saveProgress();
                }
            }, 5000);
        });

        video.addEventListener('timeupdate', function () {
            trackPlayback();
        });

        video.addEventListener('seeking', function () {
            closeCurrentSegment();
        });

        video.addEventListener('seeked', function () {
            if (!video.paused) {
                segmentStart = video.currentTime;
                lastVideoTime = video.currentTime;
            }
        });

        video.addEventListener('pause', function () {
            trackPlayback();
            closeCurrentSegment();

            console.log(
                'WAM Tracker: Video durduruldu:',
                Math.round(video.currentTime),
                'sn'
            );

            saveProgress(true);

            if (saveTimer) {
                clearInterval(saveTimer);
                saveTimer = null;
            }
        });

        video.addEventListener('ended', function () {
            trackPlayback();
            closeCurrentSegment();

            console.log('WAM Tracker: Video tamamlandı.');

            saveProgress(true);

            if (saveTimer) {
                clearInterval(saveTimer);
                saveTimer = null;
            }
        });

        window.addEventListener('beforeunload', function () {
            if (!video.paused && !video.ended) {
                trackPlayback();
                closeCurrentSegment();
            }

            saveProgress(true);
        });

        /**
         * Öğrencinin daha önce kaldığı video konumunu yükle.
         */
        function loadSavedPosition() {
            fetch(
                M.cfg.wwwroot +
                    '/local/wamakademi/get_progress.php?cmid=' +
                    encodeURIComponent(cmid),
                {
                    method: 'GET',
                    credentials: 'same-origin',
                }
            )
                .then(function (response) {
                    return response.json();
                })
                .then(function (data) {
                    if (!data.success) {
                        return;
                    }

                    const savedTime = Number(data.currenttime || 0);

                    if (savedTime <= 0) {
                        console.log('WAM Tracker: Kayıtlı konum yok.');
                        return;
                    }

                    /*
                     * Video süresi henüz hazır değilse metadata yüklenmesini bekle.
                     */
                    function restorePosition() {
                        if (
                            !video.duration ||
                            !Number.isFinite(video.duration)
                        ) {
                            return;
                        }

                        /*
                         * Video bitmişse son saniyeye göndermeyelim.
                         */
                        const safeTime = Math.min(
                            savedTime,
                            Math.max(0, video.duration - 2)
                        );

                        video.currentTime = safeTime;

                        console.log(
                            'WAM Tracker: Kaldığı yer yüklendi:',
                            Math.round(safeTime),
                            'sn'
                        );
                    }

                    if (
                        video.readyState >= 1 &&
                        video.duration &&
                        Number.isFinite(video.duration)
                    ) {
                        restorePosition();
                    } else {
                        video.addEventListener(
                            'loadedmetadata',
                            restorePosition,
                            { once: true }
                        );
                    }
                })
                .catch(function (error) {
                    console.error(
                        'WAM Tracker konum yükleme hatası:',
                        error
                    );
                });
        }

        loadSavedPosition();

        console.log('WAM Tracker: Hazır.');
    }

    startAfterPreviewCheck();
})();
