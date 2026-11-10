<?php
// This file is part of Moodle - http://moodle.org/
//
// WAM Akademi - Faz 1C İlk Teslim: Gizli Taslak Video Yükleme ve Önizleme Ekranı.
// Kapsam: Eğitim ve konu seçimi, MP4 yükleme (draft + sunucu doğrulaması),
// ilk kayıttan itibaren gizli mod_resource (Embed) oluşturma ve eğitmen önizlemesi.
// Yayımlanmış aktivitenin dosyasını değiştirme ve öğrenciye açan yayın düğmesi bu teslimde kapalıdır.

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/course/modlib.php');
require_once($CFG->dirroot . '/mod/resource/lib.php');
require_once($CFG->dirroot . '/mod/resource/locallib.php');
require_once($CFG->libdir . '/formslib.php');
require_once($CFG->libdir . '/filelib.php');
require_once($CFG->libdir . '/resourcelib.php');

require_login();

global $DB, $USER, $PAGE, $OUTPUT, $CFG;

/**
 * Kullanıcının aktivite oluşturma yetkisine sahip olduğu kursları döndürür.
 *
 * @return array<int, stdClass> Kurs kimliğine göre sıralı kurs kayıtları.
 */
function local_wamakademi_upload_get_manageable_courses(): array {
    global $DB;

    $courses = get_courses('all', 'c.fullname ASC', 'c.id, c.fullname, c.shortname, c.category, c.visible, c.maxbytes');
    $manageable = [];

    foreach ($courses as $course) {
        if ((int)$course->id === SITEID || (int)$course->id === 1 || $course->shortname === 'WAM') {
            continue;
        }
        $coursecontext = context_course::instance($course->id, IGNORE_MISSING);
        if (!$coursecontext) {
            continue;
        }
        $cancreatedraft = class_exists(\local_wamakademi\access::class)
            ? \local_wamakademi\access::can_create_draft((int)$GLOBALS['USER']->id, (int)$course->id)
            : (
                has_capability('moodle/course:manageactivities', $coursecontext) &&
                has_capability('mod/resource:addinstance', $coursecontext)
            );
        if ($cancreatedraft) {
            $manageable[(int)$course->id] = $course;
        }
    }

    return $manageable;
}

/**
 * Kursun mevcut konu (section) listesini döndürür (Section 0 hariç, yoksa 1. konuyu hazırlar).
 *
 * @param stdClass $course Kurs kaydı.
 * @return array<int, string> sectionnum => görünen konu adı.
 */
function local_wamakademi_upload_get_course_sections(stdClass $course): array {
    $modinfo = get_fast_modinfo($course->id);
    $sectioninfos = $modinfo->get_section_info_all();
    $sections = [];

    foreach ($sectioninfos as $secnum => $secinfo) {
        if ((int)$secnum === 0) {
            continue;
        }
        $name = get_section_name($course, $secinfo);
        $sections[(int)$secnum] = format_string($name) . ' (Konu ' . (int)$secnum . ')';
    }

    if (empty($sections)) {
        course_create_sections_if_missing($course, [1]);
        $modinfo = get_fast_modinfo($course->id, 0, true);
        foreach ($modinfo->get_section_info_all() as $secnum => $secinfo) {
            if ((int)$secnum === 0) {
                continue;
            }
            $name = get_section_name($course, $secinfo);
            $sections[(int)$secnum] = format_string($name) . ' (Konu ' . (int)$secnum . ')';
        }
    }

    return $sections;
}

/**
 * Seçilen kurs ve konuda yalnızca gizli taslak (visible = 0) MP4 aktivitelerini listeler.
 *
 * @param stdClass $course Kurs kaydı.
 * @return array<int, array<string, mixed>>
 */
