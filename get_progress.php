<?php

require_once('../../config.php');

require_login();

header('Content-Type: application/json; charset=utf-8');

global $DB, $USER;

$cmid = required_param('cmid', PARAM_INT);

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

$record = $DB->get_record(
    'local_wamakademi_progress',
    [
        'userid' => $USER->id,
        'cmid' => $cmid,
    ]
);

if (!$record) {
    echo json_encode([
        'success' => true,
        'currenttime' => 0,
        'progress' => 0,
        'completed' => false,
    ]);
    exit;
}

echo json_encode([
    'success' => true,
    'currenttime' => (float)$record->currenttime,
    'duration' => (float)$record->duration,
    'progress' => (int)$record->progress,
    'completed' => (bool)$record->completed,
]);
