<?php

require_once(__DIR__ . '/../../config.php');

require_login();

global $DB, $USER, $PAGE, $OUTPUT, $CFG;

$PAGE->set_url(new moodle_url('/local/wamakademi/catalog.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title('Eğitimler');
$PAGE->set_heading('');
$PAGE->add_body_class('wam-catalog-body');

$PAGE->requires->css('/local/wamakademi/styles.css');

$courses = get_courses();

$catalogcourses = [];

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

    $coursecontext = context_course::instance($course->id);

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

    $summary = trim(
        format_text(
            $course->summary,
            $course->summaryformat,
            ['context' => $coursecontext]
        )
    );

    $summaryplain = trim(strip_tags($summary));

    if ($summaryplain === '') {
        $summaryplain = 'Bu eğitim ile ilgili temel bilgi ve uygulamaları öğren.';
    }

    $categoryname = '';

    if (!empty($course->category)) {
        $category = core_course_category::get($course->category);
        $categoryname = $category->name;
    }

    $fullname = format_string($course->fullname);

    $level = 'Temel Eğitimler';

    if (
        stripos($fullname, 'ileri') !== false ||
        stripos($categoryname, 'ileri') !== false
    ) {
        $level = 'İleri Düzey';
    }

    $teachername = 'WAM Admin';

    $teachers = get_enrolled_users(
        $coursecontext,
        'moodle/course:update',
        0,
        'u.id,u.firstname,u.lastname',
        'u.lastname ASC'
    );

    if (!empty($teachers)) {
        $teacher = reset($teachers);

        $teachername = fullname($teacher);
    }

    $catalogcourses[] = [
        'id' => (int)$course->id,
        'fullname' => $fullname,
        'shortname' => format_string($course->shortname),
        'summary' => $summaryplain,
        'category' => format_string($categoryname),
        'level' => $level,
        'teacher' => $teachername,
        'courseimage' => $courseimage,
        'courseurl' => (new moodle_url(
            '/course/view.php',
            ['id' => $course->id]
        ))->out(false),
    ];
}

usort($catalogcourses, function($a, $b) {
    return strcmp($a['fullname'], $b['fullname']);
});

echo $OUTPUT->header();
?>

<div class="wam-catalog-page">

    <section class="wam-catalog-hero">

        <div class="wam-catalog-hero-content">

            <span class="wam-page-badge">
                EĞİTİM KATALOĞU
            </span>

            <h1>
                Kendini Geliştir, Geleceğe Hazırlan
            </h1>

            <p>
                İhtiyacına uygun eğitimleri keşfet ve öğrenmeye başla.
            </p>

        </div>

    </section>

    <section class="wam-catalog-toolbar">

        <div class="wam-catalog-search">
            <span>⌕</span>

            <input
                type="text"
                id="wamCatalogSearch"
                placeholder="Eğitimlerde ara..."
                autocomplete="off"
            >
        </div>

        <div class="wam-catalog-filters">

            <button
                type="button"
                class="wam-filter-button active"
                data-filter="all">
                Tümü
            </button>

            <button
                type="button"
                class="wam-filter-button"
                data-filter="office">
                Microsoft Office
            </button>

            <button
                type="button"
                class="wam-filter-button"
                data-filter="temel">
                Temel Eğitimler
            </button>

            <button
                type="button"
                class="wam-filter-button"
                data-filter="ileri">
                İleri Düzey
            </button>

        </div>

        <div class="wam-catalog-sort">

            <select id="wamCatalogSort">
                <option value="name">
                    Sırala: A-Z
                </option>

                <option value="reverse">
                    Sırala: Z-A
                </option>
            </select>

        </div>

    </section>

    <section class="wam-catalog-content">

        <div class="wam-catalog-title">

            <div>
                <h2>Tüm Eğitimler</h2>

                <p id="wamCourseCount">
                    <?php echo count($catalogcourses); ?> eğitim
                </p>
            </div>

        </div>

        <div class="wam-catalog-grid" id="wamCatalogGrid">

            <?php foreach ($catalogcourses as $course): ?>

                <?php

                $searchtext = strtolower(
                    $course['fullname']
                    . ' '
                    . $course['summary']
                    . ' '
                    . $course['level']
                    . ' '
                    . $course['category']
                );

                $filterlevel = $course['level'] === 'İleri Düzey'
                    ? 'ileri'
                    : 'temel';

                ?>

                <article
                    class="wam-catalog-card"
                    data-name="<?php echo s(strtolower($course['fullname'])); ?>"
                    data-search="<?php echo s($searchtext); ?>"
                    data-level="<?php echo s($filterlevel); ?>"
                    data-office="office"
                >

                    <div class="wam-catalog-cover">

                        <?php if (!empty($course['courseimage'])): ?>

                            <img
                                src="<?php echo s($course['courseimage']); ?>"
                                alt="<?php echo s($course['fullname']); ?>"
                            >

                        <?php else: ?>

                            <div class="wam-course-fallback">
                                <?php echo s($course['shortname']); ?>
                            </div>

                        <?php endif; ?>

                    </div>

                    <div class="wam-catalog-card-body">

                        <span
                            class="wam-catalog-level
                            <?php echo $filterlevel === 'ileri'
                                ? 'advanced'
                                : 'basic'; ?>"
                        >
                            <?php echo s($course['level']); ?>
                        </span>

                        <h3>
                            <?php echo s($course['fullname']); ?>
                        </h3>

                        <p class="wam-catalog-description">
                            <?php echo s($course['summary']); ?>
                        </p>

                        <div class="wam-catalog-teacher">
                            <strong>Eğitmen:</strong>
                            <?php echo s($course['teacher']); ?>
                        </div>

                        <div class="wam-catalog-card-footer">

                            <a href="<?php echo s($course['courseurl']); ?>">
                                Eğitimi İncele
                                <span>→</span>
                            </a>

                        </div>

                    </div>

                </article>

            <?php endforeach; ?>

        </div>

        <?php if (empty($catalogcourses)): ?>

            <div class="wam-calendar-empty">
                <h2>Henüz katalogda eğitim bulunmuyor.</h2>
            </div>

        <?php endif; ?>

    </section>

</div>

<script>
document.addEventListener('DOMContentLoaded', function() {

    const searchInput = document.getElementById('wamCatalogSearch');
    const filterButtons = document.querySelectorAll('.wam-filter-button');
    const sortSelect = document.getElementById('wamCatalogSort');
    const grid = document.getElementById('wamCatalogGrid');
    const count = document.getElementById('wamCourseCount');

    let activeFilter = 'all';

    function applyFilters() {

        const searchValue = (searchInput.value || '').toLowerCase().trim();

        const cards = Array.from(
            document.querySelectorAll('.wam-catalog-card')
        );

        let visible = [];

        cards.forEach(function(card) {

            const searchText = card.dataset.search || '';
            const level = card.dataset.level || '';

            const matchesSearch =
                searchValue === '' ||
                searchText.includes(searchValue);

            let matchesFilter = true;

            if (activeFilter === 'temel') {
                matchesFilter = level === 'temel';
            }

            if (activeFilter === 'ileri') {
                matchesFilter = level === 'ileri';
            }

            if (activeFilter === 'office') {
                matchesFilter = true;
            }

            const show = matchesSearch && matchesFilter;

            card.style.display = show ? '' : 'none';

            if (show) {
                visible.push(card);
            }
        });

        count.textContent = visible.length + ' eğitim';
    }

    function applySort() {

        const cards = Array.from(
            grid.querySelectorAll('.wam-catalog-card')
        );

        cards.sort(function(a, b) {

            const aName = a.dataset.name || '';
            const bName = b.dataset.name || '';

            if (sortSelect.value === 'reverse') {
                return bName.localeCompare(aName, 'tr');
            }

            return aName.localeCompare(bName, 'tr');
        });

        cards.forEach(function(card) {
            grid.appendChild(card);
        });
    }

    searchInput.addEventListener('input', applyFilters);

    filterButtons.forEach(function(button) {

        button.addEventListener('click', function() {

            filterButtons.forEach(function(btn) {
                btn.classList.remove('active');
            });

            button.classList.add('active');

            activeFilter = button.dataset.filter;

            applyFilters();
        });

    });

    sortSelect.addEventListener('change', function() {
        applySort();
        applyFilters();
    });

});
</script>

<?php

echo $OUTPUT->footer();