function local_wamakademi_upload_get_hidden_drafts(stdClass $course): array {
    $modinfo = get_fast_modinfo($course->id);
    $fs = get_file_storage();
    $drafts = [];

    foreach ($modinfo->get_cms() as $cm) {
        if ($cm->modname !== 'resource' || !empty($cm->deletioninprogress)) {
            continue;
        }
        // Yalnızca gizli taslakları (öğrenciden gizli) listele; yayımlanmış aktivitelerin dosyasına dokunma.
        if ((int)$cm->visible !== 0) {
            continue;
        }
        $modcontext = context_module::instance($cm->id, IGNORE_MISSING);
        if (!$modcontext || !has_capability('moodle/course:manageactivities', $modcontext)) {
            continue;
        }

        $files = $fs->get_area_files($modcontext->id, 'mod_resource', 'content', 0, 'sortorder DESC, id ASC', false);
        if (empty($files)) {
            continue;
        }
        $mainfile = reset($files);
        $mimetype = $mainfile->get_mimetype();
        if ($mimetype !== 'video/mp4') {
            continue;
        }

        $sectionname = get_section_name($course, $cm->sectionnum);
        $previewurl = new moodle_url('/local/wamakademi/js/upload.php', [
            'courseid' => (int)$course->id,
            'sectionnum' => (int)$cm->sectionnum,
            'previewcmid' => (int)$cm->id,
        ]);
        $playerurl = new moodle_url('/mod/resource/view.php', [
            'id' => (int)$cm->id,
            'wampreview' => 1,
        ]);

        $drafts[] = [
            'cmid' => (int)$cm->id,
            'name' => format_string($cm->name),
            'sectionnum' => (int)$cm->sectionnum,
            'sectionname' => format_string($sectionname),
            'filename' => $mainfile->get_filename(),
            'filesize' => display_size($mainfile->get_filesize()),
            'timemodified' => userdate($mainfile->get_timemodified()),
            'previewurl' => $previewurl->out(false),
            'playerurl' => $playerurl->out(false),
        ];
    }

    return $drafts;
}

/**
 * WAM Faz 1C İlk Teslim — Gizli Taslak MP4 Yükleme Formu (moodleform).
 */
class local_wamakademi_draft_upload_form extends moodleform {

    protected function definition() {
        $mform = $this->_form;
        $courses = $this->_customdata['courses'];
        $selectedcourse = $this->_customdata['selectedcourse'];
        $sections = $this->_customdata['sections'];
        $maxbytes = $this->_customdata['maxbytes'];

        $courseoptions = [];
        foreach ($courses as $cid => $c) {
            $courseoptions[$cid] = format_string($c->fullname) . ' (' . format_string($c->shortname) . ')';
        }

        $mform->addElement('header', 'step1header', '1. Eğitim ve Konu Seçimi');

        $mform->addElement('select', 'courseid', 'Eğitim (Kurs)', $courseoptions);
        $mform->setType('courseid', PARAM_INT);
        $mform->setDefault('courseid', (int)$selectedcourse->id);
        $mform->addRule('courseid', get_string('required'), 'required', null, 'client');

        $mform->addElement('select', 'sectionnum', 'Konu (Mevcut Bölüm)', $sections);
        $mform->setType('sectionnum', PARAM_INT);
        if (!empty($this->_customdata['selectedsection'])) {
            $mform->setDefault('sectionnum', (int)$this->_customdata['selectedsection']);
        }

        $mform->addElement('text', 'newsectionname', 'Veya Yeni Konu Başlığı (İsteğe Bağlı)', ['size' => 48, 'placeholder' => 'Örn: Excel’e Giriş veya Formüller']);
        $mform->setType('newsectionname', PARAM_TEXT);
        $mform->addRule('newsectionname', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');

        $mform->addElement('text', 'name', 'Video Ders Başlığı', ['size' => 60, 'placeholder' => 'Örn: Excel’e Giriş — Video']);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', get_string('required'), 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');

        $mform->addElement('textarea', 'intro', 'Kısa Açıklama (İsteğe Bağlı)', ['rows' => 3, 'cols' => 60]);
        $mform->setType('intro', PARAM_CLEANHTML);

        $mform->addElement('header', 'step2header', '2. Video Dosyası (MP4) ve Görünüm');

        $fileoptions = [
            'subdirs' => 0,
            'maxbytes' => $maxbytes,
            'maxfiles' => 1,
            'accepted_types' => ['.mp4', 'video/mp4'],
            'return_types' => FILE_INTERNAL,
        ];
        $mform->addElement('filemanager', 'videofile', 'MP4 Video Dosyası', null, $fileoptions);
        $mform->addRule('videofile', get_string('required'), 'required', null, 'client');

        $mform->addElement('static', 'displayinfo', 'Oynatıcı Görünümü', '<strong>Gömülü / Embed</strong> (Standart Moodle <code>mod_resource</code> + WAM oynatıcı)');
        $mform->addElement('static', 'visibilityinfo', 'İlk Kayıt Durumu', '<span style="display:inline-block;padding:3px 10px;border-radius:999px;background:#fef3c7;color:#92400e;font-weight:700;">Öğrencilerden Gizli Taslak (visible = 0)</span> — Öğrenciler ders listesinde, kurs sayfasında veya doğrudan medya URL’siyle açamaz.');

        $this->add_action_buttons(false, 'Gizli Taslak Olarak Kaydet ve Önizle');
    }

