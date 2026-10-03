<?php

require_once __DIR__ . '/../../../../core/php/core.inc.php';
require_once __DIR__ . '/apsystemsCloudAPI.class.php';

class apsystemsCloud extends eqLogic {

    /* ========== LOG ========== */

    public static function add_log($level = 'debug', $Log) {
        if (is_array($Log)) $Log = json_encode($Log);

        $bt = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
        $line = $bt[0]['line'] ?? '';
        $func = $bt[1]['function'] ?? '';

        $msg = ($func ? "$func ($line)" : "($line)") . " : $Log";
        log::add('apsystemsCloud', $level, $msg);
    }
/* ========== LEVER / COUCHER NATIF ========== */

    public static function getNativeSunTimes() {
        $lat = config::byKey('info::latitude');
        $lon = config::byKey('info::longitude');

        if ($lat == '' || $lon == '') {
            throw new Exception("Latitude/Longitude non configurées dans Jeedom");
        }

        $sun = date_sun_info(time(), floatval($lat), floatval($lon));
        //apsystemsCloud::add_log('info', $sun);
        return [
            'lever'       => $sun['sunrise'],
            'coucher'     => $sun['sunset'],
            'lever_str'   => date('H:i', $sun['sunrise']),
            'coucher_str' => date('H:i', $sun['sunset']),
        ];
    }
    /* ========== CRON JOURNALIER ========== */

    public static function cronDaily() {
        // Reset du quota journalier via commandes
        foreach (self::byType('apsystemsCloud') as $eqLogic) {
            self::config('apsystemsCloud_quota_day_used', 0, true);
            $quota = self::getQuotaStatus();
            $daysLeft = (int) date('t') - (int) date('j') + 1;
            $maxPerDay = max(1, floor($quota['remaining'] / max(1, $daysLeft)));
            self::config('apsystemsCloud_quota_day_limit', $maxPerDay, true);
            if (date('d') == '01') {
                self::config('apsystemsCloud_quota_used', 0, true);
            }
        }
         
    }

    /* ========== CRON 15 MINUTES ========== */

    public static function cron30() {
        foreach (self::byType('apsystemsCloud') as $eqLogic) {
            $eqLogic->cron30Eq($eqLogic);
        }
    }
    

    /* ========== CRON PAR ÉQUIPEMENT ========== */

