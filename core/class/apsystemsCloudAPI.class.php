<?php
require_once __DIR__ . '/apsystemsCloud.class.php';
class apsystemsCloudAPI {

    private $appId;
    private $appSecret;
    private $baseUrl = 'https://api.apsystemsema.com:9282';
    public function __construct($appId = null, $appSecret = null) {
        $this->appId     = trim($appId     ?? config::byKey('app_id', 'apsystemsCloud', ''));
        $this->appSecret = trim($appSecret ?? config::byKey('app_secret', 'apsystemsCloud', ''));

        if ($this->appId === '' || $this->appSecret === '') {
            throw new Exception(__('App Id / App Secret manquants', __FILE__));
        }
    }


    /* ================== QUOTA ================== */
    private function incrementQuotaCounter() {
        $counters = [
            'apsystemsCloud_quota_used'     => 0,
            'apsystemsCloud_quota_day_used' => 0,
        ];

        foreach ($counters as $cacheKey => $defaultValue) {
            // Lecture avec valeur par défaut
            $count = apsystemsCloud::config($cacheKey, $defaultValue);
            // Incrément
            $count++;
            // Écriture explicite
            apsystemsCloud::config($cacheKey, $count, true);
            apsystemsCloud::add_log('debug', "Incrémentation du compteur $cacheKey : $count");
        }
        $used = apsystemsCloud::config('apsystemsCloud_quota_used', 0);
        $limit = config::byKey('apsystemsCloud_api_quota_month', 'apsystemsCloud', 1000);
        $remaining = max(0, $limit - $used);
        apsystemsCloud::config('quota_remaining', $remaining, true);
        
    }




    

    /* ================== SIGNATURE ================== */

    private function buildSignature($timestamp, $nonce, $requestPath, $method = 'GET') {
        $stringToSign = $timestamp . '/' . $nonce . '/' . $this->appId . '/' . $requestPath . '/' . strtoupper($method) . '/HmacSHA256';
        $raw = hash_hmac('sha256', $stringToSign, $this->appSecret, true);
        return base64_encode($raw);
    }

    /* ================== REQUEST ================== */
   
    public function request($method, $path, $query = []) {
    
        $timestamp = (int) (microtime(true) * 1000);
        $nonce = bin2hex(random_bytes(16));

        $segments = explode('/', trim($path, '/'));
        $requestPath = end($segments);

        $signature = $this->buildSignature($timestamp, $nonce, $requestPath, $method);

        $headers = [
            'Content-Type: application/json',
            'X-CA-AppId: ' . $this->appId,
            'X-CA-Timestamp: ' . $timestamp,
            'X-CA-Nonce: ' . $nonce,
            'X-CA-Signature-Method: HmacSHA256',
            'X-CA-Signature: ' . $signature,
        ];

        $url = $this->baseUrl . $path;
        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
       
        // Sauvegarde brute du JSON dans un fichier
        $logDir = __DIR__ . '/json_logs';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0775, true);
        }

        $cleanPath = str_replace('/', '_', trim($path, '/'));
        $filename = $logDir . '/' . $cleanPath . '_' . date('Ymd_His') . '.json';

        file_put_contents($filename, $result);
        // (PHP_VERSION_ID >= 80000) {
        //    unset($ch);
        //} else {
            curl_close($ch);
       // }
        apsystemsCloud::add_log('debug', 'Request URL: ' . $url);
        apsystemsCloud::add_log('debug', 'Headers: ' . json_encode($headers));
        apsystemsCloud::add_log('debug', 'HTTP code: ' . $httpCode . ' Response: ' . $result);

        if ($curlErr) {
            throw new Exception("Erreur cURL : " . $curlErr);
        }

        

        if ($httpCode < 200 || $httpCode >= 300) {
            throw new Exception("Erreur HTTP APsystems : " . $httpCode . ' - ' . $result);
        }
        
        $this->incrementQuotaCounter();
        
        $data = json_decode($result, true);
        if ($data === null) {
            throw new Exception("Réponse JSON invalide : " . $result);
        }
        if (!is_array($data)) {
            throw new Exception('Réponse APsystems invalide: ' . $result);
        }

        if (isset($data['code']) && $data['code'] != 0) {

            if ($data['code'] == 2005) {

                $limit = (int) config::byKey('apsystemsCloud_api_quota_month', 'apsystemsCloud', 1000);

                $eq = eqLogic::byType('apsystemsCloud')[0] ?? null;
                if ($eq) {
                    $cmdUsed = $eq->getCmd(null, 'quota_used');
                    if ($cmdUsed) {
                        $cmdUsed->event($limit);
                    }
                }

                throw new Exception("Quota mensuel atteint");
            }
            throw new Exception("Erreur API APsystems : " . $data['code']);

            
        }
         return $data['data'] ?? $data;
    }

    

    /* ========== TEST CONNEXION ========== */

    public function requestTestConnection() {
        return $this->request('GET', '/user/api/v2/systems');
    }

    /* ========== ENDPOINTS ========== */

    public function getSystemDetails($sid) {
        return $this->request('GET', '/user/api/v2/systems/details/' . urlencode($sid));
    }

    public function getSystemSummary($sid) {
        return $this->request('GET', '/user/api/v2/systems/summary/' . urlencode($sid));
    }

    public function getSystemEnergy($sid, $level = 'daily', $dateRange = null) {
        $query = ['energy_level' => $level];
        if ($dateRange !== null) {
            $query['date_range'] = $dateRange;
        }
        return $this->request('GET', '/user/api/v2/systems/energy/' . urlencode($sid), $query);
    }
    public function getEcuEnergyMinutely($sid, $eid) {
        return $this->request('GET', '/user/api/v2/systems/' . urlencode($sid) . '/devices/ecu/energy/' . urlencode($eid), [
            'energy_level' => 'minutely',
            'date_range'   => date('Y-m-d')
        ]);
    }
    public function getSystemInverters($sid) {
        return $this->request('GET', '/user/api/v2/systems/inverters/' . urlencode($sid));
    }

    public function getLevelInverter($sid, $eid) {
        return $this->request('GET', '/user/api/v2/systems/' . urlencode($sid) . '/devices/inverter/summary/' . urlencode($eid));
    }

    public function getMetersSummary($sid, $eid) {
        return $this->request('GET', '/user/api/v2/systems/' . urlencode($sid) . '/devices/meter/summary/' . urlencode($eid));
    }
    

    
}