<?php
// This file is part of Moodle - http://moodle.org/
//
// WAM Akademi - Video ilerleme kaydı (eğitmen önizlemesinde yazma yok).

require_once('../../config.php');

require_login();

header('Content-Type: application/json; charset=utf-8');

global $DB, $USER;

if (!confirm_sesskey()) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Geçersiz oturum anahtarı.',
    ]);
    exit;
}

$cmid = required_param('cmid', PARAM_INT);
$currenttime = required_param('currenttime', PARAM_FLOAT);
$duration = required_param('duration', PARAM_FLOAT);
$rangesjson = optional_param('watchedranges', '[]', PARAM_RAW);

if ($cmid <= 0 || $duration <= 0) {
    echo json_encode([
        'success' => false,
        'message' => 'Geçersiz video bilgisi.',
    ]);
    exit;
}

$cm = get_coursemodule_from_id('resource', $cmid, 0, false, MUST_EXIST);
$course = get_course($cm->course);
require_course_login($course, true, $cm);

$modcontext = context_module::instance($cm->id);

if (!has_capability('mod/resource:view', $modcontext)) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Bu dersi görüntüleme yetkiniz yok.',
    ]);
    exit;
}

// Yetkili eğitmen / gizli aktivite görüntüleyici önizlemesinde ilerleme yazma.
$canpreview = has_capability('moodle/course:manageactivities', $modcontext)
    || has_capability('moodle/course:viewhiddenactivities', $modcontext)
    || is_siteadmin($USER);

if ($canpreview) {
    echo json_encode([
        'success' => true,
        'written' => false,
        'ispreview' => true,
        'cmid' => $cmid,
        'currenttime' => (float)$currenttime,
        'duration' => (float)$duration,
        'watchedseconds' => 0,
        'watchedranges' => [],
        'progress' => 0,
        'completed' => false,
    ]);
    exit;
}

$currenttime = max(0, min($currenttime, $duration));

$incomingranges = json_decode($rangesjson, true);
if (!is_array($incomingranges)) {
    $incomingranges = [];
}

/**
 * Gelen range verisini doğrula ve birleştir.
 *
 * @param array $ranges
 * @param float $duration
 * @return array
 */
function local_wamakademi_normalise_ranges(array $ranges, float $duration): array {
    $clean = [];

    foreach ($ranges as $range) {
        if (
            !is_array($range) ||
            count($range) !== 2 ||
            !is_numeric($range[0]) ||
            !is_numeric($range[1])
        ) {
            continue;
        }

        $start = (float)$range[0];
        $end = (float)$range[1];

        $start = max(0, min($start, $duration));
        $end = max(0, min($end, $duration));

        if ($end <= $start) {
            continue;
        }

        $clean[] = [$start, $end];
    }

    usort($clean, function ($a, $b) {
        return $a[0] <=> $b[0];
    });

    $merged = [];

    foreach ($clean as $range) {
        if (empty($merged)) {
            $merged[] = $range;
            continue;
        }

        $lastindex = count($merged) - 1;

        if ($range[0] <= $merged[$lastindex][1] + 0.5) {
            $merged[$lastindex][1] = max(
                $merged[$lastindex][1],
                $range[1]
            );
        } else {
            $merged[] = $range;
        }
    }

    return $merged;
}

/**
 * İzlenen saniye toplamı.
 *
 * @param array $ranges
 * @return float
 */
function local_wamakademi_count_watched_seconds(array $ranges): float {
    $seconds = 0;
    foreach ($ranges as $range) {
        $seconds += ($range[1] - $range[0]);
    }
    return $seconds;
}

$record = $DB->get_record(
    'local_wamakademi_progress',
    [
        'userid' => $USER->id,
        'cmid' => $cmid,
    ]
);

$existingranges = [];
if ($record && !empty($record->watchedranges)) {
    $decoded = json_decode($record->watchedranges, true);
    if (is_array($decoded)) {
        $existingranges = $decoded;
    }
}

$allranges = array_merge($existingranges, $incomingranges);
$mergedranges = local_wamakademi_normalise_ranges($allranges, $duration);
$watchedseconds = local_wamakademi_count_watched_seconds($mergedranges);
$watchedseconds = min($watchedseconds, $duration);

$progress = (int)round(($watchedseconds / $duration) * 100);
$progress = max(0, min(100, $progress));
$completed = ($progress >= 95) ? 1 : 0;
$fullywatched = ($progress >= 100);
$now = time();

if ($record) {
    if (empty($record->timecreated) && $watchedseconds > 0) {
        $record->timecreated = $now;
    }
    if (empty($record->timecompleted) && $fullywatched) {
        $record->timecompleted = $now;
    }

    $record->currenttime = $currenttime;
    $record->duration = $duration;
    $record->watchedseconds = $watchedseconds;
    $record->watchedranges = json_encode($mergedranges);
    $record->progress = $progress;
    $record->completed = $completed;
    $record->timemodified = $now;

    $DB->update_record('local_wamakademi_progress', $record);
} else {
    $record = new stdClass();
    $record->userid = $USER->id;
    $record->cmid = $cmid;
    $record->currenttime = $currenttime;
    $record->duration = $duration;
    $record->watchedseconds = $watchedseconds;
    $record->watchedranges = json_encode($mergedranges);
    $record->progress = $progress;
    $record->completed = $completed;
    $record->timecreated = $watchedseconds > 0 ? $now : 0;
    $record->timecompleted = $fullywatched ? $now : 0;
    $record->timemodified = $now;
    $record->id = $DB->insert_record('local_wamakademi_progress', $record);
}

echo json_encode([
    'success' => true,
    'written' => true,
    'ispreview' => false,
    'cmid' => $cmid,
    'currenttime' => (float)$record->currenttime,
    'duration' => (float)$record->duration,
    'watchedseconds' => (float)$record->watchedseconds,
    'watchedranges' => $mergedranges,
    'progress' => (int)$record->progress,
    'completed' => (bool)$record->completed,
]);
