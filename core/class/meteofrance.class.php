<?php

/* This file is part of Jeedom.
*
* Jeedom is free software: you can redistribute it and/or modify
* it under the terms of the GNU General Public License as published by
* the Free Software Foundation, either version 3 of the License, or
* (at your option) any later version.
*
* Jeedom is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
* GNU General Public License for more details.
*
* You should have received a copy of the GNU General Public License
* along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
*/
require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';

class meteofrance extends eqLogic {
  public static $_widgetPossibility = array('custom' => true);
  public static $_vigilanceType = array (
    1 => array("txt" => "Vent","icon" => "wi-strong-wind"),
    2 => array("txt" => "Pluie","icon" => "wi-rain-wind"),
    3 => array("txt" => "Orages","icon" => "wi-lightning"),
    4 => array("txt" => "Crues","icon" => "wi-flood"),
    5 => array("txt" => "Neige-verglas","icon" => "wi-snow"),
    6 => array("txt" => "Canicule","icon" => "wi-hot"),
    7 => array("txt" => "Grand-froid","icon" => "wi-thermometer-exterior"),
    8 => array("txt" => "Avalanches","icon" => "wi-na"),
    9 => array("txt" => "Vagues-submersion","icon" => "wi-tsunami"),
    // 10 => array("txt" => "Incendie","icon" => "wi-fire")
  );
  public static $_vigilanceColors = array (
    0 => array("desc" => "Non défini","color" => "#888888"),
    array("desc" => "Vert","color" => "#31AA35"),
    array("desc" => "Jaune","color" => "#FFF600"),
    array("desc" => "Orange","color" => "#FFB82B"),
    array("desc" => "Rouge","color" => "#CC0000"),
  );

  public static function backupExclude() { return(array('data/*.json')); }

  public function checkAndUpdateCmd($_logicalId, $_value, $_updateTime = null) {
		$cmd = $this->getCmd('info', $_logicalId);
		if (!is_object($cmd)) {
      log::add(__CLASS__, 'debug', "Equipment: " .$this->getName() ." Unexistant command $_logicalId");
    }
    parent::checkAndUpdateCmd($_logicalId, $_value, $_updateTime);
  }

  public static function getJsonInfo($cmd_id, $request) {
    $id = cmd::humanReadableToCmd('#' .$cmd_id .'#');
    $cmd = cmd::byId(trim(str_replace('#', '', $id)));
    if(is_object($cmd)) {
      $cmdjson = str_replace('&quot;','"',$cmd->execCmd());
      $json =json_decode($cmdJson,true);
      if($json === null)
        log::add(__CLASS__, 'debug', "Unable to decode JSON: " .substr($cmdJson,0,50));
      else {
        $tags = explode('>', $request);
        foreach ($tags as $tag) {
          $tag = trim($tag);
          if (isset($json[$tag])) {
            $json = $json[$tag];
          } elseif (is_numeric(intval($tag)) && isset($json[intval($tag)])) {
            $json = $json[intval($tag)];
          } elseif (is_numeric(intval($tag)) && intval($tag) < 0 && isset($json[count($json) + intval($tag)])) {
            $json = $json[count($json) + intval($tag)];
          } else {
            $json = "Request error: tag[$tag] not found in " .json_encode($json);
            break;
          }
        }
        return (is_array($json)) ? json_encode($json) : $json;
      }
    }
    else log::add(__CLASS__, 'debug', "Command not found: $cmd");
    return(null);
  }

  public static function cron5() {
    if(date('i') == 0) return; // will be executed by cronHourly
    foreach (eqLogic::byType(__CLASS__, true) as $meteofrance) {
      $meteofrance->getRain();
      $meteofrance->getHourlyDailyForecasts();
      $meteofrance->refreshWidget();
    }
  }

  /* comments because vigilance has its own cron task
  public static function cron15() {
    if(date('i') == 0) return; // will be executed by cronHourly
    foreach (eqLogic::byType(__CLASS__, true) as $meteofrance) {
      $meteofrance->getVigilance();
      $meteofrance->refreshWidget();
    }
  }
    */

  public static function cronHourly() {
    foreach (eqLogic::byType(__CLASS__, true) as $meteofrance) {
      $meteofrance->getInformations();
    }
  }

  public static function pullDataVigilance($updatePlugin=0) {
    $recup = 1; $ret = 0;
    foreach (eqLogic::byType(__CLASS__, true) as $meteofrance) {
      if($recup) $ret = $meteofrance->getVigilanceDataApiCloudMF();
      if($updatePlugin == 1) break; // Update data only
      $recup = 0;
      if($ret == 0) $meteofrance->getVigilance();
    }
  }

  public static function setCronDataVigilance($create) {
    if($create == 1) {
      $cron = cron::byClassAndFunction(__CLASS__, 'pullDataVigilance');
      if(!is_object($cron)) {
        $cron = new cron();
        $cron->setClass(__CLASS__);
        $cron->setFunction('pullDataVigilance');
        $cron->setEnable(1);
        $cron->setDeamon(0);
      }
      $cron->setSchedule(rand(5,25) .' 6-23 * * *');
      $cron->save();
      meteofrance::pullDataVigilance(1); // update data
    }
    else {
      $cron = cron::byClassAndFunction(__CLASS__, 'pullDataVigilance');
      if(is_object($cron)) {
        $cron->remove();
      }
    }
  }

  public function preSave() {
    $args = $this->getInsee();
    $this->getLocationDetails($args);
    $this->getBulletinDetails($args);
  }

  public function postSave() {
    $cron = cron::byClassAndFunction('meteofrance', 'cronTrigger', array('meteofrance_id' => $this->getId()));
    if (!is_object($cron)) {
      $cron = new cron();
      $cron->setClass('meteofrance');
      $cron->setFunction('cronTrigger');
      $cron->setOption(array('meteofrance_id' => $this->getId()));
    }
    $cron->setOnce(1);
    $time = time() + 90;
    $cron->setSchedule(date('i', $time) . ' ' . date('H', $time) . ' ' . date('d', $time) . ' ' . date('m', $time) . ' * ' . date('Y', $time));
    $cron->save();
  }

  public static function cronTrigger($_options) {
    $meteofrance = meteofrance::byId($_options['meteofrance_id']);
    log::add(__CLASS__, 'debug', "Starting cronTrigger for " .$meteofrance->getName());
    $meteofrance->loadCmdFromConf('bulletin');
    $meteofrance->loadCmdFromConf('bulletinville');
    $meteofrance->loadCmdFromConf('ephemeris');
	  if ($meteofrance->getConfiguration('bulletinCote')) {
      $meteofrance->loadCmdFromConf('marine');
    }
    $meteofrance->loadCmdFromConf('meteo');
    $meteofrance->loadCmdFromConf('rain');
    $meteofrance->loadCmdFromConf('vigilance');
    $eqLogicId = $meteofrance->getId();
    $mfCmd = meteofranceCmd::byEqLogicIdAndLogicalId($eqLogicId, 'refresh'); // L'existance de cette commande est testée dans toHtml
    if (!is_object($mfCmd)) {
      $mfCmd = new meteofranceCmd();
      $mfCmd->setName(__('Rafraichir', __FILE__));
      $mfCmd->setEqLogic_id($eqLogicId);
      $mfCmd->setLogicalId('refresh');
      $mfCmd->setType('action');
      $mfCmd->setSubType('other');
      $mfCmd->save();
    }
    log::add(__CLASS__, 'debug', "End of commands creation. Memory_usage: ".memory_get_usage());
    $meteofrance->getInformations();
  }

  public function getInformations() {
    $this->getRain(); 
    $this->getVigilance();
	  if($this->getConfiguration('bulletinCote')) {
      $this->getMarine();
      $this->getTide();
    }
    $this->getAlerts();
    $this->getEphemeris();
    $this->getBulletinFrance();
    $this->getBulletinSemaine();
    $this->getInstantsValues();
    $this->getBulletinVille();
    $this->getHourlyDailyForecasts();
    $this->refreshWidget();
  }

  public function getInsee() {
    $array = array();
    $array['insee'] = ''; $array['ville'] = ''; $array['zip'] = '';
    $array['lon'] = ''; $array['lat'] = '';
    $geoloc = $this->getConfiguration('geoloc', 'none');
    if ($geoloc == 'none') {
      log::add(__CLASS__, 'debug', 'Localisation non configurée.');
      return $array;
    }
    if ($geoloc == "jeedom") {
      $array['zip'] = config::byKey('info::postalCode');
      $array['ville'] = config::byKey('info::city');
    } else {
      $geotrav = eqLogic::byId($geoloc);
      if (is_object($geotrav) && $geotrav->getEqType_name() == 'geotrav') {
        $geotravCmd = geotravCmd::byEqLogicIdAndLogicalId($geoloc,'location:zip');
        if(is_object($geotravCmd))
        $array['zip'] = $geotravCmd->execCmd();
        $geotravCmd = geotravCmd::byEqLogicIdAndLogicalId($geoloc,'location:city');
        if(is_object($geotravCmd))
        $array['ville'] = $geotravCmd->execCmd();
        else {
          log::add(__CLASS__, 'error', 'Eqlogic geotravCmd object not found');
          return $array;
        }
      }
      else {
        log::add(__CLASS__, 'error', 'Eqlogic geotrav object not found');
        return $array;
      }
    }
    if($array['ville'] == '' || $array['zip'] == '') {
      log::add(__CLASS__, 'error', 'Localisation incorrectement configurée. Ville: '.$array['ville'] .'Code postal: '.$array['zip']);
      return $array;
    }
    $url = 'https://api-adresse.data.gouv.fr/search/?q=' .urlencode($array['ville']) .'&postcode=' .$array['zip'] .'&limit=1';
    $return = self::callURL($url);
    $loglevel = log::convertLogLevel(log::getLogLevel(__CLASS__));
    if($loglevel == 'debug') {
      $hdle = fopen(__DIR__ ."/../../data/" .__FUNCTION__ .'-' .$array['ville'] .'_' .$array['zip'] .".json","wb");
      if($hdle !== FALSE) { fwrite($hdle, json_encode($return)); fclose($hdle); }
    }
    if(!isset($return['features'][0]['properties'])) {
      log::add(__CLASS__, 'error', 'Ville [' .$array['ville'] .'] non trouvée. ' .__FUNCTION__ .'() ' . print_r($return,true));
      return $array;
    }
    log::add(__CLASS__, 'debug', 'Insee ' . print_r($return['features'][0]['properties'],true));
    $array['ville'] = $return['features'][0]['properties']['name'];
    $array['insee'] = $return['features'][0]['properties']['citycode'];
    $array['lon'] = $return['features'][0]['geometry']['coordinates'][0];
    $array['lat'] = $return['features'][0]['geometry']['coordinates'][1];
    log::add(__CLASS__, 'debug', 'Insee:' .$array['insee'] .' Ville:' .$array['ville'] .' Code postal:' .$array['zip'] .' Latitude:' .$array['lat'] .' Longitude:' .$array['lon']);
    return $array;
  }

