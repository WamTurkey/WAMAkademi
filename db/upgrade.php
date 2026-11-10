<?php

defined('MOODLE_INTERNAL') || die();

function xmldb_local_wamakademi_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026082603) {

        $table = new xmldb_table('local_wamakademi_progress');

        $table->add_field(
            'id',
            XMLDB_TYPE_INTEGER,
            '10',
            null,
            XMLDB_NOTNULL,
            XMLDB_SEQUENCE,
            null
        );

        $table->add_field(
            'userid',
            XMLDB_TYPE_INTEGER,
            '10',
            null,
            XMLDB_NOTNULL,
            null,
            null
        );

        $table->add_field(
            'cmid',
            XMLDB_TYPE_INTEGER,
            '10',
            null,
            XMLDB_NOTNULL,
            null,
            null
        );

        $table->add_field(
            'currenttime',
            XMLDB_TYPE_NUMBER,
            '10,2',
            null,
            XMLDB_NOTNULL,
            null,
            '0'
        );

        $table->add_field(
            'duration',
            XMLDB_TYPE_NUMBER,
            '10,2',
            null,
            XMLDB_NOTNULL,
            null,
            '0'
        );

        $table->add_field(
            'progress',
            XMLDB_TYPE_INTEGER,
            '3',
            null,
            XMLDB_NOTNULL,
            null,
            '0'
        );

        $table->add_field(
            'completed',
            XMLDB_TYPE_INTEGER,
            '1',
            null,
            XMLDB_NOTNULL,
            null,
            '0'
        );

        $table->add_field(
            'timemodified',
            XMLDB_TYPE_INTEGER,
            '10',
            null,
            XMLDB_NOTNULL,
            null,
            '0'
        );

        $table->add_key(
            'primary',
            XMLDB_KEY_PRIMARY,
            ['id']
        );

        $table->add_index(
            'userid_cmid_uix',
            XMLDB_INDEX_UNIQUE,
            ['userid', 'cmid']
        );

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(
            true,
            2026082603,
            'local',
            'wamakademi'
        );
    }

if ($oldversion < 2026082604) {

    $table = new xmldb_table('local_wamakademi_progress');

    $field = new xmldb_field(
        'watchedseconds',
        XMLDB_TYPE_NUMBER,
        '10,2',
        null,
        XMLDB_NOTNULL,
        null,
        '0',
        'duration'
    );

    if (!$dbman->field_exists($table, $field)) {
        $dbman->add_field($table, $field);
    }

    upgrade_plugin_savepoint(
        true,
        2026082604,
        'local',
        'wamakademi'
    );
}

if ($oldversion < 2026082605) {

    $table = new xmldb_table('local_wamakademi_progress');

    $field = new xmldb_field(
        'watchedranges',
        XMLDB_TYPE_TEXT,
        null,
        null,
        null,
        null,
        null,
        'watchedseconds'
    );

    if (!$dbman->field_exists($table, $field)) {
        $dbman->add_field($table, $field);
    }

    upgrade_plugin_savepoint(
        true,
        2026082605,
        'local',
        'wamakademi'
    );
}

if ($oldversion < 2026082606) {

    $table = new xmldb_table('local_wamakademi_progress');

    $timecreated = new xmldb_field(
        'timecreated',
        XMLDB_TYPE_INTEGER,
        '10',
        null,
        XMLDB_NOTNULL,
        null,
        '0',
        'completed'
    );

    if (!$dbman->field_exists($table, $timecreated)) {
        $dbman->add_field($table, $timecreated);
    }

    $timecompleted = new xmldb_field(
        'timecompleted',
        XMLDB_TYPE_INTEGER,
        '10',
        null,
        XMLDB_NOTNULL,
        null,
        '0',
        'timecreated'
    );

    if (!$dbman->field_exists($table, $timecompleted)) {
        $dbman->add_field($table, $timecompleted);
    }

    /*
     * Eski kayıtlar için yaklaşık başlangıç zamanı.
     * Gerçek ilk başlangıç geçmişte tutulmadığı için
     * mevcut timemodified değerini kullanıyoruz.
     */
    $DB->execute("
        UPDATE {local_wamakademi_progress}
           SET timecreated = timemodified
         WHERE timecreated = 0
           AND watchedseconds > 0
    ");

    /*
     * Daha önce tamamlanmış videolar için yaklaşık
     * tamamlama zamanı.
     */
    $DB->execute("
        UPDATE {local_wamakademi_progress}
           SET timecompleted = timemodified
         WHERE timecompleted = 0
           AND completed = 1
    ");

    upgrade_plugin_savepoint(
        true,
        2026082606,
        'local',
        'wamakademi'
    );
}

    return true;
}