    public function cron30Eq($eqLogic) {
        $sid = $eqLogic->getConfiguration('plant_id');
        if ($sid == '') {
            self::add_log("error", $eqLogic->getHumanName() . ' : config incomplète (SID)');
            return;
        }
        $api = new apsystemsCloudAPI();
            /*'used'          => $used,
            'limit'         => $limit,
            'remaining'     => $remaining,
            'percent'       => $percent,            
            'day_used'      => $day_used,
            'day_limit'     => $day_limit,
            'day_remaining' => $day_remaining,
        ];*/
        // --- Quota --- 
        $quota = self::getQuotaStatus();
       
        self::add_log("debug", $eqLogic->getHumanName() . " : Qutota utilisé = " . $quota['used'] . " / " . $quota['limit'] . " (" . $quota['percent'] . "%), Quota journalier utilisé = " . $quota['day_used'] . " / " . $quota['day_limit']);
        self::add_log("debug", $eqLogic->getHumanName() . " : Qutota restant du jour = " . $quota['day_remaining'] . ", jours restants dans le mois = " . ((int) date('t') - (int) date('j')));
        
        if ($quota['remaining'] == 0) {
            if (!$eqLogic->getCache('LogQuotaMensuel', false)) {
                self::add_log("debug", $eqLogic->getHumanName() . " : quota Mensuel atteint (" . $quota['used'] . "/". $quota['limit'].')');
                $eqLogic->setCache('LogQuotaMensuel', true);
            }
            return;
        }
        if ($quota['day_used'] >= $quota['day_limit']) {
            if (!$eqLogic->getCache('LogQuotaJournalier', false)) {
                self::add_log("debug", $eqLogic->getHumanName() . " : quota journalier atteint (" . $quota['day_used'] . "/". $quota['day_limit'].')');
                $eqLogic->setCache('LogQuotaJournalier', true);
            }
            
            return;
        }

        // --- Lever / coucher --- 
        try {
            $sun = self::getNativeSunTimes();
        } catch (Exception $e) {
            self::add_log("error", $eqLogic->getHumanName() . ' : ' . $e->getMessage());
            return;
        }

        $now   = time();
       
        // --- Reset quotidien avant lever du soleil --- 
        if ($now < $sun['lever']) {
            $eqLogic->setCache('Recup_infos_après_coucher_soleil', false);
            $eqLogic->setCache('time_last', null);
            $eqLogic->setCache('LogHorsFenetreSolaire', false);
            $eqLogic->setCache('LogQuotaJournalier', false);
            $eqLogic->setCache('LogQuotaMensuel', false);
        }

        // --- ECU_Id --- 
        $eid = $eqLogic->getCache('ECU_Id', 0);
        if ($eid == 0) {
            $details = $api->getSystemDetails($sid);
            $ecuList = $details['ecu'] ?? [];
            if (count($ecuList) > 0) {
                $eid = $ecuList[0];
            }
            $eqLogic->setCache('ECU_Id', $eid);
        }

        $recup_infos = $eqLogic->getCache('Recup_infos_après_coucher_soleil', false);

        // --- HORS FENÊTRE SOLAIRE --- 
        $debut = $sun['lever'] + 30 * 60;
        $fin   = $sun['coucher'] - 30 * 60;

        if ($now < $debut || $now > $fin) {

            if (!$eqLogic->getCache('LogHorsFenetreSolaire', false)) {
                self::add_log("debug", $eqLogic->getHumanName() . ' : en dehors de la fenêtre solaire');
                $eqLogic->setCache('LogHorsFenetreSolaire', true);
            }
            // --- Summary + Meters : une seule fois 90 min après coucher --- 
            if (!$recup_infos & $now > $sun['coucher'] + 90 * 60) {

                self::add_log("debug", $eqLogic->getHumanName() . ' : récupération summary + meters en dehors de la fenêtre solaire');
                $eqLogic->setCache('Recup_infos_après_coucher_soleil', true);

                // --- Summary --- 
                $summary = $api->getSystemSummary($sid);
                if ($summary != '') {
                    $eqLogic->checkAndEvent('energy_day',      $summary['today']);
                    $eqLogic->checkAndEvent('energy_month',    $summary['month']);
                    $eqLogic->checkAndEvent('energy_year',     $summary['year']);
                    $eqLogic->checkAndEvent('energy_lifetime', $summary['lifetime']);
                }

                // --- Meters --- 
                $meters = $api->getMetersSummary($sid, $eid);
                if ($meters != '') {
                    foreach ($meters['month'] as $key => $value) {
                        $eqLogic->checkAndEvent('month_' . $key, $value);
                    }
                    foreach ($meters['year'] as $key => $value) {
                        $eqLogic->checkAndEvent('year_' . $key, $value);
                    }
                    foreach ($meters['today'] as $key => $value) {
                        $eqLogic->checkAndEvent('today_' . $key, $value);
                    }
                    foreach ($meters['lifetime'] as $key => $value) {
                        $eqLogic->checkAndEvent('lifetime_' . $key, $value);
                    }
                }
            }

            return;
        }

        // --- FENÊTRE SOLAIRE : récupération minutely --- 
        if ($eid != 0) {

            $minutely = $api->getEcuEnergyMinutely($sid, $eid);

            if (isset($minutely['data']) && is_array($minutely['data']['power']) && is_array($minutely['data']['time'])) {

                $times  = $minutely['data']['time'];
                $powers = $minutely['data']['power'];

                // --- Dernière heure insérée --- 
                $lastTime = $eqLogic->getCache('time_last', null);
                $lastInserted = 0;

                if ($lastTime !== null) {
                    $lastInserted = strtotime(date('Y-m-d') . ' ' . $lastTime);
                }

                // --- Insérer uniquement les nouvelles valeurs --- 
                foreach ($times as $index => $t) {

                    $timestamp = strtotime(date('Y-m-d') . ' ' . $t);
                    if ($timestamp === false) continue;

                    if ($timestamp <= $lastInserted) continue;

                    $p = (float) $powers[$index];

                    $eqLogic->checkAndEvent('power', $p, $timestamp);

                    // Mise à jour des références
                    $lastInserted = $timestamp;
                    $eqLogic->setCache('time_last', $t);
                }

                // --- Dernière puissance instantanée --- 
                $lastPower = (float) end($powers);
                $eqLogic->checkAndEvent('power', $lastPower);

                // --- Énergie du jour en live --- 
                $todayLive = (float) $minutely['data']['today'];
                $eqLogic->checkAndEvent('energy_day', $todayLive);
            }
        }
        
    }

