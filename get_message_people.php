<?php
// WAM Akademi - Mesajlaşma kişi listesi.

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../config.php');

require_login();

global $DB, $USER, $CFG;

header('Content-Type: application/json; charset=utf-8');

$currentuserid = (int)$USER->id;

/**
 * Kullanıcı için temel JSON verisi.
 */

function wam_message_user_data(stdClass $user): array {

    // Moodle'ın kendi online/offline durumunu kullan.
    $isonline = \core_message\helper::show_online_status($user)
        ? \core_message\helper::is_online($user->lastaccess)
        : null;

    $showonlinestatus = !is_null($isonline);

    return [
        'id' => (int)$user->id,
        'userid' => (int)$user->id,
        'name' => fullname($user),
        'fullname' => fullname($user),
        'username' => $user->username,

        'imageurl' => (new moodle_url(
            '/user/pix.php',
            ['user' => $user->id]
        ))->out(false),

        'profileimageurl' => (new moodle_url(
            '/user/pix.php',
            ['user' => $user->id]
        ))->out(false),

        'subname' => '',
        'lastmessage' => '',
        'lastmessagedate' => null,
        'unreadcount' => 0,
        'sentfromcurrentuser' => false,
        'ismuted' => false,
        'isblocked' => false,

        // Gerçek Moodle online durumu.
        'showonlinestatus' => $showonlinestatus,
        'isonline' => (bool)$isonline,
    ];
}

/**
 * Kullanıcının herhangi bir course context içerisinde
 * verilen rollerden birine sahip olup olmadığını kontrol eder.
 */
function wam_user_has_course_role(int $userid, array $roles): bool {
    global $DB;

    if (empty($roles)) {
        return false;
    }

    list($insql, $params) = $DB->get_in_or_equal(
        $roles,
        SQL_PARAMS_NAMED,
        'role'
    );

    $params['userid'] = $userid;
    $params['contextlevel'] = CONTEXT_COURSE;

    $sql = "
        SELECT 1
          FROM {role_assignments} ra
          JOIN {role} r
            ON r.id = ra.roleid
          JOIN {context} ctx
            ON ctx.id = ra.contextid
         WHERE ra.userid = :userid
           AND ctx.contextlevel = :contextlevel
           AND r.shortname {$insql}
    ";

    return $DB->record_exists_sql($sql, $params);
}

/**
 * Kullanıcının WAM rolünü belirler.
 *
 * Öncelik:
 * 1. Site admin
 * 2. Öğretmen
 * 3. Öğrenci
 */
function wam_get_message_role(int $userid): string {

    if (is_siteadmin($userid)) {
        return 'admin';
    }

    if (wam_user_has_course_role(
        $userid,
        ['editingteacher', 'teacher']
    )) {
        return 'teacher';
    }

    if (wam_user_has_course_role(
        $userid,
        ['student']
    )) {
        return 'student';
    }

    return 'other';
}

/**
 * Kabul edilmiş Moodle kişilerini döndürür.
 *
 * message_contacts tablosunu kullanır.
 */
function wam_get_contact_ids(int $userid): array {
    global $DB;

    $contactids = [];

    if (!$DB->get_manager()->table_exists('message_contacts')) {
        return [];
    }

    $records = $DB->get_records(
        'message_contacts',
        ['userid' => $userid]
    );

    foreach ($records as $record) {

        if (isset($record->blocked) && (int)$record->blocked === 1) {
            continue;
        }

        if (!empty($record->contactid)) {
            $contactids[(int)$record->contactid] = true;
        }
    }

    /*
     * Bazı Moodle sürümlerinde ilişki ters yönde de tutulabilir.
     * O yüzden contactid = mevcut kullanıcı olan kayıtları da kontrol ediyoruz.
     */
    $reverse = $DB->get_records(
        'message_contacts',
        ['contactid' => $userid]
    );

    foreach ($reverse as $record) {

        if (isset($record->blocked) && (int)$record->blocked === 1) {
            continue;
        }

        if (!empty($record->userid)) {
            $contactids[(int)$record->userid] = true;
        }
    }

    return array_map(
        'intval',
        array_keys($contactids)
    );
}

$currentrole = wam_get_message_role($currentuserid);
$contactids = wam_get_contact_ids($currentuserid);

$users = $DB->get_records_select(
    'user',
    'deleted = 0
     AND suspended = 0
     AND confirmed = 1
     AND id <> :guestid
     AND id <> :currentuserid',
    [
        'guestid' => 1,
        'currentuserid' => $currentuserid,
    ],
    'firstname ASC, lastname ASC',
    'id,username,firstname,lastname,email,picture,imagealt,lastaccess'
);

$admins = [];
$teachers = [];
$friends = [];

foreach ($users as $user) {

    $userid = (int)$user->id;
    $role = wam_get_message_role($userid);

    if ($role === 'other') {
        continue;
    }

    $data = wam_message_user_data($user);
    $data['role'] = $role;

    /*
     * -----------------------------------------------------
     * YÖNETİCİ GİRİŞİ
     * -----------------------------------------------------
     *
     * Herkesi görür.
     */
    if ($currentrole === 'admin') {

        if ($role === 'admin') {
            $admins[] = $data;
        } elseif ($role === 'teacher') {
            $teachers[] = $data;
        } elseif ($role === 'student') {
            $friends[] = $data;
        }

        continue;
    }

    /*
     * -----------------------------------------------------
     * ÖĞRETMEN GİRİŞİ
     * -----------------------------------------------------
     *
     * Yöneticileri, diğer öğretmenleri ve öğrencileri görür.
     * Öğrenciler burada arkadaşlık şartı olmadan listelenir.
     */
    if ($currentrole === 'teacher') {

        if ($role === 'admin') {
            $admins[] = $data;
        } elseif ($role === 'teacher') {
            $teachers[] = $data;
        } elseif ($role === 'student') {
            $friends[] = $data;
        }

        continue;
    }

    /*
     * -----------------------------------------------------
     * ÖĞRENCİ GİRİŞİ
     * -----------------------------------------------------
     *
     * - Öğretmenleri doğrudan görür.
     * - Diğer öğrencileri sadece kabul edilmiş kişiyse görür.
     * - Yöneticileri normalde görmez.
     * - Yönetici kabul edilmiş kişi olmuşsa gösterilebilir.
     */
    if ($currentrole === 'student') {

        if ($role === 'teacher') {
            $teachers[] = $data;
            continue;
        }

        if (
            $role === 'student' &&
            in_array($userid, $contactids, true)
        ) {
            $friends[] = $data;
            continue;
        }

        if (
            $role === 'admin' &&
            in_array($userid, $contactids, true)
        ) {
            $admins[] = $data;
        }
    }
}

$self = $DB->get_record(
    'user',
    ['id' => $currentuserid],
    'id,username,firstname,lastname,email,picture,imagealt',
    MUST_EXIST
);

$response = [
    'success' => true,

    'currentuser' => [
        'id' => (int)$self->id,
        'fullname' => fullname($self),
        'username' => $self->username,
        'role' => $currentrole,
    ],

    'counts' => [
        'admins' => count($admins),
        'teachers' => count($teachers),
        'friends' => count($friends),
    ],

    'admins' => $admins,
    'teachers' => $teachers,
    'friends' => $friends,
];

echo json_encode(
    $response,
    JSON_UNESCAPED_UNICODE |
    JSON_UNESCAPED_SLASHES |
    JSON_PRETTY_PRINT
);

exit;
