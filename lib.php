<?php

defined('MOODLE_INTERNAL') || die();

/**
 * Öğrenci ve normal kullanıcıları WAM Akademi paneline yönlendirir.
 * Site yöneticileri standart Moodle panelinde kalır.
 */
function local_wamakademi_after_config() {
    global $USER;

    if (!isloggedin() || isguestuser()) {
        return;
    }

    if (is_siteadmin($USER)) {
        return;
    }

    $currentpath = $_SERVER['PHP_SELF'] ?? '';

    if ($currentpath === '/my/index.php' || $currentpath === '/my/') {
        redirect(new moodle_url('/local/wamakademi/index.php'));
    }
}

/**
 * Statik eklenti dosyası için sürüm parametreli URL üret.
 *
 * @param string $relativepath /local/wamakademi/... yolu
 * @return moodle_url
 */
function local_wamakademi_asset_url(string $relativepath): moodle_url {
    global $CFG;
    $full = $CFG->dirroot . $relativepath;
    $params = [];
    if (is_readable($full)) {
        $params['v'] = (string)filemtime($full);
    }
    return new moodle_url($relativepath, $params);
}

/**
 * Profil düzenleme URL'sini Moodle kurallarıyla üret.
 *
 * @param stdClass $user
 * @param bool $iscurrentuser
 * @param int $courseid
 * @return moodle_url|null
 */
function local_wamakademi_edit_profile_url(stdClass $user, bool $iscurrentuser, int $courseid): ?moodle_url {
    global $USER;

    if (!isloggedin() || isguestuser($user) || is_mnet_remote_user($user)) {
        return null;
    }

    $systemcontext = context_system::instance();
    $usercontext = context_user::instance($user->id, IGNORE_MISSING);
    if (!$usercontext) {
        return null;
    }

    if (($iscurrentuser || is_siteadmin($USER) || !is_siteadmin($user))
            && has_capability('moodle/user:update', $systemcontext)) {
        return new moodle_url('/user/editadvanced.php', [
            'id' => $user->id,
            'course' => $courseid,
            'returnto' => 'profile',
        ]);
    }

    if ((has_capability('moodle/user:editprofile', $usercontext) && !is_siteadmin($user))
            || ($iscurrentuser && has_capability('moodle/user:editownprofile', $systemcontext))) {
        $userauthplugin = false;
        if (!empty($user->auth)) {
            $userauthplugin = get_auth_plugin($user->auth);
        }
        if ($userauthplugin && $userauthplugin->can_edit_profile()) {
            $url = $userauthplugin->edit_profile_url();
            if (empty($url)) {
                return new moodle_url('/user/edit.php', [
                    'id' => $user->id,
                    'returnto' => 'profile',
                ]);
            }
            return new moodle_url($url);
        }
    }

    return null;
}

/**
 * Ortak menü / ayarlar yapılandırmasını JS'e aktar.
 *
 * @return array
 */