    public static function config($key, $default = null, $write = false) {
        $namespace = 'apsystemsCloud';

        // Mode écriture explicite
        if ($write) {
            config::save($key, $default, $namespace);
            return $default;
        }

        // Mode lecture avec fallback
        $value = config::byKey($key, $namespace, null);

        // Si la valeur existe → on la retourne
        if ($value !== null) {
            return $value;
        }
        config::save($key, $default, $namespace);
        // Sinon → on retourne la valeur par défaut 
        return $default;
    }



    public static function getQuotaStatus() {

        $eqLogic = eqLogic::byType('apsystemsCloud')[0] ?? null;

        if (!$eqLogic) {
            return [
                'used'          => 0,
                'remaining'     => 0,
                'percent'       => 0,
                'limit'         => 1000,
                'day_used'      => 0,
                'day_limit'     => 30,
                'day_remaining' => 0,
            ];
        }
        $used =  self::config('apsystemsCloud_quota_used', 0);
        $limit =  self::config('apsystemsCloud_api_quota_month', 1000);
        $remaining = max(0, $limit - $used);
        $percent   = min(100, round(($used / $limit) * 100));
        $day_used =  self::config('apsystemsCloud_quota_day_used', 0);
        $day_limit = self::config('apsystemsCloud_quota_day_limit', 30);
        $day_remaining = max(0, $day_limit - $day_used);
       
        return [
            'used'          => $used,
            'limit'         => $limit,
            'remaining'     => $remaining,
            'percent'       => $percent,            
            'day_used'      => $day_used,
            'day_limit'     => $day_limit,
            'day_remaining' => $day_remaining,
        ];
    }

    /* ========== COMMANDES ========== */

    public function postSave() {

        /* --- Commandes existantes --- */
        $this->createOrUpdateCmd('power', 'Puissance instantanée', 'info', 'numeric', 'W', 'POWER');
        $this->createOrUpdateCmd('energy_day', 'Production du jour ECU', 'info', 'numeric', 'KWh', 'DAILY_PRODUCTION');
        $this->createOrUpdateCmd('energy_month', 'Production du mois ECU', 'info', 'numeric', 'KWh', 'PRODUCTION');
        $this->createOrUpdateCmd('energy_year', 'Production de l\'année ECU', 'info', 'numeric', 'KWh', 'PRODUCTION');
        $this->createOrUpdateCmd('energy_lifetime', 'Production totale ECU', 'info', 'numeric', 'KWh', 'PRODUCTION');

        $this->createOrUpdateCmd('refresh', __('Rafraîchir', __FILE__), 'action', 'other');
    }

    private function createOrUpdateCmd($logicalId, $name, $type, $subType, $unite = "", $generic_type = "") {
        $cmd = $this->getCmd(null, $logicalId);
        if (!is_object($cmd)) {
            $cmd = new apsystemsCloudCmd();
            $cmd->setEqLogic_id($this->getId());
            $cmd->setLogicalId($logicalId);
            $cmd->setType($type);
            $cmd->setSubType($subType);
            $cmd->setIsVisible(1);
        }

        $cmd->setName($name);

        if ($type == "info") {
            $cmd->setUnite($unite);
            $cmd->setIsHistorized(1);
            $cmd->setConfiguration('historizeMode', 'none');
            $cmd->setConfiguration('historyPurge', 'never');
            $cmd->setConfiguration('repeatEventManagement', 'always');
            $cmd->setGeneric_type($generic_type);
        }

        $cmd->save();
        return $cmd;
    }

    private function checkAndEvent($logicalId, $value) {
        $cmd = $this->getCmd(null, $logicalId);
        if (!$cmd) {
            $cmd = $this->createOrUpdateCmd($logicalId, $logicalId, 'info', 'numeric');
        }
        $cmd->event($value);
    }
}

class apsystemsCloudCmd extends cmd {

    public function execute($_options = array()) {
        $eqLogic = $this->getEqLogic();
        if (!is_object($eqLogic) || $eqLogic->getIsEnable() != 1) return;

        if ($this->getLogicalId() == 'refresh') {
            $eqLogic->cron30Eq($eqLogic);
        }
    }
}