  public function getLocationDetails($_array = array()) {
    $lat = $_array['lat']; $lon = $_array['lon'];
    if($lat != '' && $lon != '') {
      $url = "https://rpcache-aa.meteofrance.com/internet2018client/2.0/forecast?lat=$lat&lon=$lon&id=&instants=morning,afternoon,evening,night";
      $ville = $_array['ville'];
      $return = self::callMeteoWS($url,false,true,__FUNCTION__ ."-$ville.json");
      if(isset($return['properties']['bulletin_cote'])) $bulletin_cote = $return['properties']['bulletin_cote'];
      else $bulletin_cote = 0;
      $this->setConfiguration('bulletinCote', $bulletin_cote);
      if(isset($return['properties']['timezone'])) $timezone = $return['properties']['timezone'];
      else $timezone = 'Europe/Paris';
      $this->setConfiguration('numDept', $return['properties']['french_department']);
    }
    else {
      log::add(__CLASS__, 'debug', __FUNCTION__ ." Invalid latitude/longitude: $lat/$lon");
      $this->setConfiguration('bulletinCote', 0);
      $this->setConfiguration('numDept', '');
    }
    $this->setConfiguration('insee', $_array['insee']);
    $this->setConfiguration('lat', $_array['lat']);
    $this->setConfiguration('lon', $_array['lon']);
    $this->setConfiguration('zip', $_array['zip']);
    $this->setConfiguration('ville', $_array['ville']);
  }

  public function getBulletinDetails($_array = array()) {
    // $ville = $_array['ville'];
    $ville = str_replace("'",'-',$_array['ville']);
    $zip = $_array['zip'];
    if($ville != '' && $zip != '') {
      $url = "http://meteofrance.com/previsions-meteo-france/" . urlencode($ville) . "/" . $_array['zip'];
      log::add(__CLASS__, 'debug', __FUNCTION__ ." URL: $url");
      $dom = new DOMDocument;
      if(@$dom->loadHTMLFile($url,LIBXML_NOERROR) === true ) {
        // $dom->saveHTMLFile(__DIR__ .'/' .$_array['zip'] .'.html');
        $xpath = new DomXPath($dom);
        log::add(__CLASS__, 'debug', '    ' . $xpath->query("//html/body/script[1]")[0]->nodeValue);
        $json = json_decode($xpath->query("//html/body/script[1]")[0]->nodeValue, true);
        $loglevel = log::convertLogLevel(log::getLogLevel(__CLASS__));
        if($loglevel == 'debug') {
          $hdle = fopen(__DIR__ ."/../../data/" .__FUNCTION__ ."-${ville}_$zip.json", "wb");
          if($hdle !== FALSE) { fwrite($hdle, json_encode($json)); fclose($hdle); }
        }
        log::add(__CLASS__, 'debug', 'Bulletin Ville Result ' . $json['id_bulletin_ville']);
        $this->setConfiguration('bulletinVille', ((is_null($json['id_bulletin_ville']))?'':$json['id_bulletin_ville']));
      }
      else {
        log::add(__CLASS__, 'warning', __FUNCTION__ ." loadHTMLFile failed. getBulletinVille will not be called.");
        $this->setConfiguration('bulletinVille', '');
      }
    }
    else {
      log::add(__CLASS__, 'debug', __FUNCTION__ ." Invalid ville/zipcode: $ville/$zip");
    }
  }

  public function getBulletinVille() {
    $bulletinVille = $this->getConfiguration('bulletinVille','');
    if ($bulletinVille == '') {
      return;
    }
    $url = "https://rpcache-aa.meteofrance.com/wsft/files/agat/ville/bulvillefr_$bulletinVille.xml";
    log::add(__CLASS__, 'debug', __FUNCTION__ ." BulletinVille: $bulletinVille URL: $url");
    $return = self::callMeteoWS($url,true,false,__FUNCTION__ ."-$bulletinVille.json");
    $this->checkAndUpdateCmd('BulletinvilletitreEcheance1', $return['echeance'][0]['titreEcheance']);
    $this->checkAndUpdateCmd('Bulletinvillepression1', $return['echeance'][0]['pression']);
    $this->checkAndUpdateCmd('BulletinvilleTS1', $return['echeance'][0]['TS']);
    $this->checkAndUpdateCmd('Bulletinvilletemperature1', $return['echeance'][0]['temperature']);
    $this->checkAndUpdateCmd('Bulletinvillevent1', $return['echeance'][0]['vent']);
    $this->checkAndUpdateCmd('BulletinvilletitreEcheance2', $return['echeance'][1]['titreEcheance']);
    if(isset($return['echeance'][1]['pression']))
      $this->checkAndUpdateCmd('Bulletinvillepression2', $return['echeance'][1]['pression']);
    else $this->checkAndUpdateCmd('Bulletinvillepression2', '');
    $this->checkAndUpdateCmd('BulletinvilleTS2', $return['echeance'][1]['TS']);
    $this->checkAndUpdateCmd('Bulletinvilletemperature2', $return['echeance'][1]['temperature']);
    $this->checkAndUpdateCmd('Bulletinvillevent2', $return['echeance'][1]['vent']);
    $this->checkAndUpdateCmd('BulletinvilletitreEcheance3', $return['echeance'][2]['titreEcheance']);
    if(isset($return['echeance'][2]['pression']))
      $this->checkAndUpdateCmd('Bulletinvillepression3', $return['echeance'][2]['pression']);
    else $this->checkAndUpdateCmd('Bulletinvillepression3', '');
    $this->checkAndUpdateCmd('BulletinvilleTS3', $return['echeance'][2]['TS']);
    $this->checkAndUpdateCmd('Bulletinvilletemperature3', $return['echeance'][2]['temperature']);
    $this->checkAndUpdateCmd('Bulletinvillevent3', $return['echeance'][2]['vent']);
  }

  public function getHourlyDailyForecasts() { // hourly and daily forecast
    $lat = $this->getConfiguration('lat'); $lon = $this->getConfiguration('lon');
    if($lat == '' || $lon == '') {
      log::add(__CLASS__, 'debug', __FUNCTION__ ." " .$this->getName() ." Invalid latitude/longitude: $lat/$lon");
      return;
    }
    $ville = $this->getConfiguration('ville');
    log::add(__CLASS__, 'debug', __FUNCTION__ ." $ville $lat/$lon");
    $url = "https://webservice.meteofrance.com/forecast?lat=$lat&lon=$lon&id=&instants=&day=5";
    $return = self::callMeteoWS($url,false,true,__FUNCTION__ ."-$ville.json");
    $timezone = $return['position']['timezone'];
    $nb = count($return['forecast']);
    $updated_on = $return['updated_on'];
    log::add(__CLASS__, 'debug', "  updated_on: " .date('d-m-Y H:i:s', $updated_on) ." Nbforecast: $nb Timezone: $timezone");
    $now = time();
    $found = 0; $j = 0;
      // Prévisions par heure
    for($i=0;$i<$nb-1;$i++) {
      $forecastTS = $return['forecast'][$i]['dt'];
      $forecastNextTS = $return['forecast'][$i+1]['dt'];
      log::add(__CLASS__, 'debug', "    $i forecast:" .date('d-m-Y H:i:s', $forecastTS) ." Desc: " .$return['forecast'][$i]['weather']['desc']);
      if($found || ($now >= $forecastTS && $now < $forecastNextTS)) {
        $value= $return['forecast'][$i];
        $found = 1;
        if($j == 0 ) {
          log::add(__CLASS__, 'debug', "  Now forecast found: (" .date("H:i:s",$forecastTS) .") Icon: " .$value['weather']['icon']);
          $this->checkAndUpdateCmd('MeteonowTemperature', $value['T']['value']);
          $this->checkAndUpdateCmd('MeteonowTemperatureRes', $value['T']['windchill']);
          $this->checkAndUpdateCmd('MeteonowHumidity', $value['humidity']);
          $this->checkAndUpdateCmd('MeteonowPression', $value['sea_level']);
          $this->checkAndUpdateCmd('MeteonowWindSpeed', round($value['wind']['speed']*3.6));
          $this->checkAndUpdateCmd('MeteonowWindGust', round($value['wind']['gust']*3.6));
          $this->checkAndUpdateCmd('MeteonowWindDirection', $value['wind']['direction']);
          $this->checkAndUpdateCmd('MeteonowRain1h', (isset($value['rain']['1h']) ? $value['rain']['1h'] : -1));
          $this->checkAndUpdateCmd('MeteonowSnow1h', (isset($value['snow']['1h']) ? $value['snow']['1h'] : -1));
          $this->checkAndUpdateCmd('MeteonowCloud', $value['clouds']);
          $this->checkAndUpdateCmd('MeteonowIcon', $value['weather']['icon']);
          $this->checkAndUpdateCmd('MeteonowDescription', $value['weather']['desc']);
        }
        else if($j == 1 ) {
            // H+1
  log::add(__CLASS__, 'debug', "  1h forecast Desc: " .$value['weather']['desc'] ." Icon: " .$value['weather']['icon']);
          $this->checkAndUpdateCmd('Meteodayh1description', $value['weather']['desc'].' '.date('d-m H:i', $forecastTS));
          $this->checkAndUpdateCmd('Meteodayh1temperature', $value['T']['value']);
          $this->checkAndUpdateCmd('Meteodayh1temperatureRes', $value['T']['windchill']);
          $this->checkAndUpdateCmd('hourly1icon', $value['weather']['icon']);
        }
        // message::add(__FUNCTION__, "I=$i J=$j DT: " .$value['dt'] ." ==> $forecastTS");
        // $value['dt'] = $forecastTS;
        $this->checkAndUpdateCmd("MeteoHour${j}Json", str_replace('"','&quot;',json_encode($value,JSON_UNESCAPED_UNICODE)));
        $j++;
        if($j == 10) break;
      }
    }
    if($found == 0) {
      $this->checkAndUpdateCmd('MeteonowTemperature', -666);
      $this->checkAndUpdateCmd('MeteonowTemperatureRes', -666);
      $this->checkAndUpdateCmd('MeteonowHumidity', -666);
      $this->checkAndUpdateCmd('MeteonowPression', -666);
      $this->checkAndUpdateCmd('MeteonowWindSpeed', -666);
      $this->checkAndUpdateCmd('MeteonowWindGust', -666);
      $this->checkAndUpdateCmd('MeteonowWindSpeed', -666);
      $this->checkAndUpdateCmd('MeteonowRain1h', -666);
      $this->checkAndUpdateCmd('MeteonowSnow1h', -666);
      $this->checkAndUpdateCmd('MeteonowCloud', -666);
      $this->checkAndUpdateCmd('MeteonowIcon', "0");
      $this->checkAndUpdateCmd('MeteonowDescription', "Hour not found");
      $this->checkAndUpdateCmd('Meteodayh1description', "Hour not found");
      $this->checkAndUpdateCmd('Meteodayh1temperature', -666);
      $this->checkAndUpdateCmd('Meteodayh1temperatureRes', -666);
      $this->checkAndUpdateCmd('hourly1icon', "0");
    }
      // Update the daily_forecast commands
    $nbD = count($return['daily_forecast']);
    log::add(__CLASS__, 'debug', "  NbDaily_forecast: $nbD");
    for($i=0;$i<$nbD;$i++) {
      $value= $return['daily_forecast'][$i];
      $forecastTS = $value['dt'];
      $value['dt12H'] = mktime(12,0,0,date('m',$forecastTS),date('d',$forecastTS),date('Y',$forecastTS));
      log::add(__CLASS__, 'debug', "    $i daily_forecast:" .date('d-m-Y H:i:s', $forecastTS));
      if($i < 4) {
        $this->checkAndUpdateCmd('Meteoday' .$i .'PluieCumul', $value['precipitation']['24h']);
      /* n'existe pas dans les daily_forecast
      $this->checkAndUpdateCmd('Meteoday' .$i .'directionVent', $value['wind_direction']);
      $this->checkAndUpdateCmd('Meteoday' .$i .'vitesseVent', $value['wind_speed']);
      $this->checkAndUpdateCmd('Meteoday' .$i .'forceRafales', $value['wind_speed_gust']);
       */
        $this->checkAndUpdateCmd("Meteoday${i}indiceUV", $value['uv']);
        $this->checkAndUpdateCmd("Meteoday${i}description", $value['weather12H']['desc']);
        $this->checkAndUpdateCmd("Meteoday${i}icon", $value['weather12H']['icon']);
        $this->checkAndUpdateCmd("Meteoday${i}temperatureMin", $value['T']['min']);
        $this->checkAndUpdateCmd("Meteoday${i}temperatureMax", $value['T']['max']);
      }
      /* JSON structure
        { "dt":1686096000,
          "T":{"min":12.1,"max":26.1,"sea":null},
          "humidity":{"min":40,"max":75},
          "precipitation":{"24h":0},
          "uv":8,
          "weather12H":{"icon":"p1j","desc":"Ensoleill\u00e9"},
          "sun":{"rise":1686108819,"set":1686166464},
          "dt12H":1686132000
        }
       */
      // $this->checkAndUpdateCmd("MeteoDay${i}Json", json_encode($value));
      $this->checkAndUpdateCmd("MeteoDay${i}Json", str_replace('"','&quot;',json_encode($value,JSON_UNESCAPED_UNICODE)));
    }
  }

