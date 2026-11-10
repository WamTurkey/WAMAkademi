<?php
// This file is part of Moodle - http://moodle.org/
//
// WAM Akademi - Ders listesi JSON (V4 konu gruplaması + sunucu doğrulamalı önizleme).

require_once('../../config.php');

require_login();

header('Content-Type: application/json; charset=utf-8');

global $DB, $USER;

$cmid = required_param('cmid', PARAM_INT);
// wampreview yalnızca bir önizleme talebidir; ekstra erişim vermez.
$wampreview = optional_param('wampreview', 0, PARAM_BOOL);

$cm = get_coursemodule_from_id('resource', $cmid, 0, false, MUST_EXIST);
$course = get_course($cm->course);

// Temel erişim: gizli/grup kısıtlı derste yetkisiz kullanıcı reddedilir.
require_course_login($course, true, $cm);

$modcontext = context_module::instance($cm->id);
$coursecontext = context_course::instance($course->id);

if (!has_capability('mod/resource:view', $modcontext)) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Bu dersi görüntüleme yetkiniz yok.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$canpreview = has_capability('moodle/course:manageactivities', $modcontext)
    || has_capability('moodle/course:viewhiddenactivities', $modcontext)
    || is_siteadmin($USER);

$modinfo = get_fast_modinfo($course->id, $USER->id);
$currentcm = $modinfo->get_cm($cmid);

$ispreview = $canpreview && (
    $wampreview
    || empty($currentcm->visible)
    || !$currentcm->uservisible
);

$userprogress = $DB->get_records(
    'local_wamakademi_progress',
    ['userid' => $USER->id]
);

$progressmap = [];
foreach ($userprogress as $progressrecord) {
    $progressmap[(int)$progressrecord->cmid] = $progressrecord;
}

/**
 * Tek bir resource CM için ders satırı oluştur.
 *
 * @param cm_info $coursecm
 * @param int $cmid
 * @param array $progressmap
 * @param int $sectionlessonindex
 * @param int $sectionid
 * @param int $sectionnum
 * @param string $sectionname
 * @param bool $forceactive
 * @return array
 */
function local_wamakademi_build_lesson_row(
    $coursecm,
    int $cmid,
    array $progressmap,
    int $sectionlessonindex,
    int $sectionid,
    int $sectionnum,
    string $sectionname,
    bool $forceactive = false
): array {
    $lessoncmid = (int)$coursecm->id;

    $progress = 0;
    $completed = false;
    $currenttime = 0.0;
    $duration = 0.0;

    if (isset($progressmap[$lessoncmid])) {
        $record = $progressmap[$lessoncmid];
        $progress = (int)$record->progress;
        $completed = (bool)$record->completed;
        $currenttime = (float)$record->currenttime;
        $duration = (float)$record->duration;
    }

    return [
        'cmid' => $lessoncmid,
        'name' => format_string(
            $coursecm->name,
            true,
            ['context' => $coursecm->context]
        ),
        'url' => (new moodle_url(
            '/mod/resource/view.php',
            ['id' => $lessoncmid]
        ))->out(false),
        'progress' => $progress,
        'completed' => $completed,
        'currenttime' => $currenttime,
        'duration' => $duration,
        'active' => $forceactive || ($lessoncmid === $cmid),
        'sectionid' => $sectionid,
        'sectionnum' => $sectionnum,
        'sectionname' => $sectionname,
        'sectionlessonindex' => $sectionlessonindex,
        'ispreview' => false,
    ];
}

$sectionsout = [];
$lessons = [];
$currentincluded = false;

