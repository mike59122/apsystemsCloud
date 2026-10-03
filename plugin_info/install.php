<?php

class apsystemsCloud_install {

    public static function install() {
       config::save('username', '', 'apsystemsCloud');
       config::save('password', '', 'apsystemsCloud');
       config::save('apsystemsCloud_mode', 'normal', 'apsystemsCloud');
       config::save('apsystemsCloud_api_quota_month', 1000, 'apsystemsCloud');

    }

    public static function update() {
    }

    public static function remove() {
    }
}