  public function getInstantsValues() { // Instant forecast (morning,afternoon,evening,night)
    $lat = $this->getConfiguration('lat'); $lon = $this->getConfiguration('lon');
    $ville = $this->getConfiguration('ville');
    log::add(__CLASS__, 'debug', __FUNCTION__ ." $ville $lat/$lon");
    if($lat == '' || $lon == '') {
      log::add(__CLASS__, 'debug', "  Invalid latitude/longitude: $lat/$lon");
      return;
    }
    $url = "https://webservice.meteofrance.com/forecast?lat=$lat&lon=$lon&id=&instants=morning,afternoon,evening,night";
    $return = self::callMeteoWS($url,false,true,__FUNCTION__ ."-$ville.json");
    $nb = count($return['forecast']);
    $updated_on = $return['updated_on'];
    log::add(__CLASS__, 'debug', "  updated_on: " .date('d-m-Y H:i:s', $updated_on) ." Nbforecast: $nb");
    // add moment_day in json
    for($i=0;$i<$nb-1;$i++) {
      switch(date('H',$return['forecast'][$i]['dt'])) {
        case 0: case 1: case 2: case 3: case 4: case 5:
          $return['forecast'][$i]['moment_day'] = "Nuit";
          break;
        case 6: case 7: case 8: case 9: case 10: case 11:
          $return['forecast'][$i]['moment_day'] = "Matin";
          break;
        case 12: case 13: case 14: case 15: case 16: case 17:
          $return['forecast'][$i]['moment_day'] = "Après-midi";
          break;
        case 18: case 19: case 20: case 21: case 22: case 23:
          $return['forecast'][$i]['moment_day'] = "Soirée";
          break;
      }
    }
    $now = time();
    $found = 0; $j=0;
    for($i=0;$i<$nb;$i++) {
      $forecastTS = $return['forecast'][$i]['dt'] - 3*3600;
      $forecastNextTS = $return['forecast'][$i+1]['dt'] - 3*3600;
      $value = $return['forecast'][$i];
      log::add(__CLASS__, 'debug', "    $i forecast:" .date('d-m-Y H:i:s', $forecastTS) ." Desc: " .$value['weather']['desc']);
      if(($now >= $forecastTS && $now < $forecastNextTS) || ($i==0 && $now < $forecastTS)) {
        $found = 1;
        for($j=0;$j<8;$j++) {
          $value = $return['forecast'][$i+$j];
          log::add(__CLASS__, 'debug', "    Filling: $j forecast:" .date('d-m-Y H:i:s', $value['dt']) ." Moment: " .$value['moment_day']." Json: " .json_encode($value));
          // $this->checkAndUpdateCmd("MeteoInstant${j}Json", json_encode($value));
          $this->checkAndUpdateCmd("MeteoInstant${j}Json", str_replace('"','&quot;',json_encode($value,JSON_UNESCAPED_UNICODE)));
        }
        break;
      }
    }
    if(!$found) {
      for($i=0;$i<8;$i++) {
        $this->checkAndUpdateCmd("MeteoInstant${i}Json", '');
      }
    }

    if(isset($return['probability_forecast'])) {
      $this->checkAndUpdateCmd('MeteoprobaPluie', $return['probability_forecast'][0]['rain']['3h']);
      $this->checkAndUpdateCmd('MeteoprobaNeige', $return['probability_forecast'][0]['snow']['3h']);
      $this->checkAndUpdateCmd('MeteoprobaGel', $return['probability_forecast'][0]['freezing']);
      /*
      if($ville == 'Brest') {
        log::add(__CLASS__, 'error', "  Brest DT0: " .date('d-m H:i',$return['probability_forecast'][0]['dt']) ." Pluie3h: " .$return['probability_forecast'][0]['rain']['3h'] ." Neige3h: " .$return['probability_forecast'][0]['snow']['3h'] ." Gel: " .$return['probability_forecast'][0]['freezing']);
        log::add(__CLASS__, 'error', "  Brest DT1: " .date('d-m H:i',$return['probability_forecast'][1]['dt']) ." Pluie3h: " .$return['probability_forecast'][1]['rain']['3h'] ." Neige3h: " .$return['probability_forecast'][1]['snow']['3h'] ." Gel: " .$return['probability_forecast'][1]['freezing']);
      }
       */
    }
    else {
      $this->checkAndUpdateCmd('MeteoprobaPluie', -1);
      $this->checkAndUpdateCmd('MeteoprobaNeige', -1);
      $this->checkAndUpdateCmd('MeteoprobaGel', -1);
    }
    $this->checkAndUpdateCmd('MeteoprobaStorm', -666); // Obsolete

      // Init des commandes obsoletes si elles existent
    $cmd = $this->getCmd(null, 'Meteonuit0description');
    if(is_object($cmd)) { // Si les commandes Meteoxxxx ont été créées
      $moments = array('nuit', 'matin', 'midi', 'soir');
      $cmds = array('description', 'directionVent', 'vitessVent', 'forceRafales', 'temperatureMin', 'temperatureMax');
      foreach($moments as $moment) {
        for($i=0;$i<2;$i++) {
          foreach($cmds as $cmd) {
            $logicalId = "Meteo$moment$i$cmd";
            if($cmd == 'description') 
              $this->checkAndUpdateCmd($logicalId, 'Obsolete command');
            else
              $this->checkAndUpdateCmd($logicalId, -666); // Obsolete
          }
        }
      }
    }
  }

  public function getRain() {
    $lat = $this->getConfiguration('lat'); $lon = $this->getConfiguration('lon');
    $ville = $this->getConfiguration('ville');
    log::add(__CLASS__, 'debug', __FUNCTION__ ." $ville $lat/$lon");
    if($lat == '' || $lon == '') {
      log::add(__CLASS__, 'debug', "  Invalid latitude/longitude: $lat/$lon");
      return;
    }
    $url = "https://webservice.meteofrance.com/rain?lat=$lat&lon=$lon";
    $return = self::callMeteoWS($url,false,true,__FUNCTION__ ."-$ville.json");
    $i = 0; $cumul = 0; $next = 0; $type = '';
    if(isset($return['forecast'])) {
      // $timezone = $return['position']['timezone'];
      // $updated_on = self::convertMFdt2UnixTS($return['updated_on'],$timezone);
      $updated_on = $return['updated_on'];
      log::add(__CLASS__, 'debug', "  Updated_on: " .date('d-m-Y H:i:s', $updated_on));
      foreach ($return['forecast'] as $id => $rain) {
        $i++;
        $this->checkAndUpdateCmd('Rainrain' . $i, $rain['rain']);
        $this->checkAndUpdateCmd('Raindesc' . $i, $rain['desc']);
        if (($rain['rain'] > 1) && ($next == 0)) {
          $next = $i * 5;
          if ($i > 6) {
            $next += ($i - 6) * 5;
            //after 30 mn, steps are for 10mn
          }
          $type = $rain['desc'];
        }
        $cumul += $rain['rain'];
      }
      // $dt = self::convertMFdt2UnixTS($return['forecast'][0]['dt'],$timezone);
      $dt = $return['forecast'][0]['dt'];
      $this->checkAndUpdateCmd('Rainheure',  date('Hi',$dt));
    }
    else {
      for($i=1;$i<10;$i++) {
        $this->checkAndUpdateCmd('Rainrain' . $i, 0);
        $this->checkAndUpdateCmd('Raindesc' . $i, "Absence de prévision");
      }
      $this->checkAndUpdateCmd('Rainheure',  date('Hi'));
    }
    $this->checkAndUpdateCmd('Raincumul', $cumul);
    $this->checkAndUpdateCmd('Rainnext', $next);
    $this->checkAndUpdateCmd('Raintype', $type);
  }

