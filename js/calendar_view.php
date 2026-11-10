<?php
/**
 * WAM Akademi — Takvim (Faz 2)
 * Eğitim başlangıç/bitiş (WAM progress) + Moodle etkinlikleri/son tarihler.
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/calendar/lib.php');

require_login();

global $USER, $PAGE, $OUTPUT, $CFG, $DB;

$PAGE->set_url(new moodle_url('/local/wamakademi/js/calendar_view.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_pagetype('local-wamakademi-calendar');
$PAGE->set_title('Takvim');
$PAGE->set_heading('');
$PAGE->add_body_class('wam-calendar-body');
$PAGE->requires->css('/local/wamakademi/styles.css');

/*
 * ---------------------------------------------------------
 * AY BİLGİSİ (kullanıcı saat dilimi)
 * ---------------------------------------------------------
 */

$monthparam = optional_param('month', '', PARAM_TEXT);
$nowinfo = usergetdate(time());

if ($monthparam && preg_match('/^\d{4}-\d{2}$/', $monthparam)) {
    [$year, $month] = array_map('intval', explode('-', $monthparam));
} else {
    $year = (int)$nowinfo['year'];
    $month = (int)$nowinfo['mon'];
}

$month = max(1, min(12, $month));
$monthstart = make_timestamp($year, $month, 1, 0, 0, 0);

$caltype = \core_calendar\type_factory::get_calendar_instance();
$daysinmonth = (int)$caltype->get_num_days_in_month($year, $month);

$firstinfo = usergetdate($monthstart);
$firstweekday = (int)$firstinfo['wday']; // 0=Pazar ... 6=Cumartesi
if ($firstweekday === 0) {
    $firstweekday = 7; // Pazartesi=1 ... Pazar=7
}

if ($month === 1) {
    $previousmonth = sprintf('%04d-12', $year - 1);
} else {
    $previousmonth = sprintf('%04d-%02d', $year, $month - 1);
}

if ($month === 12) {
    $nextmonth = sprintf('%04d-01', $year + 1);
} else {
    $nextmonth = sprintf('%04d-%02d', $year, $month + 1);
}

