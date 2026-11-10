<?php

require_once(__DIR__ . '/../../config.php');

require_login();

global $USER, $PAGE, $OUTPUT, $CFG;

$PAGE->set_url(new moodle_url('/local/wamakademi/calendar.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title('Takvim');
$PAGE->set_heading('');
$PAGE->add_body_class('wam-calendar-body');

$PAGE->requires->css('/local/wamakademi/styles.css');

/*
 * ---------------------------------------------------------
 * AY BİLGİSİ
 * ---------------------------------------------------------
 */

$monthparam = optional_param('month', '', PARAM_TEXT);

if (
    $monthparam &&
    preg_match('/^\d{4}-\d{2}$/', $monthparam)
) {
    [$year, $month] = array_map('intval', explode('-', $monthparam));
} else {
    $year = (int)date('Y');
    $month = (int)date('m');
}

$month = max(1, min(12, $month));

$monthstart = mktime(0, 0, 0, $month, 1, $year);

$daysinmonth = (int)date('t', $monthstart);

/*
 * PHP:
 * N = Pazartesi 1 ... Pazar 7
 */
$firstweekday = (int)date('N', $monthstart);

$previousmonth = date(
    'Y-m',
    strtotime('-1 month', $monthstart)
);

$nextmonth = date(
    'Y-m',
    strtotime('+1 month', $monthstart)
);

$currentmonth = date('Y-m');

$turkishmonths = [
    1 => 'Ocak',
    2 => 'Şubat',
    3 => 'Mart',
    4 => 'Nisan',
    5 => 'Mayıs',
    6 => 'Haziran',
    7 => 'Temmuz',
    8 => 'Ağustos',
    9 => 'Eylül',
    10 => 'Ekim',
    11 => 'Kasım',
    12 => 'Aralık',
];

$monthtitle = $turkishmonths[$month] . ' ' . $year;

/*
 * ---------------------------------------------------------
 * KURSLARI AL
 * Öğrenci bazlı gerçek başlangıç / bitiş tarihleri
 * ---------------------------------------------------------
 */

$courses = enrol_get_users_courses(
    $USER->id,
    true,
    'id,fullname,shortname,visible'
);

$events = [];
$allcourseevents = [];

foreach ($courses as $course) {

    /*
     * Moodle site kursunu gösterme.
     */
    if (
        (int)$course->id === SITEID ||
        (int)$course->id === 1 ||
        $course->shortname === 'WAM'
    ) {
        continue;
    }

    if (empty($course->visible)) {
        continue;
    }

    /*
     * -----------------------------------------------------
     * BU KURSUN DERSLERİNİ BUL
     * -----------------------------------------------------
     */

    $modinfo = get_fast_modinfo($course);

    $lessoncmids = [];

    foreach ($modinfo->get_cms() as $cm) {

        /*
         * WAM Akademi'de video dersleri resource olarak
         * kullanıyoruz.
         */
        if ($cm->modname !== 'resource') {
            continue;
        }

        if (!$cm->uservisible) {
            continue;
        }

        $lessoncmids[] = (int)$cm->id;
    }

    /*
     * Dersi olmayan kursu takvime alma.
     */
    if (empty($lessoncmids)) {
        continue;
    }

    /*
     * -----------------------------------------------------
     * ÖĞRENCİNİN BU KURSTAKİ İLERLEME KAYITLARI
     * -----------------------------------------------------
     */

    [$insql, $params] = $DB->get_in_or_equal(
        $lessoncmids,
        SQL_PARAMS_NAMED,
        'cmid'
    );

    $params['userid'] = $USER->id;

    $progressrecords = $DB->get_records_sql(
        "
        SELECT *
          FROM {local_wamakademi_progress}
         WHERE userid = :userid
           AND cmid {$insql}
        ",
        $params
    );

    /*
     * cmid üzerinden kolay erişim.
     */
    $progressbycmid = [];

    foreach ($progressrecords as $progressrecord) {
        $progressbycmid[(int)$progressrecord->cmid] = $progressrecord;
    }

    /*
     * -----------------------------------------------------
     * GERÇEK BAŞLANGIÇ TARİHİ
     *
     * Öğrencinin bu eğitimde herhangi bir videoyu ilk kez
     * gerçekten izlemeye başladığı tarih.
     * -----------------------------------------------------
     */

    $starttimestamps = [];

    foreach ($progressrecords as $progressrecord) {

        if (
            !empty($progressrecord->timecreated) &&
            (float)$progressrecord->watchedseconds > 0
        ) {
            $starttimestamps[] = (int)$progressrecord->timecreated;
        }
    }

    $realstartdate = !empty($starttimestamps)
        ? min($starttimestamps)
        : 0;

    /*
     * Eğitim hiç başlamadıysa takvimde hiç görünmesin.
     */
    if (empty($realstartdate)) {
        continue;
    }

    /*
     * -----------------------------------------------------
     * GERÇEK BİTİŞ TARİHİ
     *
     * Kurstaki TÜM dersler %100 olduğunda eğitim tamamlanır.
     * -----------------------------------------------------
     */

    $coursefullycompleted = true;
    $completiontimestamps = [];

    foreach ($lessoncmids as $lessoncmid) {

        if (!isset($progressbycmid[$lessoncmid])) {
            $coursefullycompleted = false;
            break;
        }

        $lessonprogress = $progressbycmid[$lessoncmid];

        /*
         * Bitiş tarihi için gerçekten %100 istiyoruz.
         */
        if ((int)$lessonprogress->progress < 100) {
            $coursefullycompleted = false;
            break;
        }

        if (empty($lessonprogress->timecompleted)) {
            $coursefullycompleted = false;
            break;
        }

        $completiontimestamps[] =
            (int)$lessonprogress->timecompleted;
    }

    $realenddate = 0;

    if (
        $coursefullycompleted &&
        count($completiontimestamps) === count($lessoncmids)
    ) {
        /*
         * Son tamamlanan dersin tarihi =
         * eğitimin tamamlanma tarihi.
         */
        $realenddate = max($completiontimestamps);
    }

    /*
     * -----------------------------------------------------
     * KURS GÖRSELİ
     * -----------------------------------------------------
     */

    $courseinlist = new core_course_list_element($course);

    $courseimage = null;

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

    $fullname = format_string($course->fullname);

    $shortlabel = $fullname;

    if (stripos($fullname, 'Excel') !== false) {

        if (stripos($fullname, 'İleri') !== false) {
            $shortlabel = 'Excel İleri Düzey';
        } else {
            $shortlabel = 'Excel Temel';
        }

    } elseif (stripos($fullname, 'PowerPoint') !== false) {

        $shortlabel = 'PowerPoint Temel';

    } elseif (stripos($fullname, 'Word') !== false) {

        $shortlabel = 'Word Temel';
    }

    $courseurl = (new moodle_url(
        '/course/view.php',
        ['id' => $course->id]
    ))->out(false);

    /*
     * HTML tarafını değiştirmemek için alan adlarını
     * startdate / enddate olarak bırakıyoruz.
     * Fakat artık Moodle tarihleri DEĞİL,
     * gerçek öğrenci tarihleri.
     */
    $courseevent = [
        'id' => (int)$course->id,
        'fullname' => $fullname,
        'shortlabel' => $shortlabel,
        'courseimage' => $courseimage,
        'courseurl' => $courseurl,
        'startdate' => $realstartdate,
        'enddate' => $realenddate,
    ];

    $allcourseevents[] = $courseevent;

    /*
     * GERÇEK BAŞLANGIÇ
     */
    $datekey = date('Y-m-d', $realstartdate);

    if (!isset($events[$datekey])) {
        $events[$datekey] = [];
    }

    $events[$datekey][] = [
        'type' => 'start',
        'course' => $courseevent,
    ];

    /*
     * GERÇEK BİTİŞ
     */
    if (!empty($realenddate)) {

        $datekey = date('Y-m-d', $realenddate);

        if (!isset($events[$datekey])) {
            $events[$datekey] = [];
        }

        $events[$datekey][] = [
            'type' => 'end',
            'course' => $courseevent,
        ];
    }
}

/*
 * ---------------------------------------------------------
 * SEÇİLİ GÜN
 * ---------------------------------------------------------
 */

$selecteddate = optional_param(
    'date',
    date('Y-m-d'),
    PARAM_TEXT
);

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selecteddate)) {
    $selecteddate = date('Y-m-d');
}

