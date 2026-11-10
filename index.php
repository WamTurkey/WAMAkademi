<?php

require_once('../../config.php');

require_login();

$PAGE->set_url(new moodle_url('/local/wamakademi/index.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('standard');
$PAGE->set_title('WAM Akademi');
$PAGE->set_heading('');

$PAGE->requires->css(new moodle_url('/local/wamakademi/styles.css'));

/*
 * Giriş yapan kullanıcının kayıtlı olduğu kursları getir.
 */
$usercourses = enrol_get_users_courses(
    $USER->id,
    true,
    'id,fullname,shortname,category,visible,startdate,enddate'
);

$courses = [];
$ongoingcount = 0;
$completedcount = 0;

foreach ($usercourses as $course) {

    if (empty($course->visible)) {
        continue;
    }

/*
 * Bu kursun içindeki resource/video derslerini
 * Moodle'ın gerçek sırasıyla getir.
 */
$modinfo = get_fast_modinfo(
    $course->id,
    $USER->id
);

$resources = [];

foreach ($modinfo->get_cms() as $coursecm) {

    if ($coursecm->modname !== 'resource') {
        continue;
    }

    if (!$coursecm->uservisible) {
        continue;
    }

    $resources[] = (object)[
        'cmid' => (int)$coursecm->id
    ];
}

    /*
     * Kullanıcının bu kurstaki video ilerlemelerini topla.
     */
    $totalprogress = 0;
    $trackedvideos = 0;

    $continuecmid = null;
    $continuecurrenttime = 0;

    foreach ($resources as $resource) {

        $record = $DB->get_record(
            'local_wamakademi_progress',
            [
                'userid' => $USER->id,
                'cmid' => $resource->cmid,
            ]
        );

        if (!$record) {
            continue;
        }

        $videoprogress = (int)$record->progress;

        $totalprogress += $videoprogress;
        $trackedvideos++;
    }

    /*
     * Şimdilik yalnızca tracker kaydı bulunan videolar üzerinden
     * kurs ilerlemesini hesaplıyoruz.
     */
    if ($trackedvideos > 0) {
        $progress = (int)round($totalprogress / $trackedvideos);
    } else {
        $progress = 0;
    }

    $progress = max(0, min(100, $progress));

    $iscompleted = ($progress >= 100);

    if ($iscompleted) {
        $completedcount++;
    } else {
        $ongoingcount++;
    }

    /*
     * Kategori adı.
     */
    $categoryname = $DB->get_field(
        'course_categories',
        'name',
        ['id' => $course->category]
    );

    if (!$categoryname) {
        $categoryname = 'Eğitim';
    }

/*
 * Kullanıcının sıradaki erişilebilir dersini bul.
 *
 * - Hiç başlamadıysa ilk ders.
 * - Bir ders yarım kaldıysa aynı ders.
 * - Ders tamamlandıysa sonraki ders.
 */
foreach ($resources as $resource) {

    $record = $DB->get_record(
        'local_wamakademi_progress',
        [
            'userid' => $USER->id,
            'cmid' => $resource->cmid,
        ]
    );

    /*
     * Hiç kaydı yoksa bu ders henüz başlamamıştır.
     */
    if (!$record) {
        $continuecmid = $resource->cmid;
        $continuecurrenttime = 0;
        break;
    }

    /*
     * Başlamış ama tamamlanmamışsa aynı derse devam et.
     */
    if (empty($record->completed)) {
        $continuecmid = $resource->cmid;
        $continuecurrenttime = (float)$record->currenttime;
        break;
    }
}

/*
 * Bütün dersler tamamlandıysa son dersi aç.
 */
if ($continuecmid === null && !empty($resources)) {

    $lastresource = end($resources);

    $continuecmid = $lastresource->cmid;
}

/*
 * Resource varsa doğrudan ders ekranına git.
 */
if ($continuecmid !== null) {

    $continueurl = (new moodle_url(
        '/mod/resource/view.php',
        ['id' => $continuecmid]
    ))->out(false);

} else {

    /*
     * Kursta hiç video/resource yoksa yedek olarak
     * Moodle kurs sayfasını aç.
     */
    $continueurl = (new moodle_url(
        '/course/view.php',
        ['id' => $course->id]
    ))->out(false);
}

    $courseimage = null;

$coursecontext = context_course::instance($course->id);

$fs = get_file_storage();

$files = $fs->get_area_files(
    $coursecontext->id,
    'course',
    'overviewfiles',
    false,
    'sortorder, id',
    false
);

foreach ($files as $file) {
    if ($file->is_valid_image()) {
$courseimage = moodle_url::make_pluginfile_url(
    $file->get_contextid(),
    'course',
    'overviewfiles',
    null,
    $file->get_filepath(),
    $file->get_filename()
)->out(false);
        break;
    }
}

    $courses[] = [
        'id' => $course->id,
        'fullname' => format_string($course->fullname),
        'shortname' => format_string($course->shortname),
        'category' => format_string($categoryname),

	'courseimage' => $courseimage,
        'hascourseimage' => !empty($courseimage),

        'progress' => $progress,
        'iscompleted' => $iscompleted,

        'continueurl' => $continueurl,
        'continuecmid' => $continuecmid,
        'continuecurrenttime' => $continuecurrenttime,
    ];
}

/*
 * ---------------------------------------------------------
 * HERO - KALDIĞIN YERDEN DEVAM ET
 * ---------------------------------------------------------
 */

$herocontinueurl = null;

foreach ($courses as $courseitem) {

    if (!empty($courseitem['iscompleted'])) {
        continue;
    }

    if ((int)$courseitem['progress'] <= 0) {
        continue;
    }

    if (!empty($courseitem['continueurl'])) {
        $herocontinueurl = $courseitem['continueurl'];
        break;
    }
}

if (empty($herocontinueurl)) {
    $herocontinueurl = (new moodle_url(
        '/local/wamakademi/mycourses.php'
    ))->out(false);
}

$templatecontext = [
    'firstname' => $USER->firstname,

    'courses' => array_values($courses),
    'hascourses' => !empty($courses),

    'ongoingcount' => $ongoingcount,
    'completedcount' => $completedcount,

    'certificatecount' => 0,
    'learninghours' => 0,

    'herocontinueurl' => $herocontinueurl,
];

echo $OUTPUT->header();

echo $OUTPUT->render_from_template(
    'local_wamakademi/dashboard',
    $templatecontext
);

echo $OUTPUT->footer();