$currentmonth = sprintf('%04d-%02d', (int)$nowinfo['year'], (int)$nowinfo['mon']);
$todaykey = sprintf('%04d-%02d-%02d', (int)$nowinfo['year'], (int)$nowinfo['mon'], (int)$nowinfo['mday']);

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
 * WAM EĞİTİM OLAYLARI (progress tabanlı)
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

    $modinfo = get_fast_modinfo($course);
    $lessoncmids = [];

    foreach ($modinfo->get_cms() as $cm) {
        if ($cm->modname !== 'resource') {
            continue;
        }
        if (!$cm->uservisible) {
            continue;
        }
        $lessoncmids[] = (int)$cm->id;
    }

    if (empty($lessoncmids)) {
        continue;
    }

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

    $progressbycmid = [];
    foreach ($progressrecords as $progressrecord) {
        $progressbycmid[(int)$progressrecord->cmid] = $progressrecord;
    }

    $starttimestamps = [];
    foreach ($progressrecords as $progressrecord) {
        if (
            !empty($progressrecord->timecreated) &&
            (float)$progressrecord->watchedseconds > 0
        ) {
            $starttimestamps[] = (int)$progressrecord->timecreated;
        }
    }

    $realstartdate = !empty($starttimestamps) ? min($starttimestamps) : 0;
    if (empty($realstartdate)) {
        continue;
    }

    $coursefullycompleted = true;
    $completiontimestamps = [];

    foreach ($lessoncmids as $lessoncmid) {
        if (!isset($progressbycmid[$lessoncmid])) {
            $coursefullycompleted = false;
            break;
        }
        $lessonprogress = $progressbycmid[$lessoncmid];
        if ((int)$lessonprogress->progress < 100) {
            $coursefullycompleted = false;
            break;
        }
        if (empty($lessonprogress->timecompleted)) {
            $coursefullycompleted = false;
            break;
        }
        $completiontimestamps[] = (int)$lessonprogress->timecompleted;
    }

    $realenddate = 0;
    if (
        $coursefullycompleted &&
        count($completiontimestamps) === count($lessoncmids)
    ) {
        $realenddate = max($completiontimestamps);
    }

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
    } else if (stripos($fullname, 'PowerPoint') !== false) {
        $shortlabel = 'PowerPoint Temel';
    } else if (stripos($fullname, 'Word') !== false) {
        $shortlabel = 'Word Temel';
    }

    $courseurl = (new moodle_url(
        '/course/view.php',
        ['id' => $course->id]
    ))->out(false);

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

    $datekey = userdate($realstartdate, '%Y-%m-%d');
    if (!isset($events[$datekey])) {
        $events[$datekey] = [];
    }
    $events[$datekey][] = [
        'type' => 'start',
        'course' => $courseevent,
    ];

    if (!empty($realenddate)) {
        $datekey = userdate($realenddate, '%Y-%m-%d');
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
 * MOODLE ETKİNLİKLERİ (calendar_get_view — yetki + TZ)
 * ---------------------------------------------------------
 */

$moodlebyday = [];
$moodlelist = [];
$moodleok = false;

try {
    $calendar = \calendar_information::create($monthstart, SITEID);
    [$viewdata] = calendar_get_view($calendar, 'month', false);
    $moodleok = true;

    if (!empty($viewdata->weeks) && is_array($viewdata->weeks)) {
        foreach ($viewdata->weeks as $week) {
            if (empty($week->days) || !is_array($week->days)) {
                continue;
            }
            foreach ($week->days as $day) {
                if (empty($day->events) || !is_array($day->events)) {
                    continue;
                }
                // Ay dışı dolgu günlerini atla.
                if (!empty($day->year) && !empty($day->mday)) {
                    $dayyear = (int)$day->year;
                    // month from timestamp preferred.
                }
                $dayts = !empty($day->timestamp) ? (int)$day->timestamp : 0;
                if (!$dayts && !empty($day->seconds)) {
                    // Fallback: day_exporter may expose seconds as unix? Usually timestamp.
                    $dayts = 0;
                }
                if (!$dayts) {
                    continue;
                }
                $dayinfo = usergetdate($dayts);
                if ((int)$dayinfo['year'] !== $year || (int)$dayinfo['mon'] !== $month) {
                    continue;
                }
                $datekey = sprintf(
                    '%04d-%02d-%02d',
                    (int)$dayinfo['year'],
                    (int)$dayinfo['mon'],
                    (int)$dayinfo['mday']
                );

                foreach ($day->events as $mevent) {
                    $ename = isset($mevent->name) ? (string)$mevent->name : '';
                    if ($ename === '') {
                        continue;
                    }
                    $timestart = isset($mevent->timestart) ? (int)$mevent->timestart : $dayts;
                    $eurl = '';
                    if (!empty($mevent->url)) {
                        $eurl = (string)$mevent->url;
                    } else if (!empty($mevent->viewurl)) {
                        $eurl = (string)$mevent->viewurl;
                    }
                    $coursename = '';
                    if (!empty($mevent->course) && is_object($mevent->course) && !empty($mevent->course->fullname)) {
                        $coursename = (string)$mevent->course->fullname;
                    }
                    $etype = isset($mevent->normalisedeventtype)
                        ? (string)$mevent->normalisedeventtype
                        : (isset($mevent->eventtype) ? (string)$mevent->eventtype : 'other');

                    $item = [
                        'name' => $ename,
                        'timestart' => $timestart,
                        'url' => $eurl,
                        'coursename' => $coursename,
                        'type' => $etype,
                        'datekey' => $datekey,
                    ];

                    if (!isset($moodlebyday[$datekey])) {
                        $moodlebyday[$datekey] = [];
                    }
                    // Aynı etkinliği birden fazla güne yazmamak için id varsa tekilleştir.
                    $eid = isset($mevent->id) ? (int)$mevent->id : 0;
                    $dup = false;
                    if ($eid > 0) {
                        foreach ($moodlelist as $existing) {
                            if (!empty($existing['id']) && (int)$existing['id'] === $eid) {
                                $dup = true;
                                break;
                            }
                        }
                    }
                    $item['id'] = $eid;
                    $moodlebyday[$datekey][] = $item;
                    if (!$dup) {
                        $moodlelist[] = $item;
                    }
                }
            }
        }
    }

    usort($moodlelist, static function ($a, $b) {
        return ($a['timestart'] <=> $b['timestart']);
    });
} catch (Throwable $e) {
    $moodleok = false;
    $moodlebyday = [];
    $moodlelist = [];
}

$corecalurl = (new moodle_url('/calendar/view.php', [
    'view' => 'month',
    'time' => $monthstart,
]))->out(false);

/*
 * ---------------------------------------------------------
 * SEÇİLİ GÜN
 * ---------------------------------------------------------
 */

$selecteddate = optional_param('date', $todaykey, PARAM_TEXT);
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selecteddate)) {
    $selecteddate = $todaykey;
}

$selectedparts = explode('-', $selecteddate);
$selectedtimestamp = make_timestamp(
    (int)$selectedparts[0],
    (int)$selectedparts[1],
    (int)$selectedparts[2],
    12,
    0,
    0
);

$selectedevents = $events[$selecteddate] ?? [];
$selectedmoodle = $moodlebyday[$selecteddate] ?? [];

$selectedstartcount = 0;
foreach ($selectedevents as $event) {
    if ($event['type'] === 'start') {
        $selectedstartcount++;
    }
}