function local_wamakademi_menu_config(): array {
    global $USER, $COURSE, $PAGE, $CFG, $OUTPUT;

    require_once($CFG->dirroot . '/user/lib.php');

    // Sol panel: yalnızca site gezinmesi (Profil / Site yönetimi yok).
    $links = [
        [
            'id' => 'home',
            'label' => 'Ana Sayfa',
            'url' => (new moodle_url('/local/wamakademi/index.php'))->out(false),
        ],
        [
            'id' => 'catalog',
            'label' => 'Eğitimler',
            'url' => (new moodle_url('/local/wamakademi/catalog.php'))->out(false),
        ],
        [
            'id' => 'mycourses',
            'label' => 'Eğitimlerim',
            'url' => (new moodle_url('/local/wamakademi/mycourses.php'))->out(false),
        ],
        [
            'id' => 'calendar',
            'label' => 'Takvim',
            'url' => (new moodle_url('/local/wamakademi/calendar.php'))->out(false),
        ],
    ];

    $courseid = SITEID;
    if (!empty($COURSE) && !empty($COURSE->id) && (int)$COURSE->id > 1) {
        $courseid = (int)$COURSE->id;
    } else if ($PAGE->context && $PAGE->context->contextlevel >= CONTEXT_COURSE) {
        $coursectx = $PAGE->context->get_course_context(false);
        if ($coursectx) {
            $courseid = (int)$coursectx->instanceid;
        }
    }

    $profileurl = (new moodle_url('/user/profile.php', ['id' => $USER->id]))->out(false);
    $groups = [];

    // Hesabım.
    $accountitems = [
        [
            'id' => 'profile',
            'label' => get_string('profile'),
            'url' => $profileurl,
            'action' => false,
        ],
    ];

    $editurl = local_wamakademi_edit_profile_url($USER, true, $courseid > 1 ? $courseid : SITEID);
    if ($editurl) {
        $accountitems[] = [
            'id' => 'editprofile',
            'label' => get_string('editmyprofile'),
            'url' => $editurl->out(false),
            'action' => false,
        ];
    }

    $usercontext = context_user::instance($USER->id);
    if (has_capability('moodle/user:manageownfiles', $usercontext)) {
        $accountitems[] = [
            'id' => 'privatefiles',
            'label' => get_string('privatefiles'),
            'url' => (new moodle_url('/user/files.php'))->out(false),
            'action' => false,
        ];
    }

    $groups[] = [
        'id' => 'account',
        'label' => 'Hesabım',
        'items' => $accountitems,
    ];

    // Öğrenme — customusermenuitems hedeflerini kullan.
    $learningitems = [
        [
            'id' => 'grades',
            'label' => get_string('grades', 'grades'),
            'url' => user_mygrades_url()->out(false),
            'action' => false,
        ],
        [
            'id' => 'calendar',
            'label' => get_string('calendar', 'calendar'),
            'url' => (new moodle_url('/local/wamakademi/calendar.php'))->out(false),
            'action' => false,
        ],
    ];

    if (has_capability('moodle/reportbuilder:view', context_system::instance())
            || has_capability('moodle/reportbuilder:viewall', context_system::instance())) {
        $learningitems[] = [
            'id' => 'reports',
            'label' => get_string('reports'),
            'url' => (new moodle_url('/reportbuilder/index.php'))->out(false),
            'action' => false,
        ];
    }

    $groups[] = [
        'id' => 'learning',
        'label' => 'Öğrenme',
        'items' => $learningitems,
    ];

    // Tercihler + Dil.
    $prefitems = [
        [
            'id' => 'preferences',
            'label' => get_string('preferences'),
            'url' => (new moodle_url('/user/preferences.php'))->out(false),
            'action' => false,
        ],
    ];

    $langitems = [];
    try {
        $languagemenu = new \core\output\language_menu($PAGE);
        $langdata = $languagemenu->export_for_template($OUTPUT);
        if (!empty($langdata['items'])) {
            foreach ($langdata['items'] as $item) {
                $url = $item['url'];
                if ($url instanceof moodle_url) {
                    $url = $url->out(false);
                } else if (is_object($url) && method_exists($url, 'out')) {
                    $url = $url->out(false);
                } else {
                    $url = (string)$url;
                }
                $langitems[] = [
                    'id' => 'lang_' . preg_replace('/[^a-z0-9_-]/i', '', (string)($item['title'] ?? 'x')),
                    'label' => (string)($item['title'] ?? $item['text'] ?? ''),
                    'url' => $url,
                    'active' => !empty($item['isactive']),
                    'action' => false,
                ];
            }
        }
    } catch (Throwable $e) {
        $langitems = [];
    }

    $groups[] = [
        'id' => 'preferences',
        'label' => 'Tercihler',
        'items' => $prefitems,
        'languages' => $langitems,
        'languageLabel' => get_string('language'),
    ];

    // Yönetim.
    $adminitems = [];
    if ($courseid > 1) {
        $coursecontext = context_course::instance($courseid);
        if (has_capability('moodle/course:update', $coursecontext)) {
            $adminitems[] = [
                'id' => 'courseedit',
                'label' => 'Kurs ayarları',
                'url' => (new moodle_url('/course/edit.php', ['id' => $courseid]))->out(false),
                'action' => false,
            ];
        }
    }
    if (has_capability('moodle/site:config', context_system::instance())) {
        $adminitems[] = [
            'id' => 'admin',
            'label' => 'Site yönetimi',
            'url' => (new moodle_url('/admin/search.php'))->out(false),
            'action' => false,
        ];
    }
    if (!empty($adminitems)) {
        $groups[] = [
            'id' => 'management',
            'label' => 'Yönetim',
            'items' => $adminitems,
        ];
    }

    // Oturum: tarayıcı oturumları + işlemler.
    $sessionitems = [];
    $actionitems = [];

    if (!\core\session\manager::is_loggedinas()
            && has_capability('report/usersessions:manageownsessions', $usercontext)) {
        $sessionitems[] = [
            'id' => 'usersessions',
            'label' => get_string('navigationlink', 'report_usersessions'),
            'url' => (new moodle_url('/report/usersessions/user.php'))->out(false),
            'action' => false,
        ];
    }

    // Moodle kullanıcı menüsünden işlemleri ve bilinmeyen özel öğeleri al.
    $knownurls = [];
    foreach ($groups as $g) {
        foreach (($g['items'] ?? []) as $it) {
            $knownurls[] = preg_replace('/[?#].*$/', '', (string)$it['url']);
        }
    }
    $knownurls[] = preg_replace('/[?#].*$/', '', $profileurl);

    $info = user_get_user_navigation_info($USER, $PAGE);
    $otheritems = [];
    foreach ($info->navitems as $nav) {
        if (($nav->itemtype ?? '') === 'divider' || ($nav->itemtype ?? '') === 'invalid') {
            continue;
        }
        $url = isset($nav->url) ? (string)$nav->url : '';
        $pathonly = preg_replace('/[?#].*$/', '', $url);
        $title = (string)($nav->title ?? '');
        $identifier = (string)($nav->titleidentifier ?? '');

        $islogout = (strpos($identifier, 'logout') !== false)
            || (strpos($pathonly, '/login/logout.php') !== false)
            || (strpos($pathonly, '/course/loginas.php') !== false && empty(optional_param('user', 0, PARAM_INT)));
        $isswitch = (strpos($identifier, 'switchrole') !== false)
            || (strpos($pathonly, '/course/switchrole.php') !== false);

        if ($islogout || $isswitch) {
            $actionitems[] = [
                'id' => $isswitch ? 'switchrole' : 'logout',
                'label' => $title,
                'url' => $url,
                'action' => true,
            ];
            continue;
        }

        if (($nav->itemtype ?? '') !== 'link' || $url === '') {
            continue;
        }

        // Tercihler ve bilinen hesap bağlantılarını atla.
        $skip = false;
        foreach ($knownurls as $known) {
            if ($known !== '' && strpos($pathonly, $known) !== false) {
                $skip = true;
                break;
            }
        }
        if ($skip || strpos($pathonly, '/user/preferences.php') !== false) {
            continue;
        }

        // Tanınmayan özel menü / kanca öğeleri — Betik ekle dahil korunur.
        $otheritems[] = [
            'id' => 'custom_' . substr(sha1($pathonly . $title), 0, 8),
            'label' => $title !== '' ? $title : $pathonly,
            'url' => $url,
            'action' => false,
        ];
    }

    if (!empty($otheritems)) {
        $groups[] = [
            'id' => 'other',
            'label' => 'Diğer',
            'items' => $otheritems,
        ];
    }

    $groups[] = [
        'id' => 'session',
        'label' => 'Oturum',
        'items' => $sessionitems,
        'actions' => $actionitems,
    ];

    $banner = '';
    if (!empty($info->metadata['asotheruser'])) {
        $banner = get_string('loggedinas', 'moodle', $info->metadata['userfullname'] ?? '');
    } else if (!empty($info->metadata['asotherrole']) && !empty($info->metadata['rolename'])) {
        $banner = (string)$info->metadata['rolename'];
    }

    $unreadlabel = get_string('unreadconversations', 'core_message', '{$a}');

    $path = $_SERVER['REQUEST_URI'] ?? '';
    $active = 'home';
    if (strpos($path, '/local/wamakademi/mycourses.php') !== false) {
        $active = 'mycourses';
    } else if (strpos($path, '/local/wamakademi/catalog.php') !== false) {
        $active = 'catalog';
    } else if (
        strpos($path, '/local/wamakademi/calendar.php') !== false ||
        strpos($path, '/local/wamakademi/js/calendar_view.php') !== false
    ) {
        $active = 'calendar';
    } else if (strpos($path, '/local/wamakademi/index.php') !== false) {
        $active = 'home';
    }

    // Geriye dönük uyumluluk: düz settings listesi (eski JS).
    $settings = [];
    foreach ($groups as $g) {
        foreach (($g['items'] ?? []) as $it) {
            $settings[] = $it;
        }
        foreach (($g['actions'] ?? []) as $it) {
            $settings[] = $it;
        }
    }

    return [
        'wwwroot' => (new moodle_url('/'))->out(false),
        'homeurl' => (new moodle_url('/local/wamakademi/index.php'))->out(false),
        'profileurl' => $profileurl,
        'active' => $active,
        'links' => $links,
        'settings' => $settings,
        'groups' => $groups,
        'banner' => $banner,
        'unreadConversationsTemplate' => $unreadlabel,
    ];
}

