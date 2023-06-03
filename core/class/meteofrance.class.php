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
    array("idx" => 1, "txt" => "Vent violent","icon" => "wi-strong-wind"),
    array("idx" => 2, "txt" => "Pluie","icon" => "wi-rain-wind"),
    array("idx" => 3, "txt" => "Orages","icon" => "wi-lightning"),
    array("idx" => 4, "txt" => "Inondation","icon" => "wi-flood"),
    array("idx" => 5, "txt" => "Neige-verglas","icon" => "wi-snow"),
    array("idx" => 6, "txt" => "Canicule","icon" => "wi-hot"),
    array("idx" => 7, "txt" => "Grand-froid","icon" => "wi-thermometer-exterior"),
    array("idx" => 8, "txt" => "Avalanches","icon" => "wi-na"),
    array("idx" => 9, "txt" => "Vagues-submersion","icon" => "wi-tsunami"),
    // array("idx" => 10, "txt" => "Incendie","icon" => "wi-fire")
  );

  public static function backupExclude() { return(array('data/*.json')); }

  public function checkAndUpdateCmd($_logicalId, $_value, $_updateTime = null) {
		$cmd = $this->getCmd('info', $_logicalId);
		if (!is_object($cmd)) {
      message::add(__CLASS__, "Equipment: " .$this->getName() ." Unexistant command $_logicalId");
    }
    parent::checkAndUpdateCmd($_logicalId, $_value, $_updateTime);
  }

  public static function getJsonTabInfo($cmd_id, $request) {
    $id = cmd::humanReadableToCmd('#' .$cmd_id .'#');
    $owmCmd = cmd::byId(trim(str_replace('#', '', $id)));
    if(is_object($owmCmd)) {
      $owmJson = $owmCmd->execCmd();
      $json =json_decode($owmJson,true);
      if($json === null)
        log::add(__CLASS__, 'debug', "Unable to decode json: " .substr($owmJson,0,50));
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
      $meteofrance->getNowDetails();
      $meteofrance->refreshWidget();
    }
  }

  public static function cron15() {
    if(date('i') == 0) return; // will be executed by cronHourly
    foreach (eqLogic::byType(__CLASS__, true) as $meteofrance) {
      $meteofrance->getVigilance();
      $meteofrance->refreshWidget();
    }
  }

  public static function cronHourly() {
    foreach (eqLogic::byType(__CLASS__, true) as $meteofrance) {
      $meteofrance->getInformations();
    }
  }

  public static function pullDataVigilance() {
    $recup = 1; $ret = 0;
    foreach (eqLogic::byType(__CLASS__, true) as $meteofrance) {
      if($recup) $ret = $meteofrance->getVigilanceDataApiCloudMF();
      $recup = 0;
      if($ret != -1) $meteofrance->getInformations();
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
        $cron->setSchedule(rand(5,25) .' 6-20 * * *');
        $cron->save();
      }
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
    $meteofrance->loadCmdFromConf('marine');
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
    $this->getMarine();
    $this->getTide();
    $this->getAlerts();
    $this->getEphemeris();
    $this->getBulletinFrance();
    $this->getBulletinSemaine();
    $this->getDetailsValues();
    $this->getBulletinVille();
    $this->getDailyExtras();
    $this->getNowDetails();
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
    if ($this->getConfiguration('bulletinVille','') == '') {
      return;
    }
    $url = 'https://rpcache-aa.meteofrance.com/wsft/files/agat/ville/bulvillefr_' . $this->getConfiguration('bulletinVille') . '.xml';
    log::add(__CLASS__, 'debug', __FUNCTION__ ." URL: $url");
    $return = self::callMeteoWS($url,true,false,__FUNCTION__ .".json");
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

  public function getNowDetails() { // hourly and daily forecast
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
    $updated_on = self::convertMFdt2UnixTS($return['updated_on'],$timezone);
    log::add(__CLASS__, 'debug', "  updated_on: " .date('d-m-Y H:i:s', $updated_on) ." Nbforecast: $nb Timezone: $timezone");
    $now = time();
    $found = 0; $j = 0;
      // Prévisions par heure
    for($i=0;$i<$nb-1;$i++) {
      // $forecastTS = self::convertMFdt2UnixTS($return['forecast'][$i]['dt'],$timezone);
      // $forecastNextTS = self::convertMFdt2UnixTS($return['forecast'][$i+1]['dt'],$timezone);
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
        $this->checkAndUpdateCmd("MeteoHour${j}Json", json_encode($value));
        $j++;
        if($j == 10) break;
      }
    }
    if($found == 0) {
      $this->checkAndUpdateCmd('MeteonowTemperature', -66);
      $this->checkAndUpdateCmd('MeteonowTemperatureRes', -66);
      $this->checkAndUpdateCmd('MeteonowHumidity', -66);
      $this->checkAndUpdateCmd('MeteonowPression', -66);
      $this->checkAndUpdateCmd('MeteonowWindSpeed', -66);
      $this->checkAndUpdateCmd('MeteonowWindGust', -66);
      $this->checkAndUpdateCmd('MeteonowWindSpeed', -66);
      $this->checkAndUpdateCmd('MeteonowRain1h', -66);
      $this->checkAndUpdateCmd('MeteonowSnow1h', -66);
      $this->checkAndUpdateCmd('MeteonowCloud', -66);
      $this->checkAndUpdateCmd('MeteonowIcon', "0");
      $this->checkAndUpdateCmd('MeteonowDescription', "Hour not found");
      $this->checkAndUpdateCmd('Meteodayh1description', "Hour not found");
      $this->checkAndUpdateCmd('Meteodayh1temperature', -66);
      $this->checkAndUpdateCmd('Meteodayh1temperatureRes', -66);
      $this->checkAndUpdateCmd('hourly1icon', "0");
    }
      // TODO update the daily_forecast commands
    $nbD = count($return['daily_forecast']);
    log::add(__CLASS__, 'debug', "  NbDaily_forecast: $nbD");
    for($i=0;$i<$nbD;$i++) {
      $value= $return['daily_forecast'][$i];
      $forecastTS = self::convertMFdt2UnixTS($value['dt'],$timezone);
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
      $value['dt'] = $forecastTS+43200;
      $this->checkAndUpdateCmd("MeteoDay${i}Json", json_encode($value));
    }
  }

  public function getDailyExtras() {
    $lat = $this->getConfiguration('lat'); $lon = $this->getConfiguration('lon');
    $ville = $this->getConfiguration('ville');
    log::add(__CLASS__, 'debug', __FUNCTION__ ." $ville $lat/$lon");
    if($lat == '' || $lon == '') {
      log::add(__CLASS__, 'debug', "  Invalid latitude/longitude: $lat/$lon");
      return;
    }
    $url = "https://rpcache-aa.meteofrance.com/internet2018client/2.0/forecast?lat=$lat&lon=$lon&id=&instants=morning,afternoon,evening,night";
    $return = self::callMeteoWS($url,false,true,__FUNCTION__ ."-$ville.json");

    if(isset($return['probability_forecast'])) {
      $this->checkAndUpdateCmd('MeteoprobaPluie', $return['probability_forecast'][0]['rain_hazard_3h']);
      $this->checkAndUpdateCmd('MeteoprobaNeige', $return['probability_forecast'][0]['snow_hazard_3h']);
      $this->checkAndUpdateCmd('MeteoprobaGel', $return['probability_forecast'][0]['freezing_hazard']);
      $this->checkAndUpdateCmd('MeteoprobaStorm', $return['probability_forecast'][0]['storm_hazard']);
    }
    else {
      $this->checkAndUpdateCmd('MeteoprobaPluie', -1);
      $this->checkAndUpdateCmd('MeteoprobaNeige', -1);
      $this->checkAndUpdateCmd('MeteoprobaGel', -1);
      $this->checkAndUpdateCmd('MeteoprobaStorm', -1);
    }
  }

  public function getDetailsValues() { // Instant forecast (morning,afternoon,evening,night)
    $lat = $this->getConfiguration('lat'); $lon = $this->getConfiguration('lon');
    $ville = $this->getConfiguration('ville');
    log::add(__CLASS__, 'debug', __FUNCTION__ ." $ville $lat/$lon");
    if($lat == '' || $lon == '') {
      log::add(__CLASS__, 'debug', "  Invalid latitude/longitude: $lat/$lon");
      return;
    }
    $url = "https://webservice.meteofrance.com/forecast?lat=$lat&lon=$lon&id=&instants=morning,afternoon,evening,night";
    // $url = "https://rpcache-aa.meteofrance.com/internet2018client/2.0/forecast?lat=$lat&lon=$lon&id=&instants=morning,afternoon,evening,night";
    $return = self::callMeteoWS($url,false,true,__FUNCTION__ ."-$ville.json");
    $nb = count($return['forecast']);
    $updated_on = $return['updated_on'];
    log::add(__CLASS__, 'debug', "  updated_on: " .date('d-m-Y H:i:s', $updated_on) ." Nbforecast: $nb");
    // add dt_beg, dt_end and moment_day in json
    for($i=0;$i<$nb-1;$i++) {
      $return['forecast'][$i]['dt_beg'] = $return['forecast'][$i]['dt'] - 3*3600;
      $return['forecast'][$i]['dt_end'] = $return['forecast'][$i+1]['dt'] - 3*3600;
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
      $forecastTS = $return['forecast'][$i]['dt_beg'];
      $forecastNextTS = $return['forecast'][$i]['dt_end'];
      $value = $return['forecast'][$i];
      log::add(__CLASS__, 'debug', "    $i forecast:" .date('d-m-Y H:i:s', $forecastTS) ." Desc: " .$value['weather']['desc']);
      if(($now >= $forecastTS && $now < $forecastNextTS) || ($i==0 && $now < $forecastTS)) {
        $found = 1;
        for($j=0;$j<8;$j++) {
          $value = $return['forecast'][$i+$j];
          log::add(__CLASS__, 'debug', "    Filling: $j forecast:" .date('d-m-Y H:i:s', $value['dt']) ." Moment: " .$value['moment_day']." Json: " .json_encode($value));
          $this->checkAndUpdateCmd("MeteoInstant${j}Json", json_encode($value));
        }
        break;
      }
    }
    if(!$found) {
      for($i=0;$i<8;$i++) {
        $this->checkAndUpdateCmd("MeteoInstant${i}Json", '');
      }
    }
 /* // TODO verify usefulness and delete old unused commands
    $step = 'soirée';
    switch ($return['properties']['forecast'][0]['moment_day']) {
      case 'nuit': $step = 'nuit'; break;
      case 'matin': $step = 'matin'; break;
      case 'après-midi': $step = 'après-midi'; break;
    }
    $i = 0;
    log::add(__CLASS__, 'debug', '    Moment journée : ' . $step);

    if ($step == 'nuit') {
      $this->checkAndUpdateCmd('Meteonuit0description', $return['properties']['forecast'][$i]['weather_description']);
      $this->checkAndUpdateCmd('Meteonuit0directionVent', $return['properties']['forecast'][$i]['wind_direction']);
      $this->checkAndUpdateCmd('Meteonuit0vitesseVent', $return['properties']['forecast'][$i]['wind_speed']);
      $this->checkAndUpdateCmd('Meteonuit0forceRafales', $return['properties']['forecast'][$i]['wind_speed_gust']);
      $this->checkAndUpdateCmd('Meteonuit0temperatureMin', $return['properties']['forecast'][$i]['T']);
      $this->checkAndUpdateCmd('Meteonuit0temperatureMax', $return['properties']['forecast'][$i]['T_windchill']);
      $i++;
      $this->checkAndUpdateCmd('Meteomatin0description', $return['properties']['forecast'][$i]['weather_description']);
      $this->checkAndUpdateCmd('Meteomatin0directionVent', $return['properties']['forecast'][$i]['wind_direction']);
      $this->checkAndUpdateCmd('Meteomatin0vitesseVent', $return['properties']['forecast'][$i]['wind_speed']);
      $this->checkAndUpdateCmd('Meteomatin0forceRafales', $return['properties']['forecast'][$i]['wind_speed_gust']);
      $this->checkAndUpdateCmd('Meteomatin0temperatureMin', $return['properties']['forecast'][$i]['T']);
      $this->checkAndUpdateCmd('Meteomatin0temperatureMax', $return['properties']['forecast'][$i]['T_windchill']);
      $i++;
      $this->checkAndUpdateCmd('Meteomidi0description', $return['properties']['forecast'][$i]['weather_description']);
      $this->checkAndUpdateCmd('Meteomidi0directionVent', $return['properties']['forecast'][$i]['wind_direction']);
      $this->checkAndUpdateCmd('Meteomidi0vitesseVent', $return['properties']['forecast'][$i]['wind_speed']);
      $this->checkAndUpdateCmd('Meteomidi0forceRafales', $return['properties']['forecast'][$i]['wind_speed_gust']);
      $this->checkAndUpdateCmd('Meteomidi0temperatureMin', $return['properties']['forecast'][$i]['T']);
      $this->checkAndUpdateCmd('Meteomidi0temperatureMax', $return['properties']['forecast'][$i]['T_windchill']);
      $i++;
    } else {
      $this->checkAndUpdateCmd('Meteonuit0description', '');
      $this->checkAndUpdateCmd('Meteonuit0directionVent', '');
      $this->checkAndUpdateCmd('Meteonuit0vitesseVent', '');
      $this->checkAndUpdateCmd('Meteonuit0forceRafales', '');
      $this->checkAndUpdateCmd('Meteonuit0temperatureMin', '');
      $this->checkAndUpdateCmd('Meteonuit0temperatureMax', '');
    }

    if ($step == 'matin') {
      $this->checkAndUpdateCmd('Meteomatin0description', $return['properties']['forecast'][$i]['weather_description']);
      $this->checkAndUpdateCmd('Meteomatin0directionVent', $return['properties']['forecast'][$i]['wind_direction']);
      $this->checkAndUpdateCmd('Meteomatin0vitesseVent', $return['properties']['forecast'][$i]['wind_speed']);
      $this->checkAndUpdateCmd('Meteomatin0forceRafales', $return['properties']['forecast'][$i]['wind_speed_gust']);
      $this->checkAndUpdateCmd('Meteomatin0temperatureMin', $return['properties']['forecast'][$i]['T']);
      $this->checkAndUpdateCmd('Meteomatin0temperatureMax', $return['properties']['forecast'][$i]['T_windchill']);
      $i++;
      $this->checkAndUpdateCmd('Meteomidi0description', $return['properties']['forecast'][$i]['weather_description']);
      $this->checkAndUpdateCmd('Meteomidi0directionVent', $return['properties']['forecast'][$i]['wind_direction']);
      $this->checkAndUpdateCmd('Meteomidi0vitesseVent', $return['properties']['forecast'][$i]['wind_speed']);
      $this->checkAndUpdateCmd('Meteomidi0forceRafales', $return['properties']['forecast'][$i]['wind_speed_gust']);
      $this->checkAndUpdateCmd('Meteomidi0temperatureMin', $return['properties']['forecast'][$i]['T']);
      $this->checkAndUpdateCmd('Meteomidi0temperatureMax', $return['properties']['forecast'][$i]['T_windchill']);
      $i++;
    } else {
      $this->checkAndUpdateCmd('Meteomatin0description', '');
      $this->checkAndUpdateCmd('Meteomatin0directionVent', '');
      $this->checkAndUpdateCmd('Meteomatin0vitesseVent', '');
      $this->checkAndUpdateCmd('Meteomatin0forceRafales', '');
      $this->checkAndUpdateCmd('Meteomatin0temperatureMin', '');
      $this->checkAndUpdateCmd('Meteomatin0temperatureMax', '');
    }

    if ($step == 'après-midi') {
      $this->checkAndUpdateCmd('Meteomidi0description', $return['properties']['forecast'][$i]['weather_description']);
      $this->checkAndUpdateCmd('Meteomidi0directionVent', $return['properties']['forecast'][$i]['wind_direction']);
      $this->checkAndUpdateCmd('Meteomidi0vitesseVent', $return['properties']['forecast'][$i]['wind_speed']);
      $this->checkAndUpdateCmd('Meteomidi0forceRafales', $return['properties']['forecast'][$i]['wind_speed_gust']);
      $this->checkAndUpdateCmd('Meteomidi0temperatureMin', $return['properties']['forecast'][$i]['T']);
      $this->checkAndUpdateCmd('Meteomidi0temperatureMax', $return['properties']['forecast'][$i]['T_windchill']);
      $i++;
    } else {
      $this->checkAndUpdateCmd('Meteomidi0description', '');
      $this->checkAndUpdateCmd('Meteomidi0directionVent', '');
      $this->checkAndUpdateCmd('Meteomidi0vitesseVent', '');
      $this->checkAndUpdateCmd('Meteomidi0forceRafales', '');
      $this->checkAndUpdateCmd('Meteomidi0temperatureMin', '');
      $this->checkAndUpdateCmd('Meteomidi0temperatureMax', '');
    }

    $this->checkAndUpdateCmd('Meteosoir0description', $return['properties']['forecast'][$i]['weather_description']);
    $this->checkAndUpdateCmd('Meteosoir0directionVent', $return['properties']['forecast'][$i]['wind_direction']);
    $this->checkAndUpdateCmd('Meteosoir0vitesseVent', $return['properties']['forecast'][$i]['wind_speed']);
    $this->checkAndUpdateCmd('Meteosoir0forceRafales', $return['properties']['forecast'][$i]['wind_speed_gust']);
    $this->checkAndUpdateCmd('Meteosoir0temperatureMin', $return['properties']['forecast'][$i]['T']);
    $this->checkAndUpdateCmd('Meteosoir0temperatureMax', $return['properties']['forecast'][$i]['T_windchill']);
    $i++;

    $this->checkAndUpdateCmd('Meteonuit1description', $return['properties']['forecast'][$i]['weather_description']);
    $this->checkAndUpdateCmd('Meteonuit1directionVent', $return['properties']['forecast'][$i]['wind_direction']);
    $this->checkAndUpdateCmd('Meteonuit1vitesseVent', $return['properties']['forecast'][$i]['wind_speed']);
    $this->checkAndUpdateCmd('Meteonuit1forceRafales', $return['properties']['forecast'][$i]['wind_speed_gust']);
    $this->checkAndUpdateCmd('Meteonuit1temperatureMin', $return['properties']['forecast'][$i]['T']);
    $this->checkAndUpdateCmd('Meteonuit1temperatureMax', $return['properties']['forecast'][$i]['T_windchill']);
    $i++;

    $this->checkAndUpdateCmd('Meteomatin1description', $return['properties']['forecast'][$i]['weather_description']);
    $this->checkAndUpdateCmd('Meteomatin1directionVent', $return['properties']['forecast'][$i]['wind_direction']);
    $this->checkAndUpdateCmd('Meteomatin1vitesseVent', $return['properties']['forecast'][$i]['wind_speed']);
    $this->checkAndUpdateCmd('Meteomatin1forceRafales', $return['properties']['forecast'][$i]['wind_speed_gust']);
    $this->checkAndUpdateCmd('Meteomatin1temperatureMin', $return['properties']['forecast'][$i]['T']);
    $this->checkAndUpdateCmd('Meteomatin1temperatureMax', $return['properties']['forecast'][$i]['T_windchill']);
    $i++;

    $this->checkAndUpdateCmd('Meteomidi1description', $return['properties']['forecast'][$i]['weather_description']);
    $this->checkAndUpdateCmd('Meteomidi1directionVent', $return['properties']['forecast'][$i]['wind_direction']);
    $this->checkAndUpdateCmd('Meteomidi1vitesseVent', $return['properties']['forecast'][$i]['wind_speed']);
    $this->checkAndUpdateCmd('Meteomidi1forceRafales', $return['properties']['forecast'][$i]['wind_speed_gust']);
    $this->checkAndUpdateCmd('Meteomidi1temperatureMin', $return['properties']['forecast'][$i]['T']);
    $this->checkAndUpdateCmd('Meteomidi1temperatureMax', $return['properties']['forecast'][$i]['T_windchill']);
    $i++;

    log::add(__CLASS__, 'debug', '    Meteosoir1description : ' .$return['properties']['forecast'][$i]['weather_description']);
    $this->checkAndUpdateCmd('Meteosoir1description', $return['properties']['forecast'][$i]['weather_description']);
    $this->checkAndUpdateCmd('Meteosoir1directionVent', $return['properties']['forecast'][$i]['wind_direction']);
    $this->checkAndUpdateCmd('Meteosoir1vitesseVent', $return['properties']['forecast'][$i]['wind_speed']);
    $this->checkAndUpdateCmd('Meteosoir1forceRafales', $return['properties']['forecast'][$i]['wind_speed_gust']);
    $this->checkAndUpdateCmd('Meteosoir1temperatureMin', $return['properties']['forecast'][$i]['T']);
    $this->checkAndUpdateCmd('Meteosoir1temperatureMax', $return['properties']['forecast'][$i]['T_windchill']);
*/
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
	  if (!$this->getConfiguration('bulletinCote')) {
		  return;
	  }
	  $lat = $this->getConfiguration('lat'); $lon = $this->getConfiguration('lon');
	  if($lat == '' || $lon == '') {
		  log::add(__CLASS__, 'debug', __FUNCTION__ ." Invalid latitude/longitude: $lat/$lon");
      return;
    }
    log::add(__CLASS__, 'debug', __FUNCTION__ ." $lat/$lon");
    $url = "https://rpcache-aa.meteofrance.com/internet2018client/2.0/forecast/marine?lat=$lat&lon=$lon";
    $return = self::callMeteoWS($url,false,true,__FUNCTION__ ."-2-${lat}_$lon.json");
    foreach ($return['properties']['marine'] as $id => $marine) {
	    $id = 0; // Pas d'autre commande que 0 TODO
      if(time() < strtotime($marine['time'])) break;
	    if(time() >= strtotime($marine['time'])) {
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
	    }
    }
  }

  public function getTide() {
    if (!$this->getConfiguration('bulletinCote')) {
      return;
    }
    $insee = $this->getConfiguration('insee');
    $ville = $this->getConfiguration('ville');
    log::add(__CLASS__, 'debug', __FUNCTION__ ." Insee: $insee Ville $ville");
    $url = 'https://rpcache-aa.meteofrance.com/internet2018client/2.0/tide?id=' .$insee .'52';
    // $return = self::callMeteoWS($url);
    $return = self::callMeteoWS($url,false,true,__FUNCTION__ ."-${insee}-$ville.json");
    if(isset($return['properties']['tide'])) {
      $this->checkAndUpdateCmd('Tidehigh_tide0time', date('Hi',strtotime($return['properties']['tide']['high_tide'][0]['time'])));
      $this->checkAndUpdateCmd('Tidehigh_tide0tidal_coefficient', $return['properties']['tide']['high_tide'][0]['tidal_coefficient']);
      $this->checkAndUpdateCmd('Tidehigh_tide0tidal_height', $return['properties']['tide']['high_tide'][0]['tidal_height']);
      $this->checkAndUpdateCmd('Tidehigh_tide1time', date('Hi',strtotime($return['properties']['tide']['high_tide'][1]['time'])));
      $this->checkAndUpdateCmd('Tidehigh_tide1tidal_coefficient', $return['properties']['tide']['high_tide'][1]['tidal_coefficient']);
      $this->checkAndUpdateCmd('Tidehigh_tide1tidal_height', $return['properties']['tide']['high_tide'][1]['tidal_height']);
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
      $url = "http://storage.gra.cloud.ovh.net/v1/AUTH_555bdc85997f4552914346d4550c421e/gra-vigi6-archive_public/" .date('Y') ."/" .date('m') ."/" .date('d') ."/";
      log::add(__CLASS__, 'debug', "  Fetching MF archives $url");
      $doc = new DOMDocument();
      libxml_use_internal_errors(true); // disable warning
      $doc->preserveWhiteSpace = false;
      if(@$doc->loadHTMLFile($url) !== false ) {
        $xpath = new DOMXpath($doc);
        $subdir = $xpath->query('//html/body/table/tr[@class="item subdir"]/td/a');
        $nb = count($subdir);
        $latest = '0';
        for($i=0;$i<$nb;$i++) {
          $val = $subdir[$i]->getAttribute('href');
          $val2 = substr($val,0,-1);
          if($val2 > $latest) $latest = $val2;
          log::add(__CLASS__, 'debug', "  Val: [$val] Latest: $latest");
        }
        $prevRecup = trim(config::byKey('prevVigilanceRecovery', __CLASS__));
        $latestFull = date('Y').date('m').date('d') .$latest .'Z';
        if($prevRecup != $latestFull) {
          log::add(__CLASS__, 'debug', "  Using: $latest data Previous: $prevRecup");
          $contents = @file_get_contents($url.$latest ."/CDP_CARTE_EXTERNE.json");
          if($contents !== false) {
            $hdle = fopen($fileAlert, "wb");
            if($hdle !== FALSE) { fwrite($hdle, $contents); fclose($hdle); }
          }
          else log::add(__CLASS__, 'warning', "  Unable to download CDP_CARTE_EXTERNE.json");
          $contents = @file_get_contents($url.$latest ."/VIGNETTE_NATIONAL_J_500X500.png");
          if($contents !== false) {
            $hdle = fopen($fileVignetteJ, "wb");
            if($hdle !== FALSE) { fwrite($hdle, $contents); fclose($hdle); }
          }
          else log::add(__CLASS__, 'warning', "  Unable to download VIGNETTE_NATIONAL_J_500X500.json");
          $contents = @file_get_contents($url.$latest ."/VIGNETTE_NATIONAL_J1_500X500.png");
          if($contents !== false) {
            $hdle = fopen($fileVignetteJ1, "wb");
            if($hdle !== FALSE) { fwrite($hdle, $contents); fclose($hdle); }
          }
          else log::add(__CLASS__, 'warning', "  Unable to download VIGNETTE_NATIONAL_J1_500X500.json");
          config::save('prevVigilanceRecovery', $latestFull, __CLASS__);
          $loglevel = log::convertLogLevel(log::getLogLevel(__CLASS__));
          if($loglevel == 'debug') {
            $hdle = fopen(__DIR__ ."/../../data/dayAlerts.html", "wb");
            if($hdle !== FALSE) { fwrite($hdle, $doc->saveHTML()); fclose($hdle); }
          }
        }
        else {
          log::add(__CLASS__, 'debug', "  Unchanged data: $prevRecup");
          return -1; // unchanged
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
    $value[0]=''; $value[1]="Vert"; $value[2]="Jaune"; $value[3]="Orange"; $value[4]="Rouge";
    $numDept = $this->getConfiguration('numDept');
    if($numDept == '') {
      log::add(__CLASS__, 'debug', __FUNCTION__ ." Département non défini.");
      return;
    }
    log::add(__CLASS__, 'debug', __FUNCTION__ ." Département: $numDept");
    $contents =@file_get_contents(__DIR__ ."/../../data/CDP_CARTE_EXTERNE.json");
    if($contents === false) {
      // TODO clean des cmds ou pas ?
      return;
    }
    $return = json_decode($contents,true);
    if($return === false) {
      // TODO clean des cmds ou pas ?
      return;
    }
    $txtTsAlerts = array(); $phenomColor = array();
        // init all values
    foreach(self::$_vigilanceType as $vig) {
      $i = $vig['idx']; 
      $txtTsAlerts[$i] = ''; $phenomColor[$i] = 0;
    }
    $maxColor = 0; $t = time();
    // $numDept = '06';
    foreach($return['product']['periods'] as $period) {
      $startPeriod = strtotime($period['begin_validity_time']);
      $endPeriod = strtotime($period['end_validity_time']);
      if($t > $endPeriod || $t < $startPeriod) continue;
      log::add(__CLASS__, 'debug', "  Validity period start: " .date("d-m-Y H:i",$startPeriod) ." end: " .date("d-m-Y H:i",$endPeriod));
      foreach($period['timelaps']['domain_ids'] as $domain_id) {
        $dept = $domain_id['domain_id'];
        if($dept == $numDept || $dept == $numDept .'10') { // concat 10 si departement bord de mer
          foreach($domain_id['phenomenon_items'] as $phenomenonItem) {
            $phenId = $phenomenonItem['phenomenon_id'];
            $color = $phenomenonItem['phenomenon_max_color_id'];
            if($color > $maxColor) $maxColor = $color;
            $phenomColor[$phenId] = $color;
            foreach($phenomenonItem['timelaps_items'] as $timelapsItem) {
              $colorTs = $timelapsItem['color_id'];
              if($colorTs != 0) {
                $begin = strtotime($timelapsItem['begin_time']);
                $end = strtotime($timelapsItem['end_time']);
                if($colorTs > 1) {
                  if($color != $colorTs)
                    $txtTsAlerts[$phenId] .= ' ' .$value[$colorTs] .' de ' .date('H:i',$begin) .' à ' .date('H:i',$end);
                  else
                    $txtTsAlerts[$phenId] .= ' de ' .date('H:i',$begin) .' à ' .date('H:i',$end);
                }
                log::add(__CLASS__, 'debug', "  PhenomId: $phenId Color: $color start:" .date("d-m-Y H:i:s",$begin)." End:" .date("d-m-Y H:i:s",$end) ." MaxColor: $maxColor"); 
              }
            }
          }
        }
      }
    }
    $this->checkAndUpdateCmd('Vigilancecolor_max', $maxColor);
    foreach(self::$_vigilanceType as $vig) {
      $i = $vig['idx']; 
      // if($phenomColor[$i] > 1) message::add(__CLASS__, "Vigilance $i " .$phenomColor[$i] .$txtTsAlerts[$i]);
      $this->checkAndUpdateCmd("Vigilancephases$i",
        $value[$phenomColor[$i]]
        .$txtTsAlerts[$i]);
      $this->checkAndUpdateCmd("Vigilancephenomenon_max_color_id$i", $phenomColor[$i]);
    }

/* Avant API de vigilance officielle
    $url = "https://webservice.meteofrance.com/warning/currentphenomenons?domain=$numDept";
    $return = self::callMeteoWS($url,false,true,__FUNCTION__ .'-' .$numDept .".json");
    $phases = array(); $phenomColor = array();
        // init all values
    foreach(self::$_vigilanceType as $vig) {
      $i = $vig['idx']; 
      // message::add(__CLASS__, "init Vigilance $i " .$vig['txt']);
      $phases[$i] = ''; $phenomColor[$i] = 0;
    }
    if(!isset($return['error'])) {
      $maxColor = 0;
      $endValidityTime = $return['end_validity_time'];
      if(time() > $endValidityTime) {
        log::add(__CLASS__, 'debug', "  Fin validité vigilance $numDept: ".date('d-m-Y H:i:s',$endValidityTime));
        foreach(self::$_vigilanceType as $vig) {
          $id = $vig['idx']; 
          $phases[$id] = 'Données de vigilance invalides';
        }
      }
      else {
        foreach($return['phenomenons_max_colors'] as $alert) {
          $id = $alert['phenomenon_id'];
          $color = $alert['phenomenon_max_color_id'];
          $phases[$id] = $value[$color]; // self::$_vigilanceType[$id];
          $phenomColor[$id] = $color;
          if($color > $maxColor) $maxColor = $color;
        }
      }
      $this->checkAndUpdateCmd('Vigilancecolor_max', $maxColor); // TODO command not created
    }
    else {
      log::add(__CLASS__, 'warning', __FUNCTION__ ." Département: $numDept Erreur: " .$return['error'] ." Message: " .$return['message']);
    }
    foreach(self::$_vigilanceType as $vig) {
      $i = $vig['idx']; 
      // if($phenomColor[$i] <= 0) message::add(__CLASS__, "Vigilance $i " .$phenomColor[$i]);
      $this->checkAndUpdateCmd("Vigilancephases$i", $phases[$i]);
      $this->checkAndUpdateCmd("Vigilancephenomenon_max_color_id$i", $phenomColor[$i]);
    }
 */
    /*
     *  $listVigilance = array(); TODO command not updated
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
    log::add(__CLASS__, 'debug', __FUNCTION__ ." Template: $templateFile");

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
          $dec = json_decode($jsonCmd->execCmd(),true);
          if($dec !== false) {
            $lastTS = $dec['dt'];
            $h = date("H",$lastTS);
            $img = self::getMFimg($dec['weather']['icon'] .'.svg');
            $replaceFC['#iconeFC#'] = $img;
            $replaceFC['#low_temperature#'] = $dec['T']['value']."°C";
            $replaceFC['#high_temperature#'] = ''; // "(" .$dec['T']['windchill']."°C)";
            $replaceFC['#condition#'] = $dec['weather']['desc'];
message::add(__CLASS__, __FUNCTION__ .' ' .$dec['weather']['icon'] .' ' .$dec['weather']['desc']);
            $replaceFC['#day#'] = '<br/>';
            $replaceFC['#moment#'] = '';
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
          $dec = json_decode($jsonCmd->execCmd(),true);
          if($dec !== false) {
            $replaceFC = array();
            $replaceFC['#sep#'] = '';
            $lastTS = $dec['dt']; // ($dec['dt_beg'] +$dec['dt_end'])/2;
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
            $replaceFC['#time#'] = '';
            $replaceFC['#time#'] = date('H',$dec['dt_beg']) ."&nbsp;h&nbsp;-&nbsp;" .date('H',$dec['dt_end']) ."&nbsp;h";
            $img = self::getMFimg($dec['weather']['icon'] .'.svg');
            $replaceFC['#iconeFC#'] = $img;
            $replaceFC['#condition#'] = $dec['weather']['desc'];
message::add(__CLASS__, __FUNCTION__ .' ' .$dec['weather']['icon'] .' ' .$dec['weather']['desc']);
            $replaceFC['#conditionid#'] = $jsonCmd->getId();
            $replaceFC['#low_temperature#'] = $dec['T']['value']."°C";
            $replaceFC['#high_temperature#'] = ''; // "(" .$dec['T']['windchill']."°C)";
            $html_forecast .= template_replace($replaceFC, $forecast_template);
            unset($replaceFC);
          }
        }
      }
          // Meteo par jour
      $nbDays = $this->getConfiguration('dailyForecastNumber',4);
      $start = $this->getConfiguration('todayForecast',0);
      if($start == 0) { $start = 1; $nbDays += 1; }
      else { $start=0; }
      for($i=0;$i<$nbDays;$i++) {
        $replaceFC =array();
        $replaceFC['#sep#'] = '|';
        $jsonCmd = $this->getCmd(null, "MeteoDay${i}Json");
        if(is_object($jsonCmd)) {
          $dec = json_decode($jsonCmd->execCmd(),true);
          if($dec !== false) {
            if($dec['dt'] < $lastTS) continue;
            $replaceFC['#day#'] = date_fr(date('D  j', $dec['dt']));
            $replaceFC['#moment#'] = '';
            $replaceFC['#time#'] = date('H:i',$dec['dt']);
            $img = self::getMFimg($dec['weather12H']['icon'] .'.svg');
            $replaceFC['#iconeFC#'] = $img;
            $replaceFC['#condition#'] = $dec['weather12H']['desc'];
message::add(__CLASS__, __FUNCTION__ .' ' .$dec['weather12H']['icon'] .' ' .$dec['weather12H']['desc']);
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
      $windDirection = $windDirCmd->execCmd(); //TODO valeur non numerique null ?
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
    if(is_object($windGust)) $raf = $windGust->execCmd(); else $raf = 0;
    // $raf = 150;
    $replace['#windGust#'] = ($raf) ? ("&nbsp; ${raf}km/h &nbsp;") : '<br/>'; // br pour occuper la place

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
          $img .= "?ts=" .filemtime($localFile);
          $img2 = '';
        }
        else  {
          $img = 'VIGNETTE_NATIONAL_J_500X500.png';
          $localFile = __DIR__ ."/../../data/$img";
          $img .= "?ts=" .filemtime($localFile);
          $img2 = 'VIGNETTE_NATIONAL_J1_500X500.png';
          $localFile = __DIR__ ."/../../data/$img2";
          $img2 .= "?ts=" .filemtime($localFile);
        }
        $replace['#vigilance#'] = '<td class="tableCmdcss" style="width: 10%;text-align: center" title="Vigilance aujourd\'hui: ' .date_fr(date('d  F')) .'"><a href="https://vigilance.meteofrance.fr/fr" target="_blank"><img style="width:70px" src="plugins/meteofrance/data/' .$img .'"/></a></td>';
        // <td class="tableCmdcss" style="width: 10%;height:20px;text-align: center" title="#vigDesc#"><i class="wi #vigIcon#" style="font-size: 24px;#vigColors#"></i></td>
        foreach(self::$_vigilanceType as $vig) {
          $i = $vig['idx']; 
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
            if($txt != '') $desc = " - $txt";
          }
          $replace['#vig'.$i.'Desc#'] = $vig['txt'] .$desc;
          if($col > 0) {
            if($i == 1)
              $replace['#vigilance#'] .= '<td class="tableCmdcss" style="width: 10%;height:20px;text-align: center" title="' .$vig['txt'] .$desc .'"><svg xmlns="http://www.w3.org/2000/svg" width="25" height="25" viewBox="0 0 512 512"><path fill="' .substr($color[$col],8).'" d="M288 32c0 17.7 14.3 32 32 32h32c17.7 0 32 14.3 32 32s-14.3 32-32 32H32c-17.7 0-32 14.3-32 32s14.3 32 32 32H352c53 0 96-43 96-96s-43-96-96-96H320c-17.7 0-32 14.3-32 32zm64 352c0 17.7 14.3 32 32 32h32c53 0 96-43 96-96s-43-96-96-96H32c-17.7 0-32 14.3-32 32s14.3 32 32 32H416c17.7 0 32 14.3 32 32s-14.3 32-32 32H384c-17.7 0-32 14.3-32 32zM128 512h32c53 0 96-43 96-96s-43-96-96-96H32c-17.7 0-32 14.3-32 32s14.3 32 32 32H160c17.7 0 32 14.3 32 32s-14.3 32-32 32H128c-17.7 0-32 14.3-32 32s14.3 32 32 32z"/></svg></td>';
            else if($i == 2)
              $replace['#vigilance#'] .= '<td class="tableCmdcss" style="width: 10%;height:20px;text-align: center" title="' .$vig['txt'] .$desc .'"><svg xmlns="http://www.w3.org/2000/svg" width="25" height="25" viewBox="0 0 512 512"><path fill="' .substr($color[$col],8).'" d="M96 320c-53 0-96-43-96-96c0-42.5 27.6-78.6 65.9-91.2C64.7 126.1 64 119.1 64 112C64 50.1 114.1 0 176 0c43.1 0 80.5 24.3 99.2 60c14.7-17.1 36.5-28 60.8-28c44.2 0 80 35.8 80 80c0 5.5-.6 10.8-1.6 16c.5 0 1.1 0 1.6 0c53 0 96 43 96 96s-43 96-96 96H96zM81.5 353.9c12.2 5.2 17.8 19.3 12.6 31.5l-48 112c-5.2 12.2-19.3 17.8-31.5 12.6S-3.3 490.7 1.9 478.5l48-112c5.2-12.2 19.3-17.8 31.5-12.6zm120 0c12.2 5.2 17.8 19.3 12.6 31.5l-48 112c-5.2 12.2-19.3 17.8-31.5 12.6s-17.8-19.3-12.6-31.5l48-112c5.2-12.2 19.3-17.8 31.5-12.6zm244.6 31.5l-48 112c-5.2 12.2-19.3 17.8-31.5 12.6s-17.8-19.3-12.6-31.5l48-112c5.2-12.2 19.3-17.8 31.5-12.6s17.8 19.3 12.6 31.5zM313.5 353.9c12.2 5.2 17.8 19.3 12.6 31.5l-48 112c-5.2 12.2-19.3 17.8-31.5 12.6s-17.8-19.3-12.6-31.5l48-112c5.2-12.2 19.3-17.8 31.5-12.6z"/></svg></td>';
            else if($i == 3)
              $replace['#vigilance#'] .= '<td class="tableCmdcss" style="width: 10%;height:20px;text-align: center" title="' .$vig['txt'] .$desc .'"><svg xmlns="http://www.w3.org/2000/svg" width="25" height="25" viewBox="0 0 512 512"><path fill="' .substr($color[$col],8).'" d="M0 224c0 53 43 96 96 96h47.2L290 202.5c17.6-14.1 42.6-14 60.2 .2s22.8 38.6 12.8 58.8L333.7 320H352h64c53 0 96-43 96-96s-43-96-96-96c-.5 0-1.1 0-1.6 0c1.1-5.2 1.6-10.5 1.6-16c0-44.2-35.8-80-80-80c-24.3 0-46.1 10.9-60.8 28C256.5 24.3 219.1 0 176 0C114.1 0 64 50.1 64 112c0 7.1 .7 14.1 1.9 20.8C27.6 145.4 0 181.5 0 224zm330.1 3.6c-5.8-4.7-14.2-4.7-20.1-.1l-160 128c-5.3 4.2-7.4 11.4-5.1 17.8s8.3 10.7 15.1 10.7h70.1L177.7 488.8c-3.4 6.7-1.6 14.9 4.3 19.6s14.2 4.7 20.1 .1l160-128c5.3-4.2 7.4-11.4 5.1-17.8s-8.3-10.7-15.1-10.7H281.9l52.4-104.8c3.4-6.7 1.6-14.9-4.2-19.6z"/></svg></td>';
            else if($i == 4)
              $replace['#vigilance#'] .= '<td class="tableCmdcss" style="width: 10%;height:20px;text-align: center" title="' .$vig['txt'] .$desc .'"><svg xmlns="http://www.w3.org/2000/svg" width="25" height="25" viewBox="0 0 576 512"><path fill="' .substr($color[$col],8).'" d="M306.8 6.1C295.6-2 280.4-2 269.2 6.1l-176 128c-11.2 8.2-15.9 22.6-11.6 35.8S98.1 192 112 192h16v73c1.7 1 3.3 2 4.9 3.1c18 12.4 40.1 20.3 59.2 20.3c21.1 0 42-8.5 59.2-20.3c22.1-15.5 51.6-15.5 73.7 0c18.4 12.7 39.6 20.3 59.2 20.3c19 0 41.2-7.9 59.2-20.3c1.5-1 3-2 4.5-2.9l-.3-73.2H464c13.9 0 26.1-8.9 30.4-22.1s-.4-27.6-11.6-35.8l-176-128zM269.5 309.9C247 325.4 219.5 336 192 336c-26.9 0-55.3-10.8-77.4-26.1l0 0c-11.9-8.5-28.1-7.8-39.2 1.7c-14.4 11.9-32.5 21-50.6 25.2c-17.2 4-27.9 21.2-23.9 38.4s21.2 27.9 38.4 23.9c24.5-5.7 44.9-16.5 58.2-25C126.5 389.7 159 400 192 400c31.9 0 60.6-9.9 80.4-18.9c5.8-2.7 11.1-5.3 15.6-7.7c4.5 2.4 9.7 5.1 15.6 7.7c19.8 9 48.5 18.9 80.4 18.9c33 0 65.5-10.3 94.5-25.8c13.4 8.4 33.7 19.3 58.2 25c17.2 4 34.4-6.7 38.4-23.9s-6.7-34.4-23.9-38.4c-18.1-4.2-36.2-13.3-50.6-25.2c-11.1-9.5-27.3-10.1-39.2-1.7l0 0C439.4 325.2 410.9 336 384 336c-27.5 0-55-10.6-77.5-26.1c-11.1-7.9-25.9-7.9-37 0zM384 448c-27.5 0-55-10.6-77.5-26.1c-11.1-7.9-25.9-7.9-37 0C247 437.4 219.5 448 192 448c-26.9 0-55.3-10.8-77.4-26.1l0 0c-11.9-8.5-28.1-7.8-39.2 1.7c-14.4 11.9-32.5 21-50.6 25.2c-17.2 4-27.9 21.2-23.9 38.4s21.2 27.9 38.4 23.9c24.5-5.7 44.9-16.5 58.2-25C126.5 501.7 159 512 192 512c31.9 0 60.6-9.9 80.4-18.9c5.8-2.7 11.1-5.3 15.6-7.7c4.5 2.4 9.7 5.1 15.6 7.7c19.8 9 48.5 18.9 80.4 18.9c33 0 65.5-10.3 94.5-25.8c13.4 8.4 33.7 19.3 58.2 25c17.2 4 34.4-6.7 38.4-23.9s-6.7-34.4-23.9-38.4c-18.1-4.2-36.2-13.3-50.6-25.2c-11.1-9.4-27.3-10.1-39.2-1.7l0 0C439.4 437.2 410.9 448 384 448z"/></svg></td>';
            else if($i == 5)
              $replace['#vigilance#'] .= '<td class="tableCmdcss" style="width: 10%;height:20px;text-align: center" title="' .$vig['txt'] .$desc .'"><svg xmlns="http://www.w3.org/2000/svg" width="25" height="25" viewBox="0 0 448 512"><path fill="' .substr($color[$col],8).'" d="M224 0c17.7 0 32 14.3 32 32V62.1l15-15c9.4-9.4 24.6-9.4 33.9 0s9.4 24.6 0 33.9l-49 49v70.3l61.4-35.8 17.7-66.1c3.4-12.8 16.6-20.4 29.4-17s20.4 16.6 17 29.4l-5.2 19.3 23.6-13.8c15.3-8.9 34.9-3.7 43.8 11.5s3.8 34.9-11.5 43.8l-25.3 14.8 21.7 5.8c12.8 3.4 20.4 16.6 17 29.4s-16.6 20.4-29.4 17l-67.7-18.1L287.5 256l60.9 35.5 67.7-18.1c12.8-3.4 26 4.2 29.4 17s-4.2 26-17 29.4l-21.7 5.8 25.3 14.8c15.3 8.9 20.4 28.5 11.5 43.8s-28.5 20.4-43.8 11.5l-23.6-13.8 5.2 19.3c3.4 12.8-4.2 26-17 29.4s-26-4.2-29.4-17l-17.7-66.1L256 311.7v70.3l49 49c9.4 9.4 9.4 24.6 0 33.9s-24.6 9.4-33.9 0l-15-15V480c0 17.7-14.3 32-32 32s-32-14.3-32-32V449.9l-15 15c-9.4 9.4-24.6 9.4-33.9 0s-9.4-24.6 0-33.9l49-49V311.7l-61.4 35.8-17.7 66.1c-3.4 12.8-16.6 20.4-29.4 17s-20.4-16.6-17-29.4l5.2-19.3L48.1 395.6c-15.3 8.9-34.9 3.7-43.8-11.5s-3.7-34.9 11.5-43.8l25.3-14.8-21.7-5.8c-12.8-3.4-20.4-16.6-17-29.4s16.6-20.4 29.4-17l67.7 18.1L160.5 256 99.6 220.5 31.9 238.6c-12.8 3.4-26-4.2-29.4-17s4.2-26 17-29.4l21.7-5.8L15.9 171.6C.6 162.7-4.5 143.1 4.4 127.9s28.5-20.4 43.8-11.5l23.6 13.8-5.2-19.3c-3.4-12.8 4.2-26 17-29.4s26 4.2 29.4 17l17.7 66.1L192 200.3V129.9L143 81c-9.4-9.4-9.4-24.6 0-33.9s24.6-9.4 33.9 0l15 15V32c0-17.7 14.3-32 32-32z"/></svg></td>';
            else if($i == 6)
              $replace['#vigilance#'] .= '<td class="tableCmdcss" style="width: 10%;height:20px;text-align: center" title="' .$vig['txt'] .$desc .'"><svg xmlns="http://www.w3.org/2000/svg" width="25" height="25" viewBox="0 0 576 512"><path fill="' .substr($color[$col],8).'" d="M128 112c0-26.5 21.5-48 48-48s48 21.5 48 48V276.5c0 17.3 7.1 31.9 15.3 42.5C249.8 332.6 256 349.5 256 368c0 44.2-35.8 80-80 80s-80-35.8-80-80c0-18.5 6.2-35.4 16.7-48.9c8.2-10.6 15.3-25.2 15.3-42.5V112zM176 0C114.1 0 64 50.1 64 112V276.4c0 .1-.1 .3-.2 .6c-.2 .6-.8 1.6-1.7 2.8C43.2 304.2 32 334.8 32 368c0 79.5 64.5 144 144 144s144-64.5 144-144c0-33.2-11.2-63.8-30.1-88.1c-.9-1.2-1.5-2.2-1.7-2.8c-.1-.3-.2-.5-.2-.6V112C288 50.1 237.9 0 176 0zm0 416c26.5 0 48-21.5 48-48c0-20.9-13.4-38.7-32-45.3V112c0-8.8-7.2-16-16-16s-16 7.2-16 16V322.7c-18.6 6.6-32 24.4-32 45.3c0 26.5 21.5 48 48 48zM480 160h32c12.9 0 24.6-7.8 29.6-19.8s2.2-25.7-6.9-34.9l-64-64c-12.5-12.5-32.8-12.5-45.3 0l-64 64c-9.2 9.2-11.9 22.9-6.9 34.9s16.6 19.8 29.6 19.8h32V448c0 17.7 14.3 32 32 32s32-14.3 32-32V160z"/></svg></td>';
            else if($i == 7)
              $replace['#vigilance#'] .= '<td class="tableCmdcss" style="width: 10%;height:20px;text-align: center" title="' .$vig['txt'] .$desc .'"><svg xmlns="http://www.w3.org/2000/svg" width="25" height="25" viewBox="0 0 576 512"><path fill="' .substr($color[$col],8).'" d="M128 112c0-26.5 21.5-48 48-48s48 21.5 48 48V276.5c0 17.3 7.1 31.9 15.3 42.5C249.8 332.6 256 349.5 256 368c0 44.2-35.8 80-80 80s-80-35.8-80-80c0-18.5 6.2-35.4 16.7-48.9c8.2-10.6 15.3-25.2 15.3-42.5V112zM176 0C114.1 0 64 50.1 64 112V276.4c0 .1-.1 .3-.2 .6c-.2 .6-.8 1.6-1.7 2.8C43.2 304.2 32 334.8 32 368c0 79.5 64.5 144 144 144s144-64.5 144-144c0-33.2-11.2-63.8-30.1-88.1c-.9-1.2-1.5-2.2-1.7-2.8c-.1-.3-.2-.5-.2-.6V112C288 50.1 237.9 0 176 0zm0 416c26.5 0 48-21.5 48-48c0-20.9-13.4-38.7-32-45.3V272c0-8.8-7.2-16-16-16s-16 7.2-16 16v50.7c-18.6 6.6-32 24.4-32 45.3c0 26.5 21.5 48 48 48zm336-64H480V64c0-17.7-14.3-32-32-32s-32 14.3-32 32V352H384c-12.9 0-24.6 7.8-29.6 19.8s-2.2 25.7 6.9 34.9l64 64c6 6 14.1 9.4 22.6 9.4s16.6-3.4 22.6-9.4l64-64c9.2-9.2 11.9-22.9 6.9-34.9s-16.6-19.8-29.6-19.8z"/></svg></td>';
            else if($i == 8)
              $replace['#vigilance#'] .= '<td class="tableCmdcss" style="width: 10%;height:20px;text-align: center" title="' .$vig['txt'] .$desc .'"><svg xmlns="http://www.w3.org/2000/svg" width="25" height="25" viewBox="0 0 576 512"><path fill="' .substr($color[$col],8).'" d="M439.7 401.9c34.2 23.1 81.1 19.5 111.4-10.8c34.4-34.4 34.4-90.1 0-124.4c-27.8-27.8-69.5-33.1-102.6-16c-11.8 6.1-16.4 20.6-10.3 32.3s20.6 16.4 32.3 10.3c15.1-7.8 34-5.3 46.6 7.3c15.6 15.6 15.6 40.9 0 56.6s-40.9 15.6-56.6 0l-81.7-81.7C401.2 261.3 416 236.4 416 208c0-33.9-21.1-62.9-50.9-74.5c1.9-6.8 2.9-14 2.9-21.5c0-44.2-35.8-80-80-80c-27.3 0-51.5 13.7-65.9 34.6C216.3 46.6 197.9 32 176 32c-26.5 0-48 21.5-48 48c0 4 .5 7.9 1.4 11.6L439.7 401.9zM480 64a32 32 0 1 0 -64 0 32 32 0 1 0 64 0zm0 128a32 32 0 1 0 0-64 32 32 0 1 0 0 64zM68.3 87C43.1 61.8 0 79.7 0 115.3V432c0 44.2 35.8 80 80 80H396.7c35.6 0 53.5-43.1 28.3-68.3L68.3 87z"/></svg></td>';
            else if($i == 9)
              $replace['#vigilance#'] .= '<td class="tableCmdcss" style="width: 10%;height:20px;text-align: center" title="' .$vig['txt'] .$desc .'"><svg xmlns="http://www.w3.org/2000/svg" width="25" height="25" viewBox="0 0 576 512"><path fill="' .substr($color[$col],8).'" d="M80.8 136.5C104.9 93.8 152.6 64 209 64c16.9 0 33.1 2.7 48.2 7.7c16.8 5.5 34.9-3.6 40.4-20.4s-3.6-34.9-20.4-40.4C255.8 3.8 232.8 0 209 0C95.2 0 0 88 0 200c0 91.6 53.5 172.1 142.2 194.1c13.4 3.8 27.5 5.9 42.2 5.9c.7 0 1.4 0 2.1-.1c1.8 0 3.7 .1 5.5 .1l0 0c31.9 0 60.6-9.9 80.4-18.9c5.8-2.7 11.1-5.3 15.6-7.7c4.5 2.4 9.7 5.1 15.6 7.7c19.8 9 48.5 18.9 80.4 18.9c33 0 65.5-10.3 94.5-25.8c13.4 8.4 33.7 19.3 58.2 25c17.2 4 34.4-6.7 38.4-23.9s-6.7-34.4-23.9-38.4c-18.1-4.2-36.2-13.3-50.6-25.2c-11.1-9.5-27.3-10.1-39.2-1.7l0 0C439.4 325.2 410.9 336 384 336c-27.5 0-55-10.6-77.5-26.1c-11.1-7.9-25.9-7.9-37 0c-22.4 15.5-49.9 26.1-77.4 26.1c0 0-.1 0-.1 0c-12.4 0-24-1.5-34.9-4.3C121.6 320.2 96 287 96 248c0-48.5 39.5-88 88.4-88c13.5 0 26.1 3 37.5 8.3c16 7.5 35.1 .6 42.5-15.5s.6-35.1-15.5-42.5C229.3 101.1 207.4 96 184.4 96c-40 0-76.4 15.4-103.6 40.5zm252-18.1c-8.1 6-12.8 15.5-12.8 25.6V265c1.6 1 3.3 2 4.8 3.1c18.4 12.7 39.6 20.3 59.2 20.3c19 0 41.2-7.9 59.2-20.3c23.8-16.7 55.8-15.3 78.1 3.4c10.6 8.8 24.2 15.6 37.3 18.6c5.8 1.4 11.2 3.4 16.2 6.2c.7-2.7 1.1-5.5 1.1-8.4l-.4-144c0-10-4.7-19.4-12.7-25.5l-95.5-72c-11.4-8.6-27.1-8.6-38.5 0l-96 72zM384 448c-27.5 0-55-10.6-77.5-26.1c-11.1-7.9-25.9-7.9-37 0C247 437.4 219.5 448 192 448c-26.9 0-55.3-10.8-77.4-26.1l0 0c-11.9-8.5-28.1-7.8-39.2 1.7c-14.4 11.9-32.5 21-50.6 25.2c-17.2 4-27.9 21.2-23.9 38.4s21.2 27.9 38.4 23.9c24.5-5.7 44.9-16.5 58.2-25C126.5 501.7 159 512 192 512c31.9 0 60.6-9.9 80.4-18.9c5.8-2.7 11.1-5.3 15.6-7.7c4.5 2.4 9.7 5.1 15.6 7.7c19.8 9 48.5 18.9 80.4 18.9c33 0 65.5-10.3 94.5-25.8c13.4 8.4 33.7 19.3 58.2 25c17.2 4 34.4-6.7 38.4-23.9s-6.7-34.4-23.9-38.4c-18.1-4.2-36.2-13.3-50.6-25.2c-11.1-9.4-27.3-10.1-39.2-1.7l0 0C439.4 437.2 410.9 448 384 448z"/></svg></td>';
            else if($i == 10)
              $replace['#vigilance#'] .= '<td class="tableCmdcss" style="width: 10%;height:20px;text-align: center" title="' .$vig['txt'] .$desc .'"><svg xmlns="http://www.w3.org/2000/svg" width="25" height="25" viewBox="0 0 448 512"><path fill="' .substr($color[$col],8).'" d="M159.3 5.4c7.8-7.3 19.9-7.2 27.7 .1c27.6 25.9 53.5 53.8 77.7 84c11-14.4 23.5-30.1 37-42.9c7.9-7.4 20.1-7.4 28 .1c34.6 33 63.9 76.6 84.5 118c20.3 40.8 33.8 82.5 33.8 111.9C448 404.2 348.2 512 224 512C98.4 512 0 404.1 0 276.5c0-38.4 17.8-85.3 45.4-131.7C73.3 97.7 112.7 48.6 159.3 5.4zM225.7 416c25.3 0 47.7-7 68.8-21c42.1-29.4 53.4-88.2 28.1-134.4c-4.5-9-16-9.6-22.5-2l-25.2 29.3c-6.6 7.6-18.5 7.4-24.7-.5c-16.5-21-46-58.5-62.8-79.8c-6.3-8-18.3-8.1-24.7-.1c-33.8 42.5-50.8 69.3-50.8 99.4C112 375.4 162.6 416 225.7 416z"/></svg></td>';
            else
              $replace['#vigilance#'] .= '<td class="tableCmdcss" style="width: 10%;height:20px;text-align: center" title="' .$vig['txt'] .$desc .'"><i class="wi ' .$vig['icon'] .'" style="font-size: 24px;' .$color[$col] .'"></i></td>';
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
/*
      $eqLogic = $this->getEqLogic();
      foreach ($eqLogic->getCmd('info') as $cmd) {
        $val = $cmd->execCmd(null);
        $cmdLogicalId = $cmd->getLogicalId();
        if($cmd->getSubtype() == 'numeric')
          $eqLogic->checkAndUpdateCmd($cmdLogicalId,-666);
        else if($cmd->getSubtype() == 'string')
          $eqLogic->checkAndUpdateCmd($cmdLogicalId,"Obsolete command");
      }
*/
      $this->getEqLogic()->getInformations();
    }
  }
}