  public function getMarine() {
	  $lat = $this->getConfiguration('lat'); $lon = $this->getConfiguration('lon');
	  if($lat == '' || $lon == '') {
		  log::add(__CLASS__, 'debug', __FUNCTION__ ." Invalid latitude/longitude: $lat/$lon");
      return;
    }
    log::add(__CLASS__, 'debug', __FUNCTION__ ." $lat/$lon");
    $url = "https://rpcache-aa.meteofrance.com/internet2018client/2.0/forecast/marine?lat=$lat&lon=$lon";
    $return = self::callMeteoWS($url,false,true,__FUNCTION__ ."-2-${lat}_$lon.json");
    $t = time();
    foreach ($return['properties']['marine'] as $id => $marine) {
	    $id = 0; // Pas d'autre commande que 0 TODO to be checked
      $marineTS = strtotime($marine['time']);
      if($t < $marineTS) continue;
      if($t >= $marineTS) {
        $this->checkAndUpdateCmd('Marinewind_speed_kt' . $id, $marine['wind_speed_kt']);
        $this->checkAndUpdateCmd('Marinewind_direction' . $id, $marine['wind_direction']);
        $this->checkAndUpdateCmd('Marinebeaufort_scale' . $id, $marine['beaufort_scale']);
        $this->checkAndUpdateCmd('Marinewave_height' . $id, $marine['wave_height']);
        $this->checkAndUpdateCmd('Marinemax_wave_height' . $id, $marine['max_wave_height']);
        $this->checkAndUpdateCmd('Marinewind_waves_height' . $id, $marine['wind_waves_height']);
        $this->checkAndUpdateCmd('Marineprimary_swell_direction' . $id, $marine['primary_swell_direction']);
        $this->checkAndUpdateCmd('Marineprimary_swell_height' . $id, $marine['primary_swell_height']);
        $this->checkAndUpdateCmd('Marineprimary_swell_period' . $id, $marine['primary_swell_period']);
        $this->checkAndUpdateCmd('MarineT_sea' . $id, $marine['T_sea']);
        $this->checkAndUpdateCmd('Marinesea_condition' . $id, $marine['sea_condition']);
        $this->checkAndUpdateCmd('Marinesea_condition_description' . $id, $marine['sea_condition_description']);
        break;
      }
    }
  }

  public function getTide() {
    $insee = $this->getConfiguration('insee');
    $ville = $this->getConfiguration('ville');
    log::add(__CLASS__, 'debug', __FUNCTION__ ." Insee: $insee Ville $ville");
    $url = 'https://rpcache-aa.meteofrance.com/internet2018client/2.0/tide?id=' .$insee .'52';
    $return = self::callMeteoWS($url,false,true,__FUNCTION__ ."-${insee}-$ville.json");
    if(isset($return['properties']['tide'])) {
      $this->checkAndUpdateCmd('Tidehigh_tide0time', date('Hi',strtotime($return['properties']['tide']['high_tide'][0]['time'])));
      $this->checkAndUpdateCmd('Tidehigh_tide0tidal_coefficient', $return['properties']['tide']['high_tide'][0]['tidal_coefficient']);
      $this->checkAndUpdateCmd('Tidehigh_tide0tidal_height', $return['properties']['tide']['high_tide'][0]['tidal_height']);
      if(isset($return['properties']['tide']['high_tide'][1]['time'])) 
        $this->checkAndUpdateCmd('Tidehigh_tide1time', date('Hi',strtotime($return['properties']['tide']['high_tide'][1]['time'])));
      else $this->checkAndUpdateCmd('Tidehigh_tide1time', -1);
      if(isset($return['properties']['tide']['high_tide'][1]['tidal_coefficient'])) 
        $this->checkAndUpdateCmd('Tidehigh_tide1tidal_coefficient', $return['properties']['tide']['high_tide'][1]['tidal_coefficient']);
      else $this->checkAndUpdateCmd('Tidehigh_tide1tidal_coefficient', -1);
      if(isset($return['properties']['tide']['high_tide'][1]['tidal_height'])) 
        $this->checkAndUpdateCmd('Tidehigh_tide1tidal_height', $return['properties']['tide']['high_tide'][1]['tidal_height']);
      else $this->checkAndUpdateCmd('Tidehigh_tide1tidal_height', -1);
      $this->checkAndUpdateCmd('Tidelow_tide0time', date('Hi',strtotime($return['properties']['tide']['low_tide'][0]['time'])));
      if(isset($return['properties']['tide']['low_tide'][0]['tidal_coefficient']))
        $coef = $return['properties']['tide']['low_tide'][0]['tidal_coefficient'];
      else $coef = 0;
      $this->checkAndUpdateCmd('Tidelow_tide0tidal_coefficient', $coef);
      $this->checkAndUpdateCmd('Tidelow_tide0tidal_height', $return['properties']['tide']['low_tide'][0]['tidal_height']);
      $this->checkAndUpdateCmd('Tidelow_tide1time', date('Hi',strtotime($return['properties']['tide']['low_tide'][1]['time'])));
      if(isset($return['properties']['tide']['low_tide'][1]['tidal_coefficient']))
        $coef = $return['properties']['tide']['low_tide'][1]['tidal_coefficient'];
      else $coef = 0;
      $this->checkAndUpdateCmd('Tidelow_tide1tidal_coefficient', $coef);
      $this->checkAndUpdateCmd('Tidelow_tide1tidal_height', $return['properties']['tide']['low_tide'][1]['tidal_height']);
    }
  }

  function getVigilanceToken($alertPublicKey,$alertPrivateKey) {
    $applicationId = base64_encode("$alertPublicKey:$alertPrivateKey");
    $alertToken = config::byKey('alertToken', __CLASS__, '');
    $alertTokenTS = config::byKey('alertTokenTS', __CLASS__, 0);
    if($alertToken == '' || $alertTokenTS-30 < time()) { // create token / renew token
      log::add(__CLASS__, 'debug', '  Create new or renew the token');
      $url = "https://portail-api.meteofrance.fr/token";
      $header = array("Authorization: Basic $applicationId");
      $curl = curl_init();
      curl_setopt_array($curl, array(
          CURLOPT_URL => $url, CURLOPT_HTTPHEADER => $header,
          CURLOPT_SSL_VERIFYPEER => false, CURLOPT_RETURNTRANSFER => true,
          CURLOPT_POST => true, CURLOPT_POSTFIELDS => 'grant_type=client_credentials'));
      $return = curl_exec($curl);
      $curl_error = curl_error($curl);
      curl_close($curl);
      if($return === false) {
        log::add(__CLASS__, 'error', "  Unable to get token. curl_error: $curl_error");
        return '';
      }
      $dec = json_decode($return,true);
      if(isset($dec['access_token'])) {
        $alertToken = $dec['access_token'];
        config::save('alertToken', $alertToken, __CLASS__);
        config::save('alertTokenTS', time()+ $dec['expires_in'], __CLASS__);
      }
      else {
        $alertToken = '';
        log::add(__CLASS__, 'debug', "  Token was not set in MF answer: $return");
      }
    }
    return $alertToken;
  }

