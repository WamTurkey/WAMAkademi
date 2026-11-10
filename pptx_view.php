<?php

define('NO_DEBUG_DISPLAY', true);

require('../../config.php');

require_login();

$cmid = required_param('id', PARAM_INT);

$cm = get_coursemodule_from_id(
    'resource',
    $cmid,
    0,
    false,
    MUST_EXIST
);

$context = context_module::instance($cm->id);

$files = get_file_storage()->get_area_files(
    $context->id,
    'mod_resource',
    'content',
    0,
    'id',
    false
);

$pptx = null;

foreach ($files as $file) {
    if (!$file->is_directory() && preg_match('/\.pptx?$/i', $file->get_filename())) {
        $pptx = $file;
        break;
    }
}

if (!$pptx) {
    throw new moodle_exception('PPTX dosyası bulunamadı.');
}

/*
 * Kalıcı PPTX cache klasörü.
 */
$cachedir = $CFG->dataroot . '/wam_pptx_cache';

if (!is_dir($cachedir)) {
    mkdir($cachedir, 0770, true);
}

$basename = pathinfo(
    $pptx->get_filename(),
    PATHINFO_FILENAME
);

$pdfpath = $cachedir . '/cm_' . $cmid . '.pdf';

/*
 * PPTX değişmişse cache'i yenile.
 */
$pptxmodified = $pptx->get_timemodified();

$cachevalid = file_exists($pdfpath)
    && filemtime($pdfpath) >= $pptxmodified;

/*
 * PDF yoksa veya PPTX değişmişse sadece o zaman
 * LibreOffice ile yeniden oluştur.
 */
if (!$cachevalid) {

    $tempdir = make_temp_directory('wam_pptx');

    $pptxpath = $tempdir . '/' . $pptx->get_filename();

    $pptx->copy_content_to($pptxpath);

    $profile = $tempdir . '/lo-profile-' . uniqid();

    mkdir($profile, 0700, true);

    $command = sprintf(
        'libreoffice --headless -env:UserInstallation=file://%s --convert-to pdf --outdir %s %s 2>&1',
        escapeshellarg($profile),
        escapeshellarg($tempdir),
        escapeshellarg($pptxpath)
    );

    exec($command, $output, $returncode);

    $generatedpdf = $tempdir . '/' .
        pathinfo($pptx->get_filename(), PATHINFO_FILENAME) .
        '.pdf';

    if ($returncode !== 0 || !file_exists($generatedpdf)) {
        throw new moodle_exception(
            'PPTX PDF dönüşümü başarısız: ' . implode("\n", $output)
        );
    }

    /*
     * Önce geçici PDF'yi cache'e taşı.
     */
    if (!copy($generatedpdf, $pdfpath)) {
        throw new moodle_exception(
            'PPTX PDF cache dosyası oluşturulamadı.'
        );
    }

    @chmod($pdfpath, 0660);
}

/*
 * Hazır PDF'yi direkt gönder.
 */
header('Content-Type: application/pdf');
header(
    'Content-Disposition: inline; filename="' .
    basename($basename . '.pdf') .
    '"'
);
header('Content-Length: ' . filesize($pdfpath));
header('Cache-Control: public, max-age=3600');

readfile($pdfpath);
exit;