$selectedtimestamp = strtotime($selecteddate);

$selectedevents = $events[$selecteddate] ?? [];

$selectedstartcount = 0;

foreach ($selectedevents as $event) {
    if ($event['type'] === 'start') {
        $selectedstartcount++;
    }
}

$selectedday = (int)date('d', $selectedtimestamp);
$selectedmonthnumber = (int)date('m', $selectedtimestamp);
$selectedyear = (int)date('Y', $selectedtimestamp);

$selectedtitle =
    $selectedday . ' ' .
    $turkishmonths[$selectedmonthnumber] . ' ' .
    $selectedyear;

echo $OUTPUT->header();

?>

<div class="wam-calendar-v2-page">

    <!-- HERO -->

    <section class="wam-calendar-v2-hero">

        <div class="wam-calendar-v2-hero-content">

            <span class="wam-calendar-v2-badge">
                EĞİTİM TAKVİMİ
            </span>

            <h1>
                Öğrenme Planını Takip Et
            </h1>

            <p>
                Eğitim başlangıç ve bitiş tarihlerini tek ekranda görüntüle.
            </p>

        </div>

    </section>


    <!-- TOOLBAR -->

    <section class="wam-calendar-toolbar">

        <div class="wam-calendar-navigation">

            <a
                class="wam-calendar-arrow"
                href="?month=<?php echo s($previousmonth); ?>">
                ←
            </a>

            <strong>
                <?php echo s($monthtitle); ?>
            </strong>

            <a
                class="wam-calendar-arrow"
                href="?month=<?php echo s($nextmonth); ?>">
                →
            </a>

            <a
                class="wam-calendar-today"
                href="?month=<?php echo s($currentmonth); ?>">
                Bugün
            </a>

        </div>

        <div class="wam-calendar-view-switch">

            <button
                type="button"
                class="active"
                id="wamMonthViewButton">
                Ay
            </button>

            <button
                type="button"
                id="wamListViewButton">
                Liste
            </button>

        </div>

        <a
            href="<?php echo $CFG->wwwroot; ?>/local/wamakademi/catalog.php"
            class="wam-calendar-catalog-button">
            Eğitimleri Gör →
        </a>

    </section>


    <!-- ANA İÇERİK -->

    <div class="wam-calendar-layout">

        <!-- AYLIK TAKVİM -->

        <section
            class="wam-calendar-month-card"
            id="wamMonthView">

            <div class="wam-calendar-weekdays">

                <span>Pzt</span>
                <span>Sal</span>
                <span>Çar</span>
                <span>Per</span>
                <span>Cum</span>
                <span>Cmt</span>
                <span>Paz</span>

            </div>

            <div class="wam-calendar-grid">

                <?php

                /*
                 * İlk günden önceki boş kutular
                 */
                for ($blank = 1; $blank < $firstweekday; $blank++) {
                    echo '<div class="wam-calendar-day empty"></div>';
                }

                for ($day = 1; $day <= $daysinmonth; $day++) {

                    $datekey = sprintf(
                        '%04d-%02d-%02d',
                        $year,
                        $month,
                        $day
                    );

                    $dayevents = $events[$datekey] ?? [];

                    $istoday =
                        $datekey === date('Y-m-d');

                    $isselected =
                        $datekey === $selecteddate;

                    $classes = 'wam-calendar-day';

                    if ($istoday) {
                        $classes .= ' today';
                    }

                    if ($isselected) {
                        $classes .= ' selected';
                    }

                    ?>

                    <a
                        href="?month=<?php
                            echo sprintf('%04d-%02d', $year, $month);
                        ?>&date=<?php echo s($datekey); ?>"
                        class="<?php echo $classes; ?>">

                        <span class="wam-calendar-day-number">
                            <?php echo $day; ?>
                        </span>

                        <div class="wam-calendar-day-events">

                            <?php foreach (array_slice($dayevents, 0, 3) as $event): ?>

                                <?php

                                $labelclass =
                                    $event['type'] === 'end'
                                    ? 'end'
                                    : 'start';

                                $fullname =
                                    $event['course']['fullname'];

                                if (stripos($fullname, 'Excel') !== false) {
                                    $labelclass .= ' excel';
                                }

                                if (stripos($fullname, 'PowerPoint') !== false) {
                                    $labelclass .= ' powerpoint';
                                }

                                if (stripos($fullname, 'Word') !== false) {
                                    $labelclass .= ' word';
                                }

                                ?>

                                <span class="wam-calendar-event <?php echo $labelclass; ?>">

                                    <?php
                                    echo s(
                                        $event['course']['shortlabel']
                                    );
                                    ?>

                                </span>

                            <?php endforeach; ?>

                        </div>

                    </a>

                    <?php
                }

                ?>

            </div>

            <div class="wam-calendar-legend">

                <span>
                    <i class="start"></i>
                    Başlangıç
                </span>

                <span>
                    <i class="end"></i>
                    Bitiş
                </span>

            </div>

        </section>


        <!-- LİSTE GÖRÜNÜMÜ -->

        <section
            class="wam-calendar-list-card"
            id="wamListView"
            style="display:none;">

            <?php foreach ($allcourseevents as $course): ?>

                <div class="wam-calendar-list-item">

                    <strong>
                        <?php echo s($course['fullname']); ?>
                    </strong>

                    <span>
                        Başlangıç:
                        <?php
                        echo !empty($course['startdate'])
                            ? userdate($course['startdate'], '%d.%m.%Y')
                            : '-';
                        ?>
                    </span>

                    <span>
                        Bitiş:
                        <?php
                        echo !empty($course['enddate'])
                            ? userdate($course['enddate'], '%d.%m.%Y')
                            : '-';
                        ?>
                    </span>

                    <a href="<?php echo s($course['courseurl']); ?>">
                        Eğitime Git →
                    </a>

                </div>

            <?php endforeach; ?>

        </section>


        <!-- SAĞ PANEL -->

        <aside class="wam-calendar-day-panel">

            <h2>
                <?php echo s($selectedtitle); ?>
            </h2>

            <p class="wam-calendar-day-summary">

                <?php if ($selectedstartcount > 0): ?>

                    <?php echo $selectedstartcount; ?>
                    eğitim başlıyor

                <?php elseif (!empty($selectedevents)): ?>

                    <?php echo count($selectedevents); ?>
                    takvim kaydı

                <?php else: ?>

                    Bu tarihte planlanmış eğitim yok.

                <?php endif; ?>

            </p>


            <div class="wam-calendar-day-courses">

                <?php foreach ($selectedevents as $event): ?>

                    <?php $course = $event['course']; ?>

                    <article class="wam-calendar-day-course">

                        <?php if (!empty($course['courseimage'])): ?>

                            <img
                                src="<?php echo s($course['courseimage']); ?>"
                                alt=""
                            >

                        <?php else: ?>

                            <div class="wam-calendar-course-fallback">
                                WAM
                            </div>

                        <?php endif; ?>

                        <div>

                            <h3>
                                <?php echo s($course['fullname']); ?>
                            </h3>

                            <p>

                                <?php if ($event['type'] === 'start'): ?>

                                    Başlangıç:
                                    <?php
                                    echo userdate(
                                        $course['startdate'],
                                        '%d %B %Y'
                                    );
                                    ?>

                                <?php else: ?>

                                    Bitiş:
                                    <?php
                                    echo userdate(
                                        $course['enddate'],
                                        '%d %B %Y'
                                    );
                                    ?>

                                <?php endif; ?>

                            </p>

                        </div>

                        <a href="<?php echo s($course['courseurl']); ?>">
                            Eğitime Git →
                        </a>

                    </article>

                <?php endforeach; ?>

            </div>

        </aside>

    </div>

</div>


<script>

document.addEventListener('DOMContentLoaded', function () {

    const monthButton =
        document.getElementById('wamMonthViewButton');

    const listButton =
        document.getElementById('wamListViewButton');

    const monthView =
        document.getElementById('wamMonthView');

    const listView =
        document.getElementById('wamListView');

    monthButton.addEventListener('click', function () {

        monthView.style.display = '';
        listView.style.display = 'none';

        monthButton.classList.add('active');
        listButton.classList.remove('active');

    });

    listButton.addEventListener('click', function () {

        monthView.style.display = 'none';
        listView.style.display = '';

        listButton.classList.add('active');
        monthButton.classList.remove('active');

    });

});

</script>


<?php

echo $OUTPUT->footer();