/**
 * Hesap sayfaları (profil / düzenle / tercihler) için JS yapılandırması.
 *
 * @return array
 */
function local_wamakademi_account_config(): array {
    global $USER, $PAGE;

    $profileuserid = (int)$USER->id;
    if ($PAGE->context && $PAGE->context->contextlevel === CONTEXT_USER) {
        $profileuserid = (int)$PAGE->context->instanceid;
    }
    $idparam = optional_param('id', 0, PARAM_INT);
    if ($idparam > 0 && (strpos((string)$PAGE->pagetype, 'user-') === 0)) {
        $profileuserid = $idparam;
    }

    $mycoursesurl = (new moodle_url('/local/wamakademi/mycourses.php'))->out(false);

    return [
        'currentUserId' => (int)$USER->id,
        'profileUserId' => $profileuserid,
        'pagetype' => (string)$PAGE->pagetype,
        'mycoursesUrl' => $mycoursesurl,
        'strings' => [
            'editprofile' => get_string('editmyprofile'),
            'advanced' => 'Gelişmiş ayarlar',
            'account' => 'Kullanıcı hesabı',
            'notifications' => 'Bildirimler ve mesajlar',
            'basic' => 'Temel bilgiler',
            'location' => 'Konum',
            'picture' => get_string('pictureofuser'),
            'description' => get_string('userdescription'),
            'thissession' => get_string('thissession', 'report_usersessions'),
            'thisdevice' => 'Bu cihaz',
            'nogradeyet' => 'Henüz not girilmedi',
            'gradesempty' => 'Henüz notlandırılmış eğitimin yok. Notlar, eğitmen değerlendirme girdikçe burada görünür.',
            'mycourses' => 'Eğitimlerim',
            'logout' => get_string('logout'),
        ],
    ];
}