    public function validation($data, $files) {
        global $USER, $DB;

        $errors = parent::validation($data, $files);

        $courseid = (int)($data['courseid'] ?? 0);
        $sectionnum = (int)($data['sectionnum'] ?? 0);
        $newsectionname = trim((string)($data['newsectionname'] ?? ''));

        $course = $DB->get_record('course', ['id' => $courseid], '*', IGNORE_MISSING);
        if (!$course || (int)$course->id === SITEID) {
            $errors['courseid'] = 'Geçerli bir eğitim seçmelisiniz.';
            return $errors;
        }

        $coursecontext = context_course::instance($course->id, IGNORE_MISSING);
        if (
            !$coursecontext ||
            !has_capability('moodle/course:manageactivities', $coursecontext) ||
            !has_capability('mod/resource:addinstance', $coursecontext) ||
            !has_capability('moodle/course:activityvisibility', $coursecontext)
        ) {
            $errors['courseid'] = 'Bu eğitimde gizli taslak video oluşturma yetkiniz yok.';
            return $errors;
        }

        if ($newsectionname === '') {
            $sectionRecord = $DB->get_record('course_sections', [
                'course' => $course->id,
                'section' => $sectionnum,
            ], 'id, section', IGNORE_MISSING);
            if (!$sectionRecord || $sectionnum <= 0) {
                $errors['sectionnum'] = 'Seçilen konu bu eğitime ait değil.';
            }
        }

        $title = trim((string)($data['name'] ?? ''));
        if ($title === '') {
            $errors['name'] = 'Video ders başlığı boş bırakılamaz.';
        }

        $draftitemid = (int)($data['videofile'] ?? 0);
        $usercontext = context_user::instance($USER->id);
        $fs = get_file_storage();
        $draftfiles = $fs->get_area_files($usercontext->id, 'user', 'draft', $draftitemid, 'id ASC', false);

        if (empty($draftfiles)) {
            $errors['videofile'] = 'Lütfen bir MP4 video dosyası yükleyin.';
            return $errors;
        }

        if (count($draftfiles) > 1) {
            $errors['videofile'] = 'Tek seferde yalnızca 1 adet MP4 video dosyası yüklenebilir.';
            return $errors;
        }

        $file = reset($draftfiles);
        $filename = $file->get_filename();
        $filesize = (int)$file->get_filesize();
        $ext = core_text::strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $mimetype = $file->get_mimetype();

        if ($filesize <= 0) {
            $errors['videofile'] = 'Yüklenen dosya boş (0 bayt). Yükleme kesintiye uğramış olabilir.';
            return $errors;
        }

        $maxbytes = get_user_max_upload_file_size($coursecontext, $this->_customdata['sitebytes'], $course->maxbytes);
        if ($maxbytes > 0 && $filesize > $maxbytes) {
            $errors['videofile'] = 'Dosya boyutu izin verilen sınırı (' . display_size($maxbytes) . ') aşıyor.';
            return $errors;
        }

        if ($ext !== 'mp4' || $mimetype !== 'video/mp4') {
            $errors['videofile'] = 'Yalnızca geçerli MP4 (video/mp4) dosyaları kabul edilir.';
            return $errors;
        }

        // MP4 konteyner imzası (ftyp) kontrolü — sahte uzantılı dosyaları sunucuda reddet.
        $fh = $file->get_content_file_handle();
        if ($fh) {
            $header = fread($fh, 12);
            fclose($fh);
            if ($header === false || strlen($header) < 8 || substr($header, 4, 4) !== 'ftyp') {
                $errors['videofile'] = 'Dosya içeriği geçerli bir MP4 video konteyneri (ISO Base Media / ftyp) değil.';
                return $errors;
            }
        }

        return $errors;
    }
}

// 1. Kullanıcının yetkili olduğu kursları kontrol et.
$manageablecourses = local_wamakademi_upload_get_manageable_courses();
if (empty($manageablecourses)) {
    throw new required_capability_exception(
        context_system::instance(),
        'moodle/course:manageactivities',
        'nopermissions',
        ''
    );
}

