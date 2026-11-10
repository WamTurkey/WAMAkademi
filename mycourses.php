<?php
// This file is part of Moodle - http://moodle.org/
//
// WAM Akademi - Eğitimlerim (My Courses) page.

require_once(__DIR__ . '/../../config.php');

require_login();

global $DB, $USER, $PAGE, $OUTPUT, $CFG;

$PAGE->set_url(new moodle_url('/local/wamakademi/mycourses.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title('Eğitimlerim');
$PAGE->set_heading('');
$PAGE->add_body_class('wam-my-courses-body');

$PAGE->requires->css('/local/wamakademi/styles.css');
$PAGE->requires->js(new moodle_url('/local/wamakademi/js/mycourses.js'));

/**
 * Build searchable text with Turkish-friendly folding (PHP side).
 *
 * @param string $text Raw text.
 * @return string Normalized lowercase text.
 */
function local_wamakademi_mycourses_normalize_search(string $text): string {
    $text = core_text::strtolower($text);
    $map = [
        'ı' => 'i', 'İ' => 'i', 'i' => 'i', 'I' => 'i',
        'ş' => 's', 'Ş' => 's',
        'ğ' => 'g', 'Ğ' => 'g',
        'ü' => 'u', 'Ü' => 'u',
        'ö' => 'o', 'Ö' => 'o',
        'ç' => 'c', 'Ç' => 'c',
    ];
    return strtr($text, $map);
}

/**
 * Build initials fallback from a course name.
 *
 * @param string $name Course fullname.
 * @return string Up to two uppercase initials.
 */
function local_wamakademi_mycourses_initials(string $name): string {
    $parts = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY);
    if (empty($parts)) {
        return '?';
    }
    $first = core_text::strtoupper(core_text::substr($parts[0], 0, 1));
    if (count($parts) === 1) {
        return $first;
    }
    $last = core_text::strtoupper(core_text::substr($parts[count($parts) - 1], 0, 1));
    return $first . $last;
}

$courses = enrol_get_users_courses(
    $USER->id,
    true,
    'id,fullname,shortname,category,visible'
);

$listedcourses = [];
$hero = null;

foreach ($courses as $course) {

    $coursecontext = context_course::instance($course->id);

    // Hide invisible courses unless the user can view hidden courses.
    if (empty($course->visible) && !has_capability('moodle/course:viewhiddencourses', $coursecontext)) {
        continue;
    }

    $modinfo = get_fast_modinfo($course->id, $USER->id);
    $resources = [];

    foreach ($modinfo->get_cms() as $coursecm) {
        if ($coursecm->modname !== 'resource') {
            continue;
        }
        if (!empty($coursecm->deletioninprogress)) {
            continue;
        }
        if (!$coursecm->uservisible) {
            continue;
        }
        if (!has_capability('mod/resource:view', context_module::instance($coursecm->id))) {
            continue;
        }
        $resources[] = $coursecm;
    }

    // No authorised lessons → do not list (same boundary as before).
    if (empty($resources)) {
        continue;
    }

    $hasstarted = false;
    $totalprogress = 0;
    $completedlessons = 0;
    $continuecmid = null;
    $continuecurrenttime = 0;
    $continuelessonname = '';
    $continuelessonindex = 0;
    $latestactivity = 0;

    $lessonindex = 0;
    foreach ($resources as $resource) {
        $lessonindex++;

        $record = $DB->get_record(
            'local_wamakademi_progress',
            [
                'userid' => $USER->id,
                'cmid' => $resource->id,
            ]
        );

        // Progress formula unchanged: average of every lesson's progress (missing = 0).
        $lessonprogress = 0;

        if ($record) {
            $lessonprogress = (float)$record->progress;

            if (
                (float)$record->watchedseconds > 0 ||
                (float)$record->progress > 0 ||
                (float)$record->currenttime > 0
            ) {
                $hasstarted = true;
            }

            if (!empty($record->completed)) {
                $completedlessons++;
            }

            if (!empty($record->timemodified) && (int)$record->timemodified > $latestactivity) {
                $latestactivity = (int)$record->timemodified;
            }
        }

        $totalprogress += $lessonprogress;

        if ($continuecmid === null) {
            if (!$record || empty($record->completed)) {
                $continuecmid = $resource->id;
                $continuelessonname = format_string($resource->name);
                $continuelessonindex = $lessonindex;
                if ($record) {
                    $continuecurrenttime = (float)$record->currenttime;
                }
            }
        }
    }

    $lessoncount = count($resources);

    // Progress formula unchanged.
    $courseprogress = $lessoncount > 0
        ? round($totalprogress / $lessoncount)
        : 0;

    if ($continuecmid === null && !empty($resources)) {
        $lastresource = end($resources);
        $continuecmid = $lastresource->id;
        $continuelessonname = format_string($lastresource->name);
        $continuelessonindex = $lessoncount;
    }

    $continueurl = new moodle_url(
        '/mod/resource/view.php',
        ['id' => $continuecmid]
    );

    $iscompleted = ($completedlessons === $lessoncount && $lessoncount > 0);

    if ($iscompleted) {
        $status = 'completed';
        $statuslabel = 'Tamamlandı';
        $actionlabel = 'Eğitimi incele';
    } else if ($hasstarted) {
        $status = 'active';
        $statuslabel = 'Devam ediyor';
        $actionlabel = 'Devam et';
    } else {
        $status = 'assigned';
        $statuslabel = 'Atandı';
        $actionlabel = 'Eğitime başla';
    }

    $courseimage = null;
    $courseinlist = new core_course_list_element($course);
    foreach ($courseinlist->get_course_overviewfiles() as $file) {
        if ($file->is_valid_image()) {
            $courseimage = moodle_url::make_pluginfile_url(
                $file->get_contextid(),
                $file->get_component(),
                $file->get_filearea(),
                null,
                $file->get_filepath(),
                $file->get_filename()
            )->out();
            break;
        }
    }

    $categoryname = '';
    if (!empty($course->category)) {
        $category = core_course_category::get($course->category, IGNORE_MISSING);
        if ($category) {
            $categoryname = $category->name;
        }
    }

    $fullname = format_string($course->fullname);
    $shortname = format_string($course->shortname);

    $searchtext = local_wamakademi_mycourses_normalize_search(
        $fullname . ' ' . $categoryname . ' ' . $shortname
    );

    $courseitem = [
        'id' => (int)$course->id,
        'fullname' => $fullname,
        'shortname' => $shortname,
        'category' => format_string($categoryname),
        'hascategory' => $categoryname !== '',
        'progress' => (int)$courseprogress,
        'completedlessons' => $completedlessons,
        'lessoncount' => $lessoncount,
        'continuelessonindex' => $continuelessonindex,
        'continuelessonname' => $continuelessonname,
        'continueurl' => $continueurl->out(false),
        'courseimage' => $courseimage,
        'hascourseimage' => !empty($courseimage),
        'initials' => local_wamakademi_mycourses_initials($fullname),
        'status' => $status,
        'statuslabel' => $statuslabel,
        'actionlabel' => $actionlabel,
        'iscompleted' => $iscompleted,
        'isactive' => ($status === 'active'),
        'isassigned' => ($status === 'assigned'),
        'searchtext' => $searchtext,
        'latestactivity' => $latestactivity,
    ];

    $listedcourses[] = $courseitem;

    // Hero: most recently modified in-progress course.
    if ($status === 'active') {
        if ($hero === null || $latestactivity > (int)$hero['latestactivity']) {
            $hero = $courseitem;
        }
    }
}

$coursecount = count($listedcourses);

$templatecontext = [
    'wwwroot' => $CFG->wwwroot,
    'hascourses' => $coursecount > 0,
    'coursecount' => $coursecount,
    'courses' => array_values($listedcourses),
    'hashero' => !empty($hero),
    'hero' => $hero ?: null,
    'catalogurl' => (new moodle_url('/local/wamakademi/catalog.php'))->out(false),
];

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_wamakademi/mycourses', $templatecontext);
echo $OUTPUT->footer();