/**
 * Profil ağacına WAM işaret düğümleri ekle (CSS sırası ve başlık verisi).
 *
 * @param \core_user\output\myprofile\tree $tree
 * @param stdClass $user
 * @param bool $iscurrentuser
 * @param stdClass|null $course
 */
function local_wamakademi_myprofile_navigation($tree, $user, $iscurrentuser, $course) {
    // sort_categories çağrılmadan önce düğümler tree->nodes üzerinde durur.
    $categories = $tree->categories;
    $occupied = [];
    foreach ($tree->nodes as $existing) {
        $occupied[$existing->parentcat] = true;
    }

    $markernames = [
        'contact',
        'coursedetails',
        'badges',
        'miscellaneous',
        'reports',
        'administration',
        'loginactivity',
        'privacyandpolicies',
    ];

    foreach ($markernames as $catname) {
        if (empty($categories[$catname]) || empty($occupied[$catname])) {
            continue;
        }
        $marker = new \core_user\output\myprofile\node(
            $catname,
            'wam_marker_' . $catname,
            '',
            null,
            null,
            html_writer::span('', 'wam-acc-marker', [
                'data-wam-marker' => '1',
                'data-wam-cat' => $catname,
                'aria-hidden' => 'true',
            ])
        );
        try {
            $tree->add_node($marker);
        } catch (Throwable $e) {
            // Zaten eklenmiş olabilir.
        }
    }

    // Bölüm bilgisi — kendi profilinde başlık için.
    if ($iscurrentuser && !empty($user->department)) {
        try {
            $deptnode = new \core_user\output\myprofile\node(
                'contact',
                'wam_header_department',
                '',
                null,
                null,
                html_writer::span(format_string($user->department), 'wam-acc-dept-source', [
                    'data-wam-department' => '1',
                    'hidden' => 'hidden',
                ])
            );
            $tree->add_node($deptnode);
        } catch (Throwable $e) {
            // Yok say.
        }
    }
}