  public function getVigilanceDataApiCloudMF() {
    log::add(__CLASS__, 'debug', __FUNCTION__);
    $alertPublicKey = trim(config::byKey('alertPublicKey', __CLASS__));
    $alertPrivateKey = trim(config::byKey('alertPrivateKey', __CLASS__));
    $fileAlert = __DIR__ ."/../../data/CDP_CARTE_EXTERNE.json";
    $fileVignetteJ = __DIR__ ."/../../data/VIGNETTE_NATIONAL_J_500X500.png";
    $fileVignetteJ1 = __DIR__ ."/../../data/VIGNETTE_NATIONAL_J1_500X500.png";
    $recupAPI = 0;
    if( $alertPublicKey != '' && $alertPrivateKey != '') { // Vigilances avec l'API
      $token = self::getVigilanceToken($alertPublicKey,$alertPrivateKey);
      if($token != '') {
        $url = "https://public-api.meteofrance.fr/public/DPVigilance/v1/cartevigilance/encours";
        log::add(__CLASS__, 'debug', "  Fetching API: $url");
        $header = array("Authorization: Bearer $token");
        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => $url, CURLOPT_HTTPHEADER => $header,
            CURLOPT_SSL_VERIFYPEER => false, CURLOPT_RETURNTRANSFER => true));
        $resu = curl_exec($curl);
        $curl_error = curl_error($curl);
        curl_close($curl);
        if($resu !== false) {
          $dec = json_decode($resu,true);
          $jsonError = json_last_error();
          if($jsonError == JSON_ERROR_NONE) {
            $hdle = fopen($fileAlert, "wb");
            $recupAPI++;
            if($hdle !== FALSE) { fwrite($hdle, $resu); fclose($hdle); }
          }
          else log::add(__CLASS__, 'warning', "  Unable to get new data from MeteoFrance. Using older. Json error: ($jsonError) ".json_last_error_msg());
        }
        else log::add(__CLASS__, 'warning', "  Unable to fetch vigilance/encours. curl_error: $curl_error");
          // Vignette du jour
        $url = "https://public-api.meteofrance.fr/public/DPVigilance/v1/vignettenationale-J/encours";
        $header = array("Authorization: Bearer $token");
        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => $url, CURLOPT_HTTPHEADER => $header,
            CURLOPT_SSL_VERIFYPEER => false, CURLOPT_RETURNTRANSFER => true));
        $resu = curl_exec($curl);
        $curl_error = curl_error($curl);
        curl_close($curl);
        if($resu !== false) {
          $hdle = fopen($fileVignetteJ, "wb");
          if($hdle !== FALSE) { fwrite($hdle, $resu); fclose($hdle); }
          $recupAPI++;
        }
        else log::add(__CLASS__, 'warning', "  Unable to fetch vignette-J. curl_error: $curl_error");
          // Vignette de demain
        $url = "https://public-api.meteofrance.fr/public/DPVigilance/v1/vignettenationale-J1/encours";
        $header = array("Authorization: Bearer $token");
        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => $url, CURLOPT_HTTPHEADER => $header,
            CURLOPT_SSL_VERIFYPEER => false, CURLOPT_RETURNTRANSFER => true));
        $resu = curl_exec($curl);
        $curl_error = curl_error($curl);
        curl_close($curl);
        if($resu !== false) {
          $hdle = fopen($fileVignetteJ1, "wb");
          if($hdle !== FALSE) { fwrite($hdle, $resu); fclose($hdle); }
          $recupAPI++;
        }
        else log::add(__CLASS__, 'warning', "  Unable to fetch vignette-J1. curl_error: $curl_error");
      }
    }
    if($recupAPI < 3) { // Recover vigilance with MF archives
      if(date('H') < 6) $timeRecup = strtotime("yesterday");
      else $timeRecup = time();
      $dateRecup = date('Y/m/d',$timeRecup);
      $url = "http://storage.gra.cloud.ovh.net/v1/AUTH_555bdc85997f4552914346d4550c421e/gra-vigi6-archive_public/$dateRecup/";
      // log::add(__CLASS__, 'debug', "  Fetching MF archives $url");
      $doc = new DOMDocument();
      libxml_use_internal_errors(true); // disable warning
      $doc->preserveWhiteSpace = false;
      if(@$doc->loadHTMLFile($url) !== false ) {
        $xpath = new DOMXpath($doc);
        $subdir = $xpath->query('//html/body/table/tr[@class="item subdir"]/td/a');
        $nb = count($subdir);
        $prevRecup = trim(config::byKey('prevVigilanceRecovery', __CLASS__));
        $prevRecup = substr($prevRecup,0,-1);
// echo "Found: $nb PrevRecup: $prevRecup Daterecup: $dateRecup<br>";
        $latest = '0';
        $latestFileAlert = ''; $latestFileVignetteJ = ''; $latestFileVignetteJ1 = '';
        $filesOK = file_exists($fileAlert) && file_exists($fileVignetteJ) && file_exists($fileVignetteJ1);
        for($i=0;$i<$nb;$i++) {
          $val = $subdir[$i]->getAttribute('href');
          $val2 = substr($val,0,-1);
          $url2 = $url .$val2;
          $currRecup = date('Ymd',$timeRecup);
          $newRecup = $currRecup.$val2;
          if($prevRecup >= $newRecup && $filesOK) {
            log::add(__CLASS__, 'debug', "  Data already processed: $dateRecup/$val2");
            continue;
          }
          $latest = $val2;
          
// echo "New Data Recup: $dateRecup/$val2<br>";
          // log::add(__CLASS__, 'debug', "  Fetching MF archives $url2");
          $doc2 = new DOMDocument();
          libxml_use_internal_errors(true); // disable warning
          $doc2->preserveWhiteSpace = false;
          if(@$doc2->loadHTMLFile($url2) !== false ) {
            $xpath2 = new DOMXpath($doc2);
            $subdir2 = $xpath2->query('//html/body/table/tr/td[@class="colname"]/a');
            $nb2 = count($subdir2);
            for($i2=0;$i2<$nb2;$i2++) {
              $val3 = $subdir2[$i2]->getAttribute('href');
              if($val3 == "CDP_CARTE_EXTERNE.json") {
                $latestFileAlert = "$url2/$val3";
              }
              else if($val3 == "VIGNETTE_NATIONAL_J_500X500.png") {
                $latestFileVignetteJ = "$url2/$val3";
              }
              else if($val3 == "VIGNETTE_NATIONAL_J1_500X500.png") {
                $latestFileVignetteJ1 = "$url2/$val3";
              }
            }
          }
          else {
            log::add(__CLASS__, 'warning', "  Unable to fetch $url2");
            return 1; // erreur
          }
          // log::add(__CLASS__, 'debug', "  Val: [$val] Latest: $latest");
        }
        $err = 0;
        if($latestFileAlert != '') {
          $contents = @file_get_contents($latestFileAlert);
          if($contents !== false) {
            $hdle = fopen($fileAlert, "wb");
            if($hdle !== FALSE) { fwrite($hdle, $contents); fclose($hdle); }
            else {
              log::add(__CLASS__, 'warning', "  Unable to open file for writing: $latestFileAlert");
              $err++;
            }
          }
          else {
            log::add(__CLASS__, 'warning', "  Unable to download $latestFileAlert");
            $err++;
          }
        }
        if($latestFileVignetteJ != '') {
          $contents = @file_get_contents($latestFileVignetteJ);
          if($contents !== false) {
            $hdle = fopen($fileVignetteJ, "wb");
            if($hdle !== FALSE) { fwrite($hdle, $contents); fclose($hdle); }
            else {
              log::add(__CLASS__, 'warning', "  Unable to open file for writing: $latestFileVignetteJ");
              $err++;
            }
          }
          else {
            log::add(__CLASS__, 'warning', "  Unable to download $latestFileVignetteJ");
            $err++;
          }
        }
        if($latestFileVignetteJ1 != '') {
          $contents = @file_get_contents($latestFileVignetteJ1);
          if($contents !== false) {
            $hdle = fopen($fileVignetteJ1, "wb");
            if($hdle !== FALSE) { fwrite($hdle, $contents); fclose($hdle); }
            else {
              log::add(__CLASS__, 'warning', "  Unable to open file for writing: $latestFileVignetteJ1");
              $err++;
            }
          }
          else {
            log::add(__CLASS__, 'warning', "  Unable to download $latestFileVignetteJ1");
            $err++;
          }
        }
        if($err == 0 && $latest != 0) {
          $latestFull = date('Ymd',$timeRecup) .$latest .'Z';
          config::save('prevVigilanceRecovery', $latestFull, __CLASS__);
        }
      }
      else {
        log::add(__CLASS__, 'debug', "  Unable to fetch $url");
        return 1; // erreur
      }
    }
    return 0; // OK
  }

  public function getVigilance() {
    $numDept = $this->getConfiguration('numDept');
    if($numDept == '') {
      log::add(__CLASS__, 'debug', __FUNCTION__ ." Département non défini.");
      return;
    }
    log::add(__CLASS__, 'debug', __FUNCTION__ ." Département: $numDept");
    $fileData = __DIR__ ."/../../data/CDP_CARTE_EXTERNE.json";
    $contents = @file_get_contents($fileData);
    if($contents === false) {
      log::add(__CLASS__, 'warning', "  Unable to load data from $fileData");
      // TODO clean des cmds ou pas ?
      return;
    }
    $return = json_decode($contents,true);
    if($return === false) {
      @unlink($fileData);
      // TODO clean des cmds ou pas ?
      return;
    }
    $txtTsAlerts = array(); $phenomColor = array();
        // init all values
    foreach(self::$_vigilanceType as $i => $vig) {
      $txtTsAlerts[$i] = ''; $phenomColor[$i] = 0;
    }
    $maxColor = 0; $now = time();
    // $numDept = '06';
    $vigJson = array();
    foreach($return['product']['periods'] as $period) {
      $startPeriod = strtotime($period['begin_validity_time']);
      $endPeriod = strtotime($period['end_validity_time']);
      if($now > $endPeriod || $now < $startPeriod) continue; // just one day
      $vigJson['begin_validity_time'] = $period['begin_validity_time'];
      $vigJson['end_validity_time'] = $period['end_validity_time'];
      $prevVigRecup = trim(config::byKey('prevVigilanceRecovery', __CLASS__));
      if(date('Ymd') != substr($prevVigRecup,0,8)) $img = 'VIGNETTE_NATIONAL_J1_500X500.png';
      else $img = 'VIGNETTE_NATIONAL_J_500X500.png';
      $vigJson['image'] = $img;
      // log::add(__CLASS__, 'debug', "  Validity period start: " .date("d-m-Y H:i",$startPeriod) ." end: " .date("d-m-Y H:i",$endPeriod));
      foreach($period['timelaps']['domain_ids'] as $domain_id) {
        $dept = $domain_id['domain_id'];
        if($dept == $numDept || $dept == $numDept .'10') { // concat 10 si departement bord de mer
          log::add(__CLASS__, 'debug', "  Domain: $dept JSON: " .json_encode($domain_id));
          if(strlen($dept) == 2 ) $txt = 'dept';
          else $txt = 'littoral';
          $vigJson[$txt] = $domain_id;
          foreach($domain_id['phenomenon_items'] as $phenomenonItem) {
            $phenId = $phenomenonItem['phenomenon_id'];
            $color = $phenomenonItem['phenomenon_max_color_id'];
            if($color > $maxColor) $maxColor = $color;
            $phenomColor[$phenId] = $color;
            if($color > 1) {
              foreach($phenomenonItem['timelaps_items'] as $timelapsItem) {
                $colorTs = $timelapsItem['color_id'];
                if($colorTs != 0) {
                  $begin = strtotime($timelapsItem['begin_time']);
                  $end = strtotime($timelapsItem['end_time']);
                  if($now < $end) {
                    $txtTsAlerts[$phenId] .= "<br><i class='fa fa-circle' style='color:" .self::$_vigilanceColors[$colorTs]['color'] ."'></i> " .date('H:i',$begin) ." - " .date('H:i',$end);
                    log::add(__CLASS__, 'debug', "  PhenomId: $phenId Color: $color start:" .date("d-m-Y H:i:s",$begin)." End:" .date("d-m-Y H:i:s",$end) ." MaxColor: $maxColor"); 
                  }
                }
              }
            }
          }
        }
      }
    }
    if(count($vigJson)) {
      $contents = str_replace('"','&quot;',json_encode($vigJson,JSON_UNESCAPED_UNICODE));
      $this->checkAndUpdateCmd("VigilanceJson", $contents);
      /*
      $file = __DIR__ ."/../../data/" .__FUNCTION__ ."-$numDept.json";
      $hdle = fopen($file, "wb");
      if($hdle !== FALSE) { fwrite($hdle, $contents); fclose($hdle); }
       */
    }
    $this->checkAndUpdateCmd('Vigilancecolor_max', $maxColor);
    foreach(self::$_vigilanceType as $i => $vig) {
      // if($phenomColor[$i] > 1) message::add(__CLASS__, "Vigilance $i " .$phenomColor[$i] .$txtTsAlerts[$i]);
      $this->checkAndUpdateCmd("Vigilancephases$i",
        self::$_vigilanceColors[$phenomColor[$i]]['desc']
        .$txtTsAlerts[$i]);
      $this->checkAndUpdateCmd("Vigilancephenomenon_max_color_id$i", $phenomColor[$i]);
    }

    /*
     *  $listVigilance = array(); TODO command not updated
    $value[0]=''; $value[1]="Vert"; $value[2]="Jaune"; $value[3]="Orange"; $value[4]="Rouge";
    foreach ($return['phenomenons_items'] as $id => $vigilance) {
      $this->checkAndUpdateCmd('Vigilancephenomenon_max_color_id' . $vigilance['phenomenon_id'], $vigilance['phenomenon_max_color_id']);
      if ($vigilance['phenomenon_max_color_id'] > 1) {
        $cmd = meteofranceCmd::byEqLogicIdAndLogicalId($this->getId(),'Vigilancephases' . $vigilance['phenomenon_id']);
        $listVigilance[] = $type[$vigilance['phenomenon_id']] . ' : ' . $value[$vigilance['phenomenon_max_color_id']] . ', ' . $cmd->execCmd();
      }
    }
    $this->checkAndUpdateCmd('Vigilancelist', implode(', ',$listVigilance));
     */
  }

  public function getAlerts() {
    log::add(__CLASS__, 'debug', __FUNCTION__);
    $url = 'https://webservice.meteofrance.com//report?domain=france&report_type=message&report_subtype=infospe&format=';
    $return = self::callMeteoWS($url,false,true,__FUNCTION__ .".json");
    if (isset($return['Com'][0]['titre'])) {
      $this->checkAndUpdateCmd('Alerttitre', $return['Com'][0]['titre']);
      $this->checkAndUpdateCmd('Alerttexte', $return['Com'][0]['texte']);
      $this->checkAndUpdateCmd('AlertdateDeFin', $return['Com'][0]['dateDeFin']);
      $this->checkAndUpdateCmd('AlertdateProduction', $return['Com'][0]['dateProduction']);
    }
  }

  public function getEphemeris() {
    date_default_timezone_set(config::byKey('timezone'));
    $lat = $this->getConfiguration('lat'); $lon = $this->getConfiguration('lon');
    if($lat == '' || $lon == '') {
      log::add(__CLASS__, 'debug', __FUNCTION__ ." Invalid latitude/longitude: $lat/$lon");
      return;
    }
    log::add(__CLASS__, 'debug', __FUNCTION__ ." $lat/$lon");
    $url = "https://webservice.meteofrance.com/ephemeris?lat=$lat&lon=$lon";
    $ville = $this->getConfiguration('ville');
    $return = self::callMeteoWS($url,false,true,__FUNCTION__ ."-$ville.json");
    $this->checkAndUpdateCmd('Ephemerismoon_phase', $return['properties']['ephemeris']['moon_phase']);
    $this->checkAndUpdateCmd('Ephemerismoon_phase_description', $return['properties']['ephemeris']['moon_phase_description']);
    $this->checkAndUpdateCmd('Ephemerissaint', $return['properties']['ephemeris']['saint']);
    //log::add(__CLASS__, 'debug', 'Date ' . $return['properties']['ephemeris']['sunrise_time'] . ', ' . strtotime($return['properties']['ephemeris']['sunrise_time']));
    $this->checkAndUpdateCmd('Ephemerissunrise_time', date('Hi',strtotime($return['properties']['ephemeris']['sunrise_time'])));
    $this->checkAndUpdateCmd('Ephemerissunset_time', date('Hi',strtotime($return['properties']['ephemeris']['sunset_time'])));
    $this->checkAndUpdateCmd('Ephemerismoonrise_time', date('Hi',strtotime($return['properties']['ephemeris']['moonrise_time'])));
    $this->checkAndUpdateCmd('Ephemerismoonset_time', date('Hi',strtotime($return['properties']['ephemeris']['moonset_time'])));
  }

  public function getBulletinFrance() {
    log::add(__CLASS__, 'debug', __FUNCTION__);
    $url = 'https://webservice.meteofrance.com/report?domain=france&report_type=forecast&report_subtype=BGP';
    $return = self::callMeteoWS($url,true,true,__FUNCTION__ .".json");
    if(isset($return['groupe'][0])) {
      $this->checkAndUpdateCmd('Bulletinfrdate0', $return['groupe'][0]['date']);
      $this->checkAndUpdateCmd('Bulletinfrtitre0', $return['groupe'][0]['titre']);
      $this->checkAndUpdateCmd('Bulletinfrtemps0', $return['groupe'][0]['temps']);
      $this->checkAndUpdateCmd('Bulletinfrdate1', $return['groupe'][1]['date']);
      $this->checkAndUpdateCmd('Bulletinfrtitre1', $return['groupe'][1]['titre']);
      $this->checkAndUpdateCmd('Bulletinfrtemps1', $return['groupe'][1]['temps']);
    }
    else {
      $this->checkAndUpdateCmd('Bulletinfrdate0', $return['groupe']['date']);
      $this->checkAndUpdateCmd('Bulletinfrtitre0', $return['groupe']['titre']);
      $this->checkAndUpdateCmd('Bulletinfrtemps0', $return['groupe']['temps']);
      $this->checkAndUpdateCmd('Bulletinfrdate1', '');
      $this->checkAndUpdateCmd('Bulletinfrtitre1', '');
      $this->checkAndUpdateCmd('Bulletinfrtemps1', '');
    }
  }

  public function getBulletinSemaine() {
    log::add(__CLASS__, 'debug', __FUNCTION__);
    $url = 'https://rpcache-aa.meteofrance.com/internet2018client/2.0/report?domain=france&report_type=forecast&report_subtype=BGP_mensuel';
    // $return = self::callMeteoWS($url, true);
    $return = self::callMeteoWS($url,true,true,__FUNCTION__ .".json");
    $this->checkAndUpdateCmd('Bulletindatesem', $return['groupe'][0]['date']);
    if(isset($return['groupe'][0]['temps'])) {
      $this->checkAndUpdateCmd('Bulletintempssem', $return['groupe'][0]['temps']);
    }
  }

  public static function lowerAccent($_var) {
    $return = str_replace(' ','_',strtolower($_var));
    $return = preg_replace('#Ç#', 'C', $return);
    $return = preg_replace('#ç#', 'c', $return);
    $return = preg_replace('#è|é|ê|ë#', 'e', $return);
    $return = preg_replace('#à|á|â|ã|ä|å#', 'a', $return);
    $return = preg_replace('#ì|í|î|ï#', 'i', $return);
    $return = preg_replace('#ð|ò|ó|ô|õ|ö#', 'o', $return);
    $return = preg_replace('#ù|ú|û|ü#', 'u', $return);
    $return = preg_replace('#ý|ÿ#', 'y', $return);
    $return = preg_replace('#Ý#', 'Y', $return);
    $return = str_replace('_', '-', $return);
    $return = str_replace('\'', '', $return);
    return $return;
  }

  public static function getIcones($_var) {
    $return = array();
    $return['nuit claire'] = 'night-clear';
    $return['tres nuageux'] = 'cloudy';
    $return['couvert'] = 'cloudy';
    $return['brume'] = 'fog';
    $return['brume ou bancs de brouillard'] = 'fog';
    $return['brouillard'] = 'fog';
    $return['brouillard givrant'] = 'fog';
    $return['risque de grele'] = 'hail';
    $return['orages'] = 'lightning';
    $return['risque d\'orages'] = 'lightning';
    $return['pluie orageuses'] = 'thunderstorm';
    $return['pluies orageuses'] = 'thunderstorm';
    $return['averses orageuses'] = 'thunderstorm';
    $return['ciel voile'] = 'cloud';
    $return['ciel voile nuit'] = 'cloud';
    $return['eclaircies'] = 'cloud';
    $return['peu nuageux'] = 'cloud';
    $return['pluie forte'] = 'rain';
    $return['bruine / pluie faible'] = 'showers';
    $return['bruine'] = 'showers';
    $return['pluie faible'] = 'showers';
    $return['pluies eparses / rares averses'] = 'showers';
    $return['pluies eparses'] = 'showers';
    $return['rares averses'] = 'showers';
    $return['pluie moderee'] = 'rain';
    $return['pluie / averses'] = 'rain';
    $return['pluie faible'] = 'showers';
    $return['averses'] = 'rain';
    $return['pluie'] = 'rain';
    $return['neige'] = 'snow';
    $return['neige forte'] = 'snow';
    $return['quelques flocons'] = 'snow';
    $return['averses de neige'] = 'snow';
    $return['neige / averses de neige'] = 'snow';
    $return['pluie et neige'] = 'snow';
    $return['pluie verglacante'] = 'sleet';
    $return['ensoleille'] = 'day-sunny';
    return $return[self::lowerAccent($_var)];
  }

  public static function callMeteoWS($_url, $_xml = false, $_token = true,$debugFile='') {
    //$token = config::byKey('token', 'meteofrance');
    if ($_token)  {
      $token = '&token=__Wj7dVSTjV9YGu1guveLyDq0g7S7TfTjaHBTPTpO0kj8__';
    } else {
      $token = '';
    }
    log::add(__CLASS__, 'debug', "  Get: $_url$token");
    $request_http = new com_http($_url . $token);
    $request_http->setNoSslCheck(true);
    $request_http->setNoReportError(true);
    $return = $request_http->exec(15,1);
    if ($return === false) {
      log::add(__CLASS__, 'debug', "  Unable to fetch $_url");
      return;
    } 
    if ($_xml) {
      $xml = simplexml_load_string($return, 'SimpleXMLElement', LIBXML_NOCDATA);
      $return = json_encode($xml);
    }
      // log result in file or with log class
    $loglevel = log::convertLogLevel(log::getLogLevel(__CLASS__));
    if($loglevel == 'debug') {
      if($debugFile != '') {
        $file = __DIR__ ."/../../data/$debugFile";
        $hdle = fopen($file,"wb");
        if($hdle !== FALSE) {
          fwrite($hdle, $return);
          fclose($hdle);
          log::add(__CLASS__, 'debug', "  Result saved in file: " .realpath($file));
        }
      }
      else log::add(__CLASS__, 'debug', "  Result $return");
    }
    return json_decode($return, true);
  }

  public static function callURL($_url) {
    $request_http = new com_http($_url);
    $request_http->setNoSslCheck(true);
    $request_http->setNoReportError(true);
    $return = $request_http->exec(15,2);
    if ($return === false) {
      log::add(__CLASS__, 'debug', 'Unable to fetch ' . $_url);
      return;
    } else {
      log::add(__CLASS__, 'debug', '  Get ' . $_url);
      log::add(__CLASS__, 'debug', '  Result ' . $return);
    }
    return json_decode($return, true);
  }

  public function loadCmdFromConf($type) {
    /*create commands based on template*/
    if (!is_file(__DIR__ . '/../config/devices/' . $type . '.json')) {
      return;
    }
    $content = file_get_contents(__DIR__ . '/../config/devices/' . $type . '.json');
    if (!is_json($content)) {
      return;
    }
    $device = json_decode($content, true);
    if (!is_array($device) || !isset($device['commands'])) {
      return true;
    }
    foreach ($device['commands'] as $command) {
      $cmd = null;
      foreach ($this->getCmd() as $liste_cmd) {
        if ((isset($command['logicalId']) && $liste_cmd->getLogicalId() == $command['logicalId'])
        || (isset($command['name']) && $liste_cmd->getName() == $command['name'])) {
          $cmd = $liste_cmd;
          break;
        }
      }
      if ($cmd == null || !is_object($cmd)) {
        $cmd = new meteofranceCmd();
        $cmd->setEqLogic_id($this->getId());
        utils::a2o($cmd, $command);
log::add(__CLASS__, 'debug', "  Command creation: " .$command['name']);
        $cmd->save();
      }
    }
  }

  public function getMFimg($filename) {
    $url = 'https://meteofrance.com/modules/custom/mf_tools_common_theme_public/svg/weather';
    $localdir = __DIR__ ."/../../data/icones";
    if(strlen($filename) < 5) // 0.svg .svg ...
      return("plugins/" .__CLASS__ ."/data/icones/0.svg");
    if(!file_exists("$localdir/$filename")) {
      $content = @file_get_contents("$url/$filename");
      if($content === false) {
        log::add(__CLASS__,'debug',"Unable to get file: $url/$filename");
        return("$url/$filename");
      }
      if(!is_dir($localdir)) @mkdir($localdir,0777,true);
      $res = file_put_contents("$localdir/$filename",$content);
      if($res === false) {
        log::add(__CLASS__,'debug',"Unable to save file: $localdir/$filename");
        return("$url/$filename");
      }
    }
    return("plugins/" .__CLASS__ ."/data/icones/$filename");
  }

  public function convertMFdt2UnixTS($dt,$timezone) {
    $dateTZ = date_create('now', timezone_open($timezone));
    $offset = date_offset_get($dateTZ);
    // log::add(__CLASS__, 'debug', "  TZ=$timezone Offset=$offset Gmdate:".gmdate('d-m H:i',$dt) ." Date:".date('d-m H:i',$dt-$offset));
    return($dt - $offset);
  }

  public function convertDegrees2Compass($degrees,$deg=0) {
    $sector = array("Nord","NNE","NE","ENE","Est","ESE","SE","SSE","Sud","SSO","SO","OSO","Ouest","ONO","NO","NNO","Nord");
    $degrees %= 360;
    $idx = round($degrees/22.5);
    if($deg) {
      return($sector[$idx] ." $degrees" ."°");
    }
    else return($sector[$idx]);
  }
  
  public function toHtml($_version = 'dashboard') {
    $replace = $this->preToHtml($_version);
    if (!is_array($replace)) {
      return $replace;
    }
    $version = jeedom::versionAlias($_version);
    if ($this->getDisplay('hideOn' . $version) == 1) {
      return '';
    }
    $lat = $this->getConfiguration('lat'); $lon = $this->getConfiguration('lon');
    $ville = $this->getConfiguration('ville'); $zip = $this->getConfiguration('zip');
    $insee = $this->getConfiguration('insee'); $timezone = $this->getConfiguration('timezone');
    if($ville == '' || $lon == '' || $lat == '' || $zip == '')  {
      $replace['#cmd#'] = '<div style="background-color: red;color:white;margin:5px">Erreur de configuration de l\'équipement Météo France.<br/>Vérifiez la localisation utilisée, puis sauvegardez cet équipement.</div>'."Ville: $ville<br/>Zip: $zip<br/>Insee: $insee<br/>Lat: $lat<br/>Long: $lon<br/>Timezone: $timezone";
      return $this->postToHtml($_version, template_replace($replace, getTemplate('core', $version, 'eqLogic')));
    }
    $lastCmd = $this->getCmd(null, 'refresh'); // Pour test si la dernière commande créée par cronTrigger existe
    if(!is_object($lastCmd)) {
      $replace['#cmd#'] = '<div style="background-color: red;color:white;margin:5px">Création des commandes pour l\'équipement Météo France en cours. Veuillez patienter.</div>'."Ville: $ville<br/>Zip: $zip<br/>Insee: $insee<br/>Lat: $lat<br/>Long: $lon<br/>Timezone: $timezone";
      return $this->postToHtml($_version, template_replace($replace, getTemplate('core', $version, 'eqLogic')));
    }
    $templateF = $this->getConfiguration('templateMeteofrance','plugin');
    if($templateF == 'none') return parent::toHtml($_version);
    else if($templateF == 'plugin') $templateFile = 'meteofrance';
    else if($templateF == 'custom') $templateFile = 'custom.meteofrance';
    else $templateFile = substr($templateF,0,-5);
    log::add(__CLASS__, 'debug', __FUNCTION__ ." " .$this->getName() ." Template: $templateFile");

    $html_forecast = '<table "width=100%"><tr>';
    if ($_version != 'mobile' || $this->getConfiguration('fullMobileDisplay', 0) == 1) {
      $forecast_template = getTemplate('core', $version, 'forecast', 'meteofrance');
      $lastTS = 0;
      $nbH = $this->getConfiguration('forecast1h',0);
      for($i=1;$i<$nbH+1;$i++) {
          // Meteo heure suivante
        $replaceFC = array();
        $replaceFC['#sep#'] =  '';
        $replaceFC['#day#'] = '+ 1';
        $jsonCmd = $this->getCmd(null, "MeteoHour${i}Json");
        if(is_object($jsonCmd)) {
          $val = str_replace('&quot;','"',$jsonCmd->execCmd());
          $dec = json_decode($val,true);
          /* JSON structure
          { "dt":1686045600,
            "T":{"value":24.5,"windchill":29.5},
            "humidity":60,
            "sea_level":1015.5,
            "wind":{"speed":1,"gust":0,"direction":-1,"icon":"Variable"},
            "rain":{"1h":0},"snow":{"1h":0},"iso0":3500,"rain snow limit":"Non pertinent",
            "clouds":10,
            "weather":{"icon":"p1j","desc":"Ensoleillé"}
          }
           */
          if($dec !== false) {
            $lastTS = $dec['dt'];
            $h = date("H",$lastTS);
            $img = self::getMFimg($dec['weather']['icon'] .'.svg');
            $replaceFC['#iconeFC#'] = $img;
            $replaceFC['#low_temperature#'] = $dec['T']['value']."°C";
            $replaceFC['#high_temperature#'] = ''; // "(" .$dec['T']['windchill']."°C)";
            $replaceFC['#condition#'] = $dec['weather']['desc'];
// message::add(__CLASS__, __FUNCTION__ .' ' .$dec['weather']['icon'] .' ' .$dec['weather']['desc']);
            $replaceFC['#day#'] = '<br/>';
            $replaceFC['#moment#'] = '<br/>';
            $replaceFC['#time#'] = date('H:i',$lastTS);
          }
        }
        $html_forecast .= template_replace($replaceFC, $forecast_template);
        unset($replaceFC);
      }
          // Meteo par instant matin, aprés midi, soir, nuit
      $nbDayInstant = $this->getConfiguration('momentForecastDaysNumber',2);
      $displayNight = $this->getConfiguration('displayNighlyForecast',0);
      $currentDay = date('md'); $numDay = 0;
      for($i=0;$i<12;$i++) {
        $jsonCmd = $this->getCmd(null, "MeteoInstant${i}Json");
        if(is_object($jsonCmd)) {
          $val = str_replace('&quot;','"',$jsonCmd->execCmd());
          $dec = json_decode($val,true);
          /* JSON structure
          { "dt":1686078000,
            "T":{"value":21.4,"windchill":24.6},
            "humidity":60,"sea_level":1015.5,
            "wind":{"speed":4,"gust":0,"direction":195,"icon":"SSO"},
            "rain":{"1h":0},
            "snow":{"1h":0},
            "iso0":3400,"rain snow limit":"Non pertinent","clouds":40,
            "weather":{"icon":"p2j","desc":"Eclaircies"},
            "moment_day":"Soirée"
          }
           */
          if($dec !== false) {
            $replaceFC = array();
            $replaceFC['#sep#'] = '';
            $lastTS = $dec['dt'];
            $day = date('md',$lastTS);
            if($day != $currentDay) $numDay++;
            if($numDay >= $nbDayInstant) break;
            $currentDay = $day;
            $moment = $dec['moment_day'];
            if($moment == "Nuit" && $displayNight == 0) continue;
            if($i==0 || $moment == 'Nuit' || ($moment == "Matin" && $displayNight == 0))
              $replaceFC['#day#'] = date_fr(date('D  j',$lastTS));
            else
              $replaceFC['#day#'] = "<br/>";
            $replaceFC['#moment#'] = $moment;
            $replaceFC['#time#'] = date('H',$dec['dt']) ."&nbsp;h";
            $img = self::getMFimg($dec['weather']['icon'] .'.svg');
            $replaceFC['#iconeFC#'] = $img;
            $replaceFC['#condition#'] = $dec['weather']['desc'];
// message::add(__CLASS__, __FUNCTION__ .' ' .$dec['weather']['icon'] .' ' .$dec['weather']['desc']);
            $replaceFC['#conditionid#'] = $jsonCmd->getId();
            $replaceFC['#low_temperature#'] = $dec['T']['value']."°C";
            $replaceFC['#high_temperature#'] = ''; // "(" .$dec['T']['windchill']."°C)";
            $html_forecast .= template_replace($replaceFC, $forecast_template);
            unset($replaceFC);
          }
        }
      }
          // Meteo par jour
      $nbDays = $this->getConfiguration('dailyForecastNumber',12);
      for($i=0;$i<$nbDays;$i++) {
        $replaceFC =array();
        $replaceFC['#sep#'] = '|';
        $jsonCmd = $this->getCmd(null, "MeteoDay${i}Json");
        if(is_object($jsonCmd)) {
          $val = str_replace('&quot;','"',$jsonCmd->execCmd());
          $dec = json_decode($val,true);
          /* JSON structure
            { "dt":1686096000,
              "T":{"min":15.9,"max":29,"sea":null},
              "humidity":{"min":50,"max":95},
              "precipitation":{"24h":0},"uv":9,
              "weather12H":{"icon":"p2j","desc":"Eclaircies"},
              "sun":{"rise":1686110309,"set":1686165626},"dt12H":1686132000
            }
           */
          if($dec !== false) {
            if($dec['dt12H'] < $lastTS) continue;
            $replaceFC['#day#'] = date_fr(date('D  j', $dec['dt']));
            $replaceFC['#moment#'] = '<br/>';
            $replaceFC['#time#'] = date('H:i',$dec['dt12H']);
            $img = self::getMFimg($dec['weather12H']['icon'] .'.svg');
            $replaceFC['#iconeFC#'] = $img;
            $replaceFC['#condition#'] = $dec['weather12H']['desc'];
// message::add(__CLASS__, __FUNCTION__ .' ' .$dec['weather12H']['icon'] .' ' .$dec['weather12H']['desc']);
            $replaceFC['#conditionid#'] = $jsonCmd->getId();
            $replaceFC['#low_temperature#'] = $dec['T']['min'];
            $replaceFC['#high_temperature#'] = $dec['T']['max'];
          }
        }
        else break;
        $html_forecast .= template_replace($replaceFC, $forecast_template);
        unset($replaceFC);
      }
    }
    $html_forecast .= '</tr></table>';
    $replace["#forecast#"] = "<div style=\"overflow-x: scroll; width: 100%; min-height: 15px; max-height: 200px; margin-top: 1px; font-size: 14px; text-align: left; scrollbar-width: thin;\">$html_forecast</div>\n";

    $replace['#city#'] = $this->getName();
    $replace['#cityName#'] = $ville;
    $replace['#cityZip#'] = $zip;

    $temperature = $this->getCmd(null, 'MeteonowTemperature');
    $replace['#temperature#'] = is_object($temperature) ? round($temperature->execCmd()) : '';
    $replace['#tempid#'] = is_object($temperature) ? $temperature->getId() : '';

    $temperature = $this->getCmd(null, 'MeteonowTemperatureRes');
    $replace['#ressentie#'] = is_object($temperature) ? round($temperature->execCmd()) : '';
    $replace['#ressid#'] = is_object($temperature) ? $temperature->getId() : '';

    $humidity = $this->getCmd(null, 'MeteonowHumidity');
    $replace['#humidity#'] = is_object($humidity) ? $humidity->execCmd() : '';

    $uvindex = $this->getCmd(null, 'Meteoday0indiceUV');
    $replace['#uvi#'] = is_object($uvindex) ? round($uvindex->execCmd()) : '';

    $pressure = $this->getCmd(null, 'MeteonowPression');
    $replace['#pressure#'] = is_object($pressure) ? $pressure->execCmd() : '';
    $replace['#pressureid#'] = is_object($pressure) ? $pressure->getId() : '';

    $wind_speed = $this->getCmd(null, 'MeteonowWindSpeed');
    if(is_object($wind_speed)) {
      $ws = $wind_speed->execCmd();
      if(!is_numeric($ws)) $ws = 0;
    }
    else $ws = 0;
    $replace['#windspeed#'] = $ws;
    $replace['#windid#'] = is_object($wind_speed) ? $wind_speed->getId() : '';

    $sunrise = $this->getCmd(null, 'Ephemerissunrise_time');
    $replace['#sunrise#'] = is_object($sunrise) ? substr_replace($sunrise->execCmd(),':',-2,0) : '';
    $replace['#sunriseid#'] = is_object($sunrise) ? $sunrise->getId() : '';

    $sunset = $this->getCmd(null, 'Ephemerissunset_time');
    $replace['#sunset#'] = is_object($sunset) ? substr_replace($sunset->execCmd(),':',-2,0) : '';
    $replace['#sunsetid#'] = is_object($sunset) ? $sunset->getId() : '';

    $windDirCmd = $this->getCmd(null, 'MeteonowWindDirection');
    if(is_object($windDirCmd)) {
      $windDirection = $windDirCmd->execCmd();
      if(!is_numeric($windDirection)) $windDirection = 0;
      $replace['#wind_direction#'] = $windDirection;
      $replace['#winddir#'] = $this->convertDegrees2Compass($windDirection,0);
      $replace['#wind_direction_vari#'] = $windDirection+180;
    }
    else {
      $replace['#wind_direction#'] = 0;
      $replace['#winddir#'] = '';
      $replace['#wind_direction_vari#'] = 180;
    }

    $windGust = $this->getCmd(null, 'MeteonowWindGust');
    if(is_object($windGust)) {
      $raf = $windGust->execCmd();
      if(!is_numeric($raf)) $raf = 0;
    }
    else $raf = 0;
    $replace['#windGust#'] = ($raf) ? ("&nbsp; ${raf}km/h &nbsp;") : '';

    $refresh = $this->getCmd(null, 'refresh');
    $replace['#refresh#'] = is_object($refresh) ? $refresh->getId() : '';

    $condition = $this->getCmd(null, 'MeteonowDescription');
    if (is_object($condition)) {
      $replace['#condition#'] = $condition->execCmd();
      $replace['#conditionid#'] = $condition->getId();
      $replace['#collectDate#'] = $condition->getCollectDate();
    } else {
      $replace['#condition#'] = '';
      $replace['#collectDate#'] = '';
    }

    $icone = $this->getCmd(null, 'MeteonowIcon');
    if(is_object($icone)) {
      $img = self::getMFimg($icone->execCmd() .'.svg');
      $replace['#icone#'] = $img;
    }

    $parameters = $this->getDisplay('parameters');
    if (is_array($parameters)) {
      foreach ($parameters as $key => $value) {
        $replace['#' . $key . '#'] = $value;
      }
    }

    $echeance = $this->getCmd(null,'Rainheure');
    if (is_object($echeance)) {
      $heure = substr_replace($echeance->execCmd(),':',-2,0);
      $replace['#heure#'] = $heure;
      // $replace['#h1h#'] = date('H:i',strtotime('+ 1 hour', mktime($heure[0] . $heure[1], $heure[3] . $heure[4])));
      $h = explode(':',$heure);
      $t = mktime((int)$h[0], (int)$h[1]);
      $replace['#h1h#'] = date('H:i', $t + 3600);
      $replace['#h10m#'] = date('H:i', $t + 600);
      $replace['#h20m#'] = date('H:i', $t + 1200);
      $replace['#h30m#'] = date('H:i', $t + 1800);
      $replace['#h40m#'] = date('H:i', $t + 2400);
      $replace['#h50m#'] = date('H:i', $t + 3000);
    }

    $color = Array();
    $color[0] = '';
    $color[1] = '';
    $color[2] = ' background: #AAE8FF';
    $color[3] = ' background: #48BFEA';
    $color[4] = ' background: #0094CE';

    for($i=1; $i <= 9; $i++){
      $prev = $this->getCmd(null,'Rainrain' . $i);
      $text = $this->getCmd(null,'Raindesc' . $i);
      if(is_object($prev)){
        $val = $prev->execCmd();
        $replace['#prev' . $i . '#'] = $val;
        $replace['#prev' . $i . 'Color#'] = $color[(is_numeric($val)?$val:0)];
        $replace['#prev' . $i . 'Text#'] = $text->execCmd();
      }
    }

    $color = array();
    $color[0] = ' color: #888888';
    $color[1] = ' color: #31AA35';
    $color[2] = ' color: #FFF600';
    $color[3] = ' color: #FFB82B';
    $color[4] = ' color: #CC0000';

    $maxColorCmd = $this->getCmd(null,'Vigilancecolor_max');
    $replace['#vigilance#'] = '<td class="tableCmdcss" style="width=10%;text-align: center" title="Vigilances">Pas de données de vigilance</td>';
    if(is_object($maxColorCmd)){
      $maxColor = $maxColorCmd->execCmd();
      if($maxColor > 0) {
        $prevVigRecup = trim(config::byKey('prevVigilanceRecovery', __CLASS__));
        if(date('Ymd') != substr($prevVigRecup,0,8)) {
          $img = 'VIGNETTE_NATIONAL_J1_500X500.png';
          $localFile = __DIR__ ."/../../data/$img";
          $img .= "?ts=" .@filemtime($localFile);
          $img2 = '';
        }
        else  {
          $img = 'VIGNETTE_NATIONAL_J_500X500.png';
          $localFile = __DIR__ ."/../../data/$img";
          $img .= "?ts=" .@filemtime($localFile);
          $img2 = 'VIGNETTE_NATIONAL_J1_500X500.png';
          $localFile = __DIR__ ."/../../data/$img2";
          $img2 .= "?ts=" .@filemtime($localFile);
        }
        $replace['#vigilance#'] = '<td class="tableCmdcss" style="width: 10%;text-align: center" title="Vigilance aujourd\'hui: ' .date_fr(date('d  F')) .'"><a href="https://vigilance.meteofrance.fr/fr" target="_blank"><img style="width:70px" src="plugins/meteofrance/data/' .$img .'"/></a></td>';
        // <td class="tableCmdcss" style="width: 10%;height:20px;text-align: center" title="#vigDesc#"><i class="wi #vigIcon#" style="font-size: 24px;#vigColors#"></i></td>
        foreach(self::$_vigilanceType as $i => $vig) {
          $vigilance = $this->getCmd(null, "Vigilancephenomenon_max_color_id$i");
          if(is_object($vigilance))  {
            $col = $vigilance->execCmd();
            if(!is_numeric($col)) $col = 0;
          }
          else $col = 0;
          $replace['#vig'.$i.'Colors#'] = $color[$col];
          $replace['#vig'.$i.'Icon#'] =  $vig['icon'];
          $phase = $this->getCmd(null, "Vigilancephases$i");
          $desc = '';
          if(is_object($phase))  {
            $txt = $phase->execCmd();
            if($txt != '') {
              $desc = " &nbsp; $txt";
            }
          }
          $replace['#vig'.$i.'Desc#'] = $vig['txt'] .$desc;
          if($col > 0) {
            if($i >= 1 && $i <= 10) {
              $file = __DIR__ ."/../template/images/Vigilance$i.svg";
              $svg = @file_get_contents($file);
              if($svg === false) log::add(__CLASS__, 'debug', "  Unable to read SVG : $file");
              else {
                $svg = str_replace('#888888', substr($color[$col],8),$svg);
                $replace['#vigilance#'] .= '<td class="tableCmdcss" style="width: 10%;height:20px;text-align: center" title="' .$vig['txt'] .$desc .'">' .$svg .'</td>';
              }
            }
            else
              $replace['#vigilance#'] .= '<td class="tableCmdcss" style="width: 10%;height:20px;text-align: center" title="' .$vigTxt .$desc .'"><i class="wi ' .$vig['icon'] .'" style="font-size: 24px;' .$color[$col] .'"></i></td>';
          }
        }
        if($img2 != '')
          $replace['#vigilance#'] .= '<td class="tableCmdcss" style="width: 10%;text-align: center" title="Vigilance demain: ' .date_fr(date('d  F',time()+86400)) .'"><a href="https://vigilance.meteofrance.fr/fr/demain" target="_blank"><img style="width:70px" src="plugins/meteofrance/data/' .$img2 .'"/></a></td>';
      }
    }
    if (file_exists( __DIR__ ."/../template/$_version/$templateFile.html"))
      return $this->postToHtml($_version, template_replace($replace, getTemplate('core', $version, $templateFile, __CLASS__)));
    else
      return $this->postToHtml($_version, template_replace($replace, getTemplate('core', $version, __CLASS__, __CLASS__)));
  }
}

class meteofranceCmd extends cmd {
  public function execute($_options = null) {
    if ($this->getLogicalId() == 'refresh') {
      $eqLogic = $this->getEqLogic();
/*
      foreach ($eqLogic->getCmd('info') as $cmd) {
        $val = $cmd->execCmd(null);
        $cmdLogicalId = $cmd->getLogicalId();
        if($cmd->getSubtype() == 'numeric')
          $eqLogic->checkAndUpdateCmd($cmdLogicalId,-666);
        else if($cmd->getSubtype() == 'string')
          $eqLogic->checkAndUpdateCmd($cmdLogicalId,"Obsolete command");
      }
*/
      $eqLogic->getInformations();
    }
  }
}