$selectedday = (int)$selectedparts[2];
$selectedmonthnumber = (int)$selectedparts[1];
$selectedyear = (int)$selectedparts[0];
$selectedtitle =
    $selectedday . ' ' .
    ($turkishmonths[$selectedmonthnumber] ?? '') . ' ' .
    $selectedyear;

echo $OUTPUT->header();
?>

<div class="wam-calendar-v2-page">

    <section class="wam-calendar-v2-hero">
        <div class="wam-calendar-v2-hero-content">
            <span class="wam-calendar-v2-badge">TAKVİM</span>
            <h1>Öğrenme Planını Takip Et</h1>
            <p>
                Eğitim başlangıç/bitiş tarihleri ile Moodle etkinlikleri ve son tarihler aynı ekranda.
            </p>
        </div>
    </section>

    <section class="wam-calendar-toolbar">
        <div class="wam-calendar-navigation">
            <a class="wam-calendar-arrow" href="?month=<?php echo s($previousmonth); ?>">←</a>
            <strong><?php echo s($monthtitle); ?></strong>
            <a class="wam-calendar-arrow" href="?month=<?php echo s($nextmonth); ?>">→</a>
            <a class="wam-calendar-today" href="?month=<?php echo s($currentmonth); ?>">Bugün</a>
        </div>

        <div class="wam-calendar-view-switch">
            <button type="button" class="active" id="wamMonthViewButton">Ay</button>
            <button type="button" id="wamListViewButton">Liste</button>
        </div>

        <a href="<?php echo s((new moodle_url('/local/wamakademi/catalog.php'))->out(false)); ?>"
           class="wam-calendar-catalog-button">
            Eğitimleri Gör →
        </a>
    </section>

    <div class="wam-calendar-layout">

        <section class="wam-calendar-month-card" id="wamMonthView">
            <h2 class="wam-cal-section-title">Eğitim takvimi</h2>

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
                for ($blank = 1; $blank < $firstweekday; $blank++) {
                    echo '<div class="wam-calendar-day empty"></div>';
                }

                for ($day = 1; $day <= $daysinmonth; $day++) {
                    $datekey = sprintf('%04d-%02d-%02d', $year, $month, $day);
                    $dayevents = $events[$datekey] ?? [];
                    $daymoodle = $moodlebyday[$datekey] ?? [];
                    $istoday = ($datekey === $todaykey);
                    $isselected = ($datekey === $selecteddate);

                    $classes = 'wam-calendar-day';
                    if ($istoday) {
                        $classes .= ' today';
                    }
                    if ($isselected) {
                        $classes .= ' selected';
                    }
                    ?>
                    <a href="?month=<?php echo sprintf('%04d-%02d', $year, $month); ?>&amp;date=<?php echo s($datekey); ?>"
                       class="<?php echo $classes; ?>">
                        <span class="wam-calendar-day-number"><?php echo $day; ?></span>
                        <div class="wam-calendar-day-events">
                            <?php foreach (array_slice($dayevents, 0, 2) as $event): ?>
                                <?php
                                $labelclass = $event['type'] === 'end' ? 'end' : 'start';
                                $fullname = $event['course']['fullname'];
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
                                    <?php echo s($event['course']['shortlabel']); ?>
                                </span>
                            <?php endforeach; ?>

                            <?php if (!empty($daymoodle)): ?>
                                <?php
                                $firstm = $daymoodle[0];
                                $shown = min(1, count($daymoodle));
                                ?>
                                <?php if ($shown): ?>
                                    <span class="wam-calendar-event moodle">
                                        <?php echo s(\core_text::substr($firstm['name'], 0, 28)); ?>
                                    </span>
                                <?php endif; ?>
                                <?php if (count($daymoodle) > 1 || (count($dayevents) + count($daymoodle)) > 3): ?>
                                    <span class="wam-cal-moodle-dot" title="<?php echo count($daymoodle); ?> Moodle etkinliği"></span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </a>
                    <?php
                }
                ?>
            </div>

            <div class="wam-calendar-legend">
                <span><i class="start"></i> Başlangıç</span>
                <span><i class="end"></i> Bitiş</span>
                <span><i class="moodle"></i> Moodle etkinliği</span>
            </div>
        </section>

        <section class="wam-calendar-list-card" id="wamListView" hidden>
            <h2 class="wam-cal-section-title">Eğitim listesi</h2>
            <?php if (empty($allcourseevents)): ?>
                <p class="wam-cal-empty">Bu ay için eğitim başlangıç/bitiş kaydı yok.</p>
            <?php endif; ?>
            <?php foreach ($allcourseevents as $course): ?>
                <div class="wam-calendar-list-item">
                    <strong><?php echo s($course['fullname']); ?></strong>
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
                    <a href="<?php echo s($course['courseurl']); ?>">Eğitime Git →</a>
                </div>
            <?php endforeach; ?>
        </section>

        <aside class="wam-calendar-day-panel">
            <h2><?php echo s($selectedtitle); ?></h2>
            <p class="wam-calendar-day-summary">
                <?php if ($selectedstartcount > 0): ?>
                    <?php echo (int)$selectedstartcount; ?> eğitim başlıyor
                <?php elseif (!empty($selectedevents)): ?>
                    <?php echo count($selectedevents); ?> takvim kaydı
                <?php elseif (!empty($selectedmoodle)): ?>
                    <?php echo count($selectedmoodle); ?> Moodle etkinliği
                <?php else: ?>
                    Bu tarihte planlanmış kayıt yok.
                <?php endif; ?>
            </p>

            <div class="wam-calendar-day-courses">
                <?php foreach ($selectedevents as $event): ?>
                    <?php $course = $event['course']; ?>
                    <article class="wam-calendar-day-course">
                        <?php if (!empty($course['courseimage'])): ?>
                            <img src="<?php echo s($course['courseimage']); ?>" alt="">
                        <?php else: ?>
                            <div class="wam-calendar-course-fallback">WAM</div>
                        <?php endif; ?>
                        <div>
                            <h3><?php echo s($course['fullname']); ?></h3>
                            <p>
                                <?php if ($event['type'] === 'start'): ?>
                                    Başlangıç: <?php echo userdate($course['startdate'], '%d %B %Y'); ?>
                                <?php else: ?>
                                    Bitiş: <?php echo userdate($course['enddate'], '%d %B %Y'); ?>
                                <?php endif; ?>
                            </p>
                        </div>
                        <a href="<?php echo s($course['courseurl']); ?>">Eğitime Git →</a>
                    </article>
                <?php endforeach; ?>

                <?php foreach ($selectedmoodle as $me): ?>
                    <article class="wam-calendar-day-course">
                        <div class="wam-calendar-course-fallback">M</div>
                        <div>
                            <h3>
                                <?php if (!empty($me['url'])): ?>
                                    <a href="<?php echo s($me['url']); ?>"><?php echo s($me['name']); ?></a>
                                <?php else: ?>
                                    <?php echo s($me['name']); ?>
                                <?php endif; ?>
                            </h3>
                            <p>
                                <?php echo userdate($me['timestart'], '%d %B %Y, %H:%M'); ?>
                                <?php if (!empty($me['coursename'])): ?>
                                    · <?php echo s($me['coursename']); ?>
                                <?php endif; ?>
                            </p>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </aside>

        <section class="wam-cal-moodle-section" aria-label="Etkinlikler ve son tarihler">
            <div class="wam-cal-section-head">
                <h2 class="wam-cal-section-title">Etkinlikler ve son tarihler</h2>
                <a class="wam-cal-moodle-link" href="<?php echo s($corecalurl); ?>">
                    Moodle etkinlikleri ve son tarihler
                </a>
            </div>

            <?php if (!$moodleok): ?>
                <p class="wam-cal-empty">
                    Moodle etkinlikleri bu ekranda yüklenemedi.
                    Yönetim ve ekleme için
                    <a href="<?php echo s($corecalurl); ?>">Moodle takvimini</a> açın.
                </p>
            <?php elseif (empty($moodlelist)): ?>
                <p class="wam-cal-empty">Bu ay için görünür Moodle etkinliği yok.</p>
            <?php else: ?>
                <ul class="wam-cal-moodle-list">
                    <?php foreach ($moodlelist as $me): ?>
                        <li class="wam-cal-moodle-item">
                            <?php if (!empty($me['url'])): ?>
                                <a href="<?php echo s($me['url']); ?>"><?php echo s($me['name']); ?></a>
                            <?php else: ?>
                                <strong><?php echo s($me['name']); ?></strong>
                            <?php endif; ?>
                            <span class="wam-cal-moodle-meta">
                                <?php echo userdate($me['timestart'], '%d %B %Y, %H:%M'); ?>
                                <?php if (!empty($me['coursename'])): ?>
                                    · <?php echo s($me['coursename']); ?>
                                <?php endif; ?>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>

    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var monthButton = document.getElementById('wamMonthViewButton');
    var listButton = document.getElementById('wamListViewButton');
    var monthView = document.getElementById('wamMonthView');
    var listView = document.getElementById('wamListView');
    if (!monthButton || !listButton || !monthView || !listView) {
        return;
    }
    monthButton.addEventListener('click', function () {
        monthView.hidden = false;
        listView.hidden = true;
        monthButton.classList.add('active');
        listButton.classList.remove('active');
    });
    listButton.addEventListener('click', function () {
        monthView.hidden = true;
        listView.hidden = false;
        listButton.classList.add('active');
        monthButton.classList.remove('active');
    });
});
</script>

<?php
echo $OUTPUT->footer();
