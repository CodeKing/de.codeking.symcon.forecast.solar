<?php

define('__ROOT__', dirname(dirname(__FILE__)));
require_once(__ROOT__ . '/libs/helpers/autoload.php');

/**
 * Class ForecastSolar
 * IP-Symcon Forecast.Solar
 *
 * @version     0.1
 * @category    Symcon
 * @package     de.codeking.symcon.forecast.solar
 * @author      Frank Herrmann <frank@herrmann.to>
 * @link        https://herrmann.to
 * @link        https://github.com/CodeKing/de.codeking.symcon.forecast.solar
 *
 */
class ForecastSolar extends Module
{
    use InstanceHelper;

    public $data = [];

    private $api = 'https://api.forecast.solar/estimate/:lat/:lon/:dec/:az/:kwp';

    private $dec;
    private $az;
    private $kwp;

    protected $profile_mappings = [
        'total earnings today' => '~Power',
        'max. earnings per hour today' => '~Power',
        'total earnings tomorrow' => '~Power',
        'max. earnings per hour tomorrow' => '~Power'
    ];

    /**
     * create instance
     */
    public function Create()
    {
        parent::Create();

        // register public properties
        $this->RegisterPropertyInteger('dec', 25); // 0°-90°
        $this->RegisterPropertyFloat('az', 0); // -180°-180°  (-180 = north, -90 = east, 0 = south, 90 = west, 180 = north)
        $this->RegisterPropertyFloat('kwp', 5.9); // modules power in kilo watt

        // register update timer
        $this->RegisterTimer('UpdateData', 0, $this->_getPrefix() . '_Update($_IPS[\'TARGET\']);');
        $this->Update();
    }

    /**
     * run initially update when kernel is ready
     */
    public function onKernelReady()
    {
        // check configuration data
        $validConfig = $this->ReadConfig();

        // update timer
        if ($validConfig) {
            $this->SetTimerInterval('UpdateData', 3600 * 1000);
            $this->Update();
        }
    }

    /**
     * Read config
     * @return bool
     */
    private function ReadConfig(): bool
    {
        $this->dec = $this->ReadPropertyInteger('dec');
        $this->az = $this->ReadPropertyFloat('az');
        $this->kwp = $this->ReadPropertyFloat('kwp');

        return $this->dec && $this->az && $this->kwp;
    }

    /**
     * Update & save data
     * @return bool
     */
    public function Update()
    {
        // read config
        $this->ReadConfig();

        // update estimate data
        if (!$this->UpdateEstimate()) {
            return false;
        }

        // save data
        $this->SaveData();

        return true;
    }

    /**
     * Update manually via instance button
     */
    public function UpdateManually()
    {
        if ($this->Update()) {
            echo 'OK';
        } else {
            echo 'Error';
        }
    }

    /**
     * Update aWATTar prices for the next 24h
     * @return bool
     */
    private function UpdateEstimate()
    {
        // get current data
        if ($data = $this->GetData()) {
            // convert yaml to array
            $mapped_data = [];
            foreach (explode("\n", $data) AS $line) {
                if (strlen($line) > 5) {
                    list($key, $value) = explode(';', $line);

                    // trim pairs
                    $key = trim($key);
                    $value = trim($value);

                    // get date from key
                    list($key, $date) = explode('.', $key);

                    // create array
                    if (!isset($mapped_data[$key])) {
                        $mapped_data[$key] = [];
                    }

                    // append value
                    $mapped_data[$key][$date] = (float)$value / 1000;
                }
            }

            // map data
            $this->data = [
                'total earnings today' => 0,
                'total earnings tomorrow' => 0,
                'max. earnings per hour today' => 0,
                'max. earnings per hour tomorrow' => 0
            ];

            if (isset($mapped_data['watts'])) {
                foreach ($mapped_data AS $key => $values) {
                    switch ($key):
                        case 'watts':
                        case 'watt_hours_day':
                            foreach ($values AS $date => $value) {
                                $data_key = date('Y-m-d') == date('Y-m-d', strtotime($date))
                                    ? ($key == 'watts' ? 'max. earnings per hour today' : 'total earnings today')
                                    : ($key == 'watts' ? 'max. earnings per hour tomorrow' : 'total earnings tomorrow');

                                if ($value > $this->data[$data_key]) {
                                    $this->data[$data_key] = $value;
                                }
                            }
                            break;
                    endswitch;

                }
            }

            return true;
        }

        return false;
    }

    /**
     * save data to variables
     */
    private function SaveData()
    {
        // loop  data and append variables to instance
        $position = 0;
        foreach ($this->data AS $key => $value) {
            $this->CreateVariableByIdentifier([
                'parent_id' => $this->InstanceID,
                'name' => $key,
                'value' => $value,
                'position' => $position
            ]);
            $position++;
        }
    }

    /**
     * get current aWATTar data
     * @return array|bool
     */
    private function GetData()
    {
        // get lat / lon by location module
        $location_id = $this->_getLocationId();

        // get lat / lon on symcon 5.x
        if (IPS_GetKernelVersion() >= 5) {
            $location = json_decode(IPS_GetProperty($location_id, 'Location'), true);
            $location_lat = isset($location['latitude']) ? $location['latitude'] : false;
            $location_lon = isset($location['longitude']) ? $location['longitude'] : false;
        } // get lat / lon on symcon 4.x
        else {
            $location_lat = IPS_GetProperty($location_id, 'Latitude');
            $location_lon = IPS_GetProperty($location_id, 'Longitude');
        }

        if (!$location_lat || !$location_lon) {
            $this->SetStatus(201);
            return false;
        } else {
            $location_lat = str_replace(',', '.', $location_lat);
            $location_lon = str_replace(',', '.', $location_lon);
        }

        // modify endpoint params
        $endpoint = strtr($this->api, [
            ':lat' => $location_lat,
            ':lon' => $location_lon,
            ':dec' => $this->dec,
            ':az' => $this->az,
            ':kwp' => str_replace(',', '.', $this->kwp)
        ]);

        $this->_log('Forecast.Solar', 'Endpoint: ' . $endpoint);

        // curl options
        $curlOptions = [
            CURLOPT_TIMEOUT => 60,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HTTPHEADER => [
                'Content-Type: text/csv',
                'Accept: text/csv',
                'User-Agent: IP_Symcon'
            ]
        ];

        // call api
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, $curlOptions);
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // log response
        $this->_log('Forecast.Solar', $response);

        // check & response data
        if ($http_code == 200 && $response && strstr($response, 'watt_hours_day')) {
            $this->SetStatus(102);

            $next_timer = strtotime(date('Y-m-d H:00:05', strtotime('+1 hour')));
            $this->SetTimerInterval('UpdateData', ($next_timer - time()) * 1000); // every hour
            return $response;
        } else if ($http_code == 429) {
            $this->SetStatus(202);

            $next_timer = strtotime(date('Y-m-d H:00:05', strtotime('+1 hour')));
            $this->SetTimerInterval('UpdateData', ($next_timer - time()) * 1000); // every hour
            return false;
        } else {
            $this->SetStatus(200);
            return false;
        }
    }
}