foreach ($modinfo->get_section_info_all() as $sectioninfo) {
    $sectionnum = (int)$sectioninfo->section;
    $sectionid = (int)$sectioninfo->id;
    $sectionname = format_string(
        get_section_name($course, $sectioninfo),
        true,
        ['context' => $coursecontext]
    );
    if (trim($sectionname) === '') {
        $sectionname = ($sectionnum === 0) ? 'Genel' : ('Konu ' . $sectionnum);
    }

    $cmsinsection = $modinfo->get_cms();
    $sectionlessons = [];
    $sectionlessonindex = 0;

    foreach ($cmsinsection as $coursecm) {
        if ((int)$coursecm->sectionnum !== $sectionnum) {
            continue;
        }
        if ($coursecm->modname !== 'resource') {
            continue;
        }
        if (!empty($coursecm->deletioninprogress)) {
            continue;
        }
        // Kullanıcının göremediği derslerin başlık/URL'sini yanıta ekleme.
        if (!$coursecm->uservisible) {
            continue;
        }
        if (!has_capability('mod/resource:view', $coursecm->context)) {
            continue;
        }

        $sectionlessonindex++;
        $row = local_wamakademi_build_lesson_row(
            $coursecm,
            $cmid,
            $progressmap,
            $sectionlessonindex,
            $sectionid,
            $sectionnum,
            $sectionname
        );
        $sectionlessons[] = $row;
        $lessons[] = $row;
        if ($row['cmid'] === $cmid) {
            $currentincluded = true;
        }
    }

    // Yetkili eğitmen gizli taslağı önizliyorsa ve ders normal listede yoksa ekle.
    if (
        $ispreview
        && !$currentincluded
        && (int)$currentcm->sectionnum === $sectionnum
    ) {
        $sectionlessonindex++;
        $previewrow = local_wamakademi_build_lesson_row(
            $currentcm,
            $cmid,
            $progressmap,
            $sectionlessonindex,
            $sectionid,
            $sectionnum,
            $sectionname,
            true
        );
        $previewrow['name'] = $previewrow['name'] . ' (Gizli Taslak Önizleme)';
        $previewrow['ispreview'] = true;
        $previewrow['url'] = (new moodle_url(
            '/mod/resource/view.php',
            ['id' => $cmid, 'wampreview' => 1]
        ))->out(false);
        $sectionlessons[] = $previewrow;
        $lessons[] = $previewrow;
        $currentincluded = true;
    }

    // Yalnızca içinde görülebilir ders olan konuları döndür.
    if (!empty($sectionlessons)) {
        $sectionsout[] = [
            'sectionid' => $sectionid,
            'sectionnum' => $sectionnum,
            'sectionname' => $sectionname,
            'lessons' => $sectionlessons,
        ];
    }
}

// Video derslerde sıralı kilit kaldırıldı: yetkili öğrenciler yayımlanmış
// videoları istedikleri sırada açabilir. Kayıt, grup, gizli taslak ve
// mod/resource:view kontrolleri yukarıda korunur.
foreach ($lessons as &$lesson) {
    $lesson['locked'] = false;
}
unset($lesson);

foreach ($sectionsout as &$sec) {
    foreach ($sec['lessons'] as &$slesson) {
        $slesson['locked'] = false;
    }
    unset($slesson);
}
unset($sec);

$total = 0;
$completedcount = 0;
foreach ($lessons as $lesson) {
    if (!empty($lesson['ispreview'])) {
        continue;
    }
    $total++;
    if (!empty($lesson['completed'])) {
        $completedcount++;
    }
}

$courseprogress = 0;
if ($total > 0) {
    $courseprogress = (int)round(($completedcount / $total) * 100);
}

echo json_encode([
    'success' => true,
    'canpreview' => (bool)$canpreview,
    'ispreview' => (bool)$ispreview,
    'course' => [
        'id' => (int)$course->id,
        'name' => format_string($course->fullname, true, ['context' => $coursecontext]),
    ],
    'currentcmid' => $cmid,
    'total' => $total,
    'completed' => $completedcount,
    'progress' => $courseprogress,
    'sections' => $sectionsout,
    'lessons' => $lessons,
], JSON_UNESCAPED_UNICODE);