$requestedcourseid = optional_param('courseid', 0, PARAM_INT);
$requestedsection = optional_param('sectionnum', 1, PARAM_INT);
$previewcmid = optional_param('previewcmid', 0, PARAM_INT);

if ($requestedcourseid > 0 && isset($manageablecourses[$requestedcourseid])) {
    $selectedcourse = $manageablecourses[$requestedcourseid];
} else {
    $selectedcourse = reset($manageablecourses);
}

// Kurs bağlamında Moodle güvenlik ve yetki doğrulaması.
$coursecontext = context_course::instance($selectedcourse->id, MUST_EXIST);
require_login($selectedcourse, false);
if (class_exists(\local_wamakademi\access::class)
        && !\local_wamakademi\access::can_create_draft((int)$USER->id, (int)$course->id)) {
    require_capability('local/wamakademi:createdraft', $coursecontext);
}
require_capability('moodle/course:manageactivities', $coursecontext);
require_capability('mod/resource:addinstance', $coursecontext);
require_capability('moodle/course:activityvisibility', $coursecontext);

$sections = local_wamakademi_upload_get_course_sections($selectedcourse);
if (!isset($sections[$requestedsection])) {
    $requestedsection = (int)array_key_first($sections);
}

$pageurl = new moodle_url('/local/wamakademi/js/upload.php', [
    'courseid' => (int)$selectedcourse->id,
    'sectionnum' => (int)$requestedsection,
]);
if ($previewcmid > 0) {
    $pageurl->param('previewcmid', $previewcmid);
}

$PAGE->set_url($pageurl);
$PAGE->set_context($coursecontext);
$PAGE->set_pagelayout('standard');
$PAGE->set_title('WAM Akademi — Video Yükle (Gizli Taslak ve Önizleme)');
$PAGE->set_heading(format_string($selectedcourse->fullname));
$PAGE->requires->css(new moodle_url('/local/wamakademi/styles.css'));

$maxbytes = get_user_max_upload_file_size($coursecontext, $CFG->maxbytes, $selectedcourse->maxbytes);

$mform = new local_wamakademi_draft_upload_form($pageurl->out(false), [
    'courses' => $manageablecourses,
    'selectedcourse' => $selectedcourse,
    'sections' => $sections,
    'selectedsection' => $requestedsection,
    'maxbytes' => $maxbytes,
    'sitebytes' => $CFG->maxbytes,
]);

$createderror = null;