/**
 * WAM Akademi ortak head kancası.
 */
function local_wamakademi_before_standard_html_head() {
    global $PAGE, $USER;

    $pagetype = (string)$PAGE->pagetype;

    // Giriş sayfası stili — yalnızca login-index.
    $logincss = __DIR__ . '/login.css';
    $csspath = '/local/wamakademi/login.css';
    if (!is_readable($logincss)) {
        $logincss = __DIR__ . '/js/login.css';
        $csspath = '/local/wamakademi/js/login.css';
    }
    if ($pagetype === 'login-index' && is_readable($logincss)) {
        $PAGE->requires->css(new moodle_url($csspath, [
            'v' => filemtime($logincss),
        ]));
    }

    if (isloggedin() && !isguestuser()) {
        $PAGE->requires->js_call_amd(
            'local_wamakademi/message_people',
            'init'
        );

        // Hesap ekranları ortak CSS (çark + rozet + sayfa stilleri).
        $PAGE->requires->css(
            local_wamakademi_asset_url('/local/wamakademi/js/account.css')
        );

        $PAGE->requires->data_for_js('wamMenuConfig', local_wamakademi_menu_config(), true);
        $PAGE->requires->js(
            local_wamakademi_asset_url('/local/wamakademi/js/wam_menu.js')
        );

        $accountpages = [
            'user-profile',
            'user-edit',
            'user-preferences',
            'grade-report-overview-index',
            'local-wamakademi-calendar',
            'report-usersessions-user',
        ];
        if (in_array($pagetype, $accountpages, true)) {
            $PAGE->requires->data_for_js('wamAccountConfig', local_wamakademi_account_config(), true);
            $PAGE->requires->js(
                local_wamakademi_asset_url('/local/wamakademi/js/account_ui.js')
            );
        }
    }

    if (strpos($pagetype, 'course-view-') === 0) {
        $PAGE->requires->js(
            local_wamakademi_asset_url('/local/wamakademi/js/course_view.js')
        );
    }

    /*
     * Aşağıdaki bölüm sadece video/resource ders sayfalarında çalışır.
     * Sıralı kilit yönlendirmesi kaldırıldı; kayıt ve görünürlük Moodle'da kalır.
     */
    if ($pagetype !== 'mod-resource-view') {
        return;
    }

    $currentcmid = optional_param('id', 0, PARAM_INT);
    if ($currentcmid <= 0) {
        return;
    }

    $PAGE->requires->js(
        local_wamakademi_asset_url('/local/wamakademi/js/video_tracker.js')
    );

    $PAGE->requires->css(
        local_wamakademi_asset_url('/local/wamakademi/lesson.css')
    );

    $PAGE->requires->js(
        local_wamakademi_asset_url('/local/wamakademi/js/lesson_layout.js')
    );
}
