<?php

require_once __DIR__ . '/../../../../core/php/core.inc.php';
require_once __DIR__ . '/../class/apsystemsCloudAPI.class.php';

try {

    /*if (!isConnect()) {
        throw new Exception(__('401 - Accès non autorisé', __FILE__));
    }*/

    if (init('action') == 'testConnection') {

        $app_id     = init('app_id');
        $app_secret = init('app_secret');

        if ($app_id == '' || $app_secret == '') {
            throw new Exception(__('App Id ou App Secret manquant', __FILE__));
        }

        $api = new apsystemsCloudAPI($app_id, $app_secret);
        $api->requestTestConnection();

        ajax::success(__('Connexion réussie', __FILE__));
    }

    if (init('action') == 'getQuota') {

        $quota_month_used = config::byKey('apsystemsCloud_quota_used', 'apsystemsCloud', 0);
        $quota_month_limit = config::byKey('apsystemsCloud_api_quota_month', 'apsystemsCloud', 1000);
        $percent_month = ($quota_month_limit > 0) ? min(100, round(($quota_month_used / $quota_month_limit) * 100)) : 0;

        $quota_day_used = config::byKey('apsystemsCloud_quota_day_used', 'apsystemsCloud', 0);
        $quota_day_limit = config::byKey('apsystemsCloud_quota_day_limit', 'apsystemsCloud', 100);
        $percent_day = ($quota_day_limit > 0) ? min(100, round(($quota_day_used / $quota_day_limit) * 100)) : 0;

        ajax::success([
            'month' => "$quota_month_used / $quota_month_limit ($percent_month%)",
            'day'   => "$quota_day_used / $quota_day_limit ($percent_day%)"
        ]);
    }

} catch (Exception $e) {
    ajax::error($e->getMessage());
}