if ($formdata = $mform->get_data()) {
    require_sesskey();

    // Formdan gelen kurs kimliğini yeniden doğrula.
    $targetcourseid = (int)$formdata->courseid;
    $targetcourse = $DB->get_record('course', ['id' => $targetcourseid], '*', MUST_EXIST);
    if ((int)$targetcourse->id === SITEID) {
        throw new moodle_exception('invalidcourseid');
    }
    $targetcontext = context_course::instance($targetcourse->id, MUST_EXIST);
    require_login($targetcourse, false);
    require_capability('moodle/course:manageactivities', $targetcontext);
    require_capability('mod/resource:addinstance', $targetcontext);
    require_capability('moodle/course:activityvisibility', $targetcontext);

    $targetsectionnum = (int)$formdata->sectionnum;
    $newsectionname = trim((string)($formdata->newsectionname ?? ''));

    $transaction = $DB->start_delegated_transaction();
    try {
        if ($newsectionname !== '') {
            require_capability('moodle/course:update', $targetcontext);
            $newsec = course_create_section($targetcourse->id, 0);
            course_update_section($targetcourse, $newsec, ['name' => $newsectionname]);
            $targetsectionnum = (int)$newsec->section;
        } else {
            $existingsec = $DB->get_record('course_sections', [
                'course' => $targetcourse->id,
                'section' => $targetsectionnum,
            ], '*', MUST_EXIST);
        }

        // İlk kayıttan itibaren gizli mod_resource (Embed) oluştur.
        $moduleinfo = new stdClass();
        $moduleinfo->modulename = 'resource';
        $moduleinfo->course = (int)$targetcourse->id;
        $moduleinfo->section = $targetsectionnum;
        $moduleinfo->name = trim((string)$formdata->name);
        $moduleinfo->introeditor = [
            'text' => (string)($formdata->intro ?? ''),
            'format' => FORMAT_HTML,
            'itemid' => 0,
        ];
        $moduleinfo->showdescription = 0;

        // İlk kayıttan itibaren öğrencilerden gizli tut (visible = 0).
        $moduleinfo->visible = 0;
        $moduleinfo->visibleoncoursepage = 1;

        // Standart Moodle Dosya (mod_resource) Gömülü / Embed görünümü.
        $moduleinfo->display = RESOURCELIB_DISPLAY_EMBED;
        $moduleinfo->printintro = 1;
        $moduleinfo->showsize = 1;
        $moduleinfo->showtype = 1;
        $moduleinfo->showdate = 0;
        $moduleinfo->filterfiles = 0;
        $moduleinfo->files = (int)$formdata->videofile;
        $moduleinfo->cmidnumber = '';
        $moduleinfo->groupmode = 0;
        $moduleinfo->groupingid = 0;
        $moduleinfo->completion = 0;

        $created = create_module($moduleinfo);
        if (empty($created->coursemodule)) {
            throw new moodle_exception('cannotaddcoursemodule');
        }

        $newcmid = (int)$created->coursemodule;
        $modcontext = context_module::instance($newcmid, MUST_EXIST);

        // Dosyanın mod_resource content alanına gerçekten yazıldığını doğrula; yoksa işlemi geri al (yarım aktivite bırakma).
        $fs = get_file_storage();
        $storedfiles = $fs->get_area_files($modcontext->id, 'mod_resource', 'content', 0, 'sortorder DESC, id ASC', false);
        if (empty($storedfiles)) {
            throw new moodle_exception('filenotfound', 'resource');
        }

        // Güvenlik için visible=0 olduğunu veritabanında kesinleştir.
        $DB->set_field('course_modules', 'visible', 0, ['id' => $newcmid]);
        $DB->set_field('course_modules', 'visibleold', 0, ['id' => $newcmid]);
        rebuild_course_cache($targetcourse->id, true);

        $transaction->allow_commit();

        redirect(
            new moodle_url('/local/wamakademi/js/upload.php', [
                'courseid' => (int)$targetcourse->id,
                'sectionnum' => $targetsectionnum,
                'previewcmid' => $newcmid,
            ]),
            'Video gizli taslak olarak kaydedildi. Aşağıdan önizleyebilirsiniz; öğrenciler bu taslağı göremez ve açamaz.',
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    } catch (Throwable $e) {
        try {
            $transaction->rollback($e);
        } catch (Throwable $ignored) {
            $createderror = $e->getMessage();
        }
    }
}

// Önizlenecek gizli taslak seçildiyse doğrula.
$previewdata = null;
if ($previewcmid > 0) {
    $previewcm = get_coursemodule_from_id('resource', $previewcmid, 0, false, IGNORE_MISSING);
    if ($previewcm && (int)$previewcm->course === (int)$selectedcourse->id) {
        $previewmodcontext = context_module::instance($previewcm->id, IGNORE_MISSING);
        if (
            $previewmodcontext &&
            has_capability('moodle/course:manageactivities', $previewmodcontext) &&
            has_capability('moodle/course:viewhiddenactivities', $previewmodcontext) &&
            has_capability('mod/resource:view', $previewmodcontext)
        ) {
            $resource = $DB->get_record('resource', ['id' => $previewcm->instance], '*', IGNORE_MISSING);
            $fs = get_file_storage();
            $files = $fs->get_area_files($previewmodcontext->id, 'mod_resource', 'content', 0, 'sortorder DESC, id ASC', false);
            if ($resource && !empty($files)) {
                $mainfile = reset($files);
                $pluginfileurl = moodle_url::make_pluginfile_url(
                    $previewmodcontext->id,
                    'mod_resource',
                    'content',
                    $resource->revision,
                    $mainfile->get_filepath(),
                    $mainfile->get_filename()
                );
                $mediamanager = core_media_manager::instance($PAGE);
                $embedoptions = [
                    core_media_manager::OPTION_TRUSTED => true,
                    core_media_manager::OPTION_BLOCK => true,
                ];
                $embedhtml = $mediamanager->embed_url($pluginfileurl, format_string($resource->name), 0, 0, $embedoptions);

                $previewdata = [
                    'cmid' => (int)$previewcm->id,
                    'name' => format_string($resource->name),
                    'sectionname' => format_string(get_section_name($selectedcourse, $previewcm->sectionnum)),
                    'visible' => (int)$previewcm->visible,
                    'filename' => $mainfile->get_filename(),
                    'filesize' => display_size($mainfile->get_filesize()),
                    'embedhtml' => $embedhtml,
                    'wamplayerurl' => (new moodle_url('/mod/resource/view.php', [
                        'id' => (int)$previewcm->id,
                        'wampreview' => 1,
                    ]))->out(false),
                ];
            }
        }
    }
}

$hiddendrafts = local_wamakademi_upload_get_hidden_drafts($selectedcourse);

echo $OUTPUT->header();
?>

<style>
.wam-upload-shell {
    max-width: 1080px;
    margin: 0 auto 48px;
    font-family: "Segoe UI", "Helvetica Neue", Arial, sans-serif;
    color: #0f172a;
}
.wam-upload-banner {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    padding: 16px 20px;
    margin-bottom: 20px;
    border-radius: 12px;
    background: #FFFFFF;
    border: 1px solid #E2E8F0;
    color: #0F172A;
    box-shadow: 0 1px 2px rgba(15, 23, 42, 0.06);
}
.wam-upload-banner h2 {
    margin: 4px 0 6px;
    font-size: 22px;
    font-weight: 750;
    color: #0F172A;
}
.wam-upload-banner p {
    margin: 0;
    color: #475569;
    font-size: 14px;
}
.wam-upload-steps {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 10px;
    margin-bottom: 22px;
}
.wam-upload-step {
    padding: 12px 14px;
    border-radius: 10px;
    border: 1px solid #cbd5e1;
    background: #f8fafc;
    font-size: 13px;
}
.wam-upload-step.active {
    border-color: #2563eb;
    background: #eff6ff;
    color: #1e3a8a;
    font-weight: 700;
}
.wam-upload-step.disabled {
    opacity: 0.65;
    background: #f1f5f9;
}
.wam-upload-card {
    padding: 22px 24px;
    margin-bottom: 22px;
    border-radius: 14px;
    border: 1px solid #e2e8f0;
    background: #ffffff;
    box-shadow: 0 4px 14px rgba(15, 23, 42, 0.05);
}
.wam-upload-preview-box {
    border: 2px solid #16a34a;
    background: #f0fdf4;
}
.wam-upload-badge-hidden {
    display: inline-flex;
    align-items: center;
    padding: 4px 10px;
    border-radius: 999px;
    background: #fef3c7;
    color: #92400e;
    font-size: 12px;
    font-weight: 700;
}
.wam-upload-publish-locked {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    margin-top: 16px;
    padding: 14px 16px;
    border-radius: 10px;
    border: 1px dashed #94a3b8;
    background: #f8fafc;
}
.wam-upload-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 14px;
}
.wam-upload-table th,
.wam-upload-table td {
    padding: 10px 12px;
    border-bottom: 1px solid #e2e8f0;
    text-align: left;
}
@media (max-width: 768px) {
    .wam-upload-steps {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="wam-upload-shell">

    <div class="wam-upload-banner">
        <div>
            <span style="font-size:11px;font-weight:700;letter-spacing:0.06em;text-transform:uppercase;background:rgba(255,255,255,0.18);padding:4px 9px;border-radius:999px;">
                WAM AKADEMİ · FAZ 1C İLK TESLİM
            </span>
            <h2>Video Ders Yükle (Gizli Taslak ve Önizleme)</h2>
            <p>Eğitim ve konu seçin, MP4 videonuzu ilk kayıttan itibaren öğrencilerden gizli taslak olarak kaydedin ve önizleyin.</p>
        </div>
        <div>
            <a href="<?php echo (new moodle_url('/course/view.php', ['id' => (int)$selectedcourse->id]))->out(false); ?>"
               class="btn btn-light btn-sm">
                Moodle Kurs Sayfasına Git →
            </a>
        </div>
    </div>

    <div class="wam-upload-steps" aria-label="Yükleme adımları">
        <div class="wam-upload-step active">1. Eğitim ve Konu</div>
        <div class="wam-upload-step active">2. Dosya (MP4 · Embed)</div>
        <div class="wam-upload-step active">3. Gizli Taslak Önizleme</div>
        <div class="wam-upload-step">4. Yayın (A8 sonrası)</div>
    </div>

    <?php if (!empty($createderror)): ?>
        <div class="alert alert-danger">
            <strong>Yükleme tamamlanamadı:</strong> <?php echo s($createderror); ?>
            (Yarım aktivite bırakılmadı.)
        </div>
    <?php endif; ?>

    <?php if ($previewdata !== null): ?>
        <section class="wam-upload-card wam-upload-preview-box" aria-labelledby="wam-preview-heading">
            <div style="display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:12px;margin-bottom:14px;">
                <div>
                    <span class="wam-upload-badge-hidden">GİZLİ TASLAK (Öğrencilere Kapalı · visible=0)</span>
                    <h3 id="wam-preview-heading" style="margin:6px 0 2px;font-size:20px;font-weight:750;">
                        <?php echo $previewdata['name']; ?>
                    </h3>
                    <div style="font-size:13px;color:#475569;">
                        Konu: <strong><?php echo $previewdata['sectionname']; ?></strong> ·
                        Dosya: <code><?php echo s($previewdata['filename']); ?></code> (<?php echo s($previewdata['filesize']); ?>) ·
                        CMID: <code><?php echo (int)$previewdata['cmid']; ?></code>
                    </div>
                </div>
                <div>
                    <a href="<?php echo s($previewdata['wamplayerurl']); ?>"
                       class="btn btn-primary btn-sm"
                       target="_blank"
                       rel="noopener">
                        Tam Ekran WAM Oynatıcısında Önizle ↗
                    </a>
                </div>
            </div>

            <div style="background:#0f172a;border-radius:12px;padding:12px;margin-bottom:12px;">
                <?php echo $previewdata['embedhtml']; ?>
            </div>

            <div style="font-size:13px;color:#166534;">
                ✓ <strong>Eğitmen Önizleme Koruması Aktif:</strong> Bu önizleme sırasında <code>local_wamakademi_progress</code> tablosuna öğrenci izleme kaydı yazılmaz.
            </div>

            <?php
            $publishenabled = (bool)get_config('local_wamakademi', 'publishenabled');
            $canpublish = class_exists(\local_wamakademi\access::class)
                && \local_wamakademi\access::can_publish((int)$USER->id, (int)$selectedcourse->id);
            $coursegroups = groups_get_all_groups((int)$selectedcourse->id);
            ?>
            <div class="wam-upload-publish-locked">
                <div>
                    <strong>4. Adım — Öğrenciye Yayınlama</strong>
                    <div style="font-size:13px;color:#475569;">
                        Yayın; yetki, dosya (MP4/ftyp), kitle ve A8 önkoşullarını yeniden doğrular. Hata olursa taslak gizli kalır.
                        Yayımlanmış videoda dosya değişimi yok — yeni sürüm = yeni etkinlik (eski ilerleme korunur).
                    </div>
                    <?php if ($publishenabled && $canpublish): ?>
                        <div style="margin-top:10px;display:flex;flex-wrap:wrap;gap:8px;align-items:center;">
                            <label for="wamPublishGroup" style="font-size:13px;margin:0;">Kitle (grup):</label>
                            <select id="wamPublishGroup" class="form-select form-select-sm" style="max-width:240px;">
                                <option value="0">Seçiniz</option>
                                <?php foreach ($coursegroups as $g): ?>
                                    <option value="<?php echo (int)$g->id; ?>"><?php echo s(format_string($g->name)); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="button" id="wamPublishBtn" class="btn btn-primary btn-sm"
                                    data-cmid="<?php echo (int)$previewdata['cmid']; ?>"
                                    data-sesskey="<?php echo sesskey(); ?>"
                                    data-url="<?php echo (new moodle_url('/local/wamakademi/publish.php'))->out(false); ?>">
                                Öğrencilere Yayınla
                            </button>
                            <span id="wamPublishStatus" style="font-size:13px;"></span>
                        </div>
                        <script>
                        (function(){
                          var btn = document.getElementById('wamPublishBtn');
                          if (!btn) return;
                          btn.addEventListener('click', function(){
                            var groupid = document.getElementById('wamPublishGroup').value;
                            var status = document.getElementById('wamPublishStatus');
                            status.textContent = 'Doğrulanıyor...';
                            var body = new URLSearchParams();
                            body.set('cmid', btn.getAttribute('data-cmid'));
                            body.set('groupid', groupid);
                            body.set('sesskey', btn.getAttribute('data-sesskey'));
                            fetch(btn.getAttribute('data-url'), {
                              method: 'POST',
                              headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                              body: body.toString(),
                              credentials: 'same-origin'
                            }).then(function(r){ return r.json(); }).then(function(data){
                              if (data.success) {
                                status.textContent = 'Yayınlandı.';
                                status.style.color = '#166534';
                              } else {
                                status.textContent = (data.errors || ['Hata']).join(' ');
                                status.style.color = '#b91c1c';
                              }
                            }).catch(function(e){
                              status.textContent = 'İstek başarısız';
                              status.style.color = '#b91c1c';
                            });
                          });
                        })();
                        </script>
                    <?php else: ?>
                        <div style="margin-top:8px;font-size:13px;color:#92400e;">
                            Yayın kapalı: A8 testleri geçene kadar <code>publishenabled=0</code>.
                        </div>
                        <button type="button" class="btn btn-secondary" disabled aria-disabled="true">
                            Öğrencilere Yayınla (Kapalı)
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </section>
    <?php endif; ?>

    <section class="wam-upload-card">
        <div style="display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:12px;margin-bottom:16px;">
            <div>
                <h3 style="margin:0;font-size:18px;font-weight:700;">Yeni Gizli Taslak Video Oluştur</h3>
                <p style="margin:4px 0 0;font-size:13px;color:#64748b;">
                    Not: Yayımlanmış bir aktivitenin dosyasını değiştirme bu ekranda kapalıdır; her yükleme yeni bir gizli taslak oluşturur.
                </p>
            </div>
            <form method="get" action="<?php echo (new moodle_url('/local/wamakademi/js/upload.php'))->out(false); ?>" style="display:flex;gap:8px;align-items:center;">
                <label for="wamQuickCourseSelect" style="font-size:13px;font-weight:600;margin:0;">Eğitim Değiştir:</label>
                <select id="wamQuickCourseSelect" name="courseid" class="form-select form-select-sm" onchange="this.form.submit()">
                    <?php foreach ($manageablecourses as $cid => $c): ?>
                        <option value="<?php echo (int)$cid; ?>" <?php echo ((int)$cid === (int)$selectedcourse->id) ? 'selected' : ''; ?>>
                            <?php echo format_string($c->fullname); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>

        <?php $mform->display(); ?>
    </section>

    <section class="wam-upload-card">
        <h3 style="margin:0 0 12px;font-size:18px;font-weight:700;">
            Bu Eğitimdeki Mevcut Gizli Video Taslakları (<?php echo count($hiddendrafts); ?>)
        </h3>
        <?php if (empty($hiddendrafts)): ?>
            <p style="margin:0;font-size:14px;color:#64748b;">
                Bu eğitimde henüz gizli taslak durumunda MP4 video bulunmuyor.
            </p>
        <?php else: ?>
            <table class="wam-upload-table">
                <thead>
                    <tr>
                        <th>CMID</th>
                        <th>Konu</th>
                        <th>Ders Başlığı</th>
                        <th>Dosya</th>
                        <th>Boyut</th>
                        <th>Durum</th>
                        <th>İşlem</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($hiddendrafts as $draft): ?>
                        <tr>
                            <td><code><?php echo (int)$draft['cmid']; ?></code></td>
                            <td><?php echo $draft['sectionname']; ?></td>
                            <td><strong><?php echo $draft['name']; ?></strong></td>
                            <td><code><?php echo s($draft['filename']); ?></code></td>
                            <td><?php echo s($draft['filesize']); ?></td>
                            <td><span class="wam-upload-badge-hidden">Gizli Taslak</span></td>
                            <td>
                                <a href="<?php echo s($draft['previewurl']); ?>" class="btn btn-outline-primary btn-sm">
                                    Burada Önizle
                                </a>
                                <a href="<?php echo s($draft['playerurl']); ?>" class="btn btn-outline-secondary btn-sm" target="_blank" rel="noopener">
                                    WAM Oynatıcı ↗
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>

</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var formCourseSelect = document.getElementById('id_courseid');
    if (formCourseSelect) {
        formCourseSelect.addEventListener('change', function () {
            var nextCourseId = formCourseSelect.value;
            if (nextCourseId) {
                window.location.href = '<?php echo (new moodle_url('/local/wamakademi/js/upload.php'))->out(false); ?>?courseid=' + encodeURIComponent(nextCourseId);
            }
        });
    }
});
</script>

<?php
echo $OUTPUT->footer();
