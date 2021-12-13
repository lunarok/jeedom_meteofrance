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

  public static function cron5() {
    foreach (eqLogic::byType(__CLASS__, true) as $meteofrance) {
      $meteofrance->getRain();
      $meteofrance->refreshWidget();
    }
  }

  public static function cron15() {
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

  public function preSave() {
    $args = $this->getInsee();
    $this->getDetails($args);
    $this->getBulletinDetails($args);
  }

  public function postSave() {
    $cron = cron::byClassAndFunction('meteofrance', 'cronTrigger', array('meteofrance_id' => $this->getId()));
    if (!is_object($cron)) {
      if ($updateOnly == 1) {
        return;
      }
      $cron = new cron();
      $cron->setClass('meteofrance');
      $cron->setFunction('cronTrigger');
      $cron->setOption(array('meteofrance_id' => $this->getId()));
    }
    $time = time() + 90;
    $cron->setSchedule(date('i', $time) . ' ' . date('H', $time) . ' ' . date('d', $time) . ' ' . date('m', $time) . ' * ' . date('Y', $time));
    $cron->save();
  }

  public static function cronTrigger($_options) {
    $meteofrance = meteofrance::byId($_options['meteofrance_id']);
    $meteofrance->loadCmdFromConf('bulletin');
    $meteofrance->loadCmdFromConf('bulletinville');
    $meteofrance->loadCmdFromConf('ephemeris');
    $meteofrance->loadCmdFromConf('marine');
    $meteofrance->loadCmdFromConf('meteo');
    $meteofrance->loadCmdFromConf('rain');
    $meteofrance->loadCmdFromConf('vigilance');
    event::add('meteofrance::includeDevice',
          array(
              'state' => 1
          )
      );
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
    $this->refreshWidget();
  }

  public function getInsee() {
    $array = array();
    $geoloc = $this->getConfiguration('geoloc', 'none');
    if ($geoloc == 'none') {
      log::add(__CLASS__, 'error', 'Eqlogic geoloc non configuré.');
      return;
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
          return;
        }
      }
      else {
        log::add(__CLASS__, 'error', 'Eqlogic geotrav object not found');
        return;
      }
    }
    $url = 'https://api-adresse.data.gouv.fr/search/?q=' . str_replace(' ', '-', $array['ville']) . '&postcode=' . $array['zip'] . '&limit=1';
    $return = self::callURL($url);
    log::add(__CLASS__, 'debug', 'Insee ' . print_r($return['features'][0]['properties'],true));
    $array['insee'] = $return['features'][0]['properties']['citycode'];
    $array['ville'] = self::lowerAccent($array['ville']);
    $array['lon'] = $return['features'][0]['geometry']['coordinates'][0];
    $array['lat'] = $return['features'][0]['geometry']['coordinates'][1];
    return $array;
  }

  public function getDetails($_array = array()) {
    $url = 'https://rpcache-aa.meteofrance.com/internet2018client/2.0/forecast?lat=' . $_array['lat'] . '&lon=' . $_array['lon'] . '&id=&instants=morning,afternoon,evening,night';
    $return = self::callMeteoWS($url);
    $this->setConfiguration('bulletinCote', $return['properties']['bulletin_cote']);
    $this->setConfiguration('couvertPluie', $return['properties']['rain_product_available']);
    $this->setConfiguration('lat', $_array['lat']);
    $this->setConfiguration('lon', $_array['lon']);
    $this->setConfiguration('numDept', $return['properties']['french_department']);
    $this->setConfiguration('insee', $_array['insee']);
    $this->setConfiguration('zip', $_array['zip']);
    $this->setConfiguration('ville', $_array['ville']);
  }

  public function getBulletinDetails($_array = array()) {
    $url = "http://meteofrance.com/previsions-meteo-france/" . $_array['ville'] . "/" . $_array['zip'];
    $dom = new DOMDocument;
    $dom->loadHTMLFile($url);
    $xpath = new DomXPath($dom);
    log::add(__CLASS__, 'debug', 'Bulletin Ville URL ' . $url);
    log::add(__CLASS__, 'debug', 'Bulletin Ville ' . $xpath->query("//html/body/script[1]")[0]->nodeValue);
    $json = json_decode($xpath->query("//html/body/script[1]")[0]->nodeValue, true);
    //log::add(__CLASS__, 'debug', 'Bulletin Ville Result ' . $json['id_bulletin_ville']);
    $this->setConfiguration('bulletinVille', $json['id_bulletin_ville']);
  }

  public function getBulletinVille() {
    if ($this->getConfiguration('bulletinVille','') == '') {
      return;
    }
    $url = 'https://rpcache-aa.meteofrance.com/wsft/files/agat/ville/bulvillefr_' . $this->getConfiguration('bulletinVille') . '.xml';
    $return = self::callMeteoWS($url, true, false);
    $this->checkAndUpdateCmd('BulletinvilletitreEcheance1', $return['echeance'][0]['titreEcheance']);
    $this->checkAndUpdateCmd('Bulletinvillepression1', $return['echeance'][0]['pression']);
    $this->checkAndUpdateCmd('BulletinvilleTS1', $return['echeance'][0]['TS']);
    $this->checkAndUpdateCmd('Bulletinvilletemperature1', $return['echeance'][0]['temperature']);
    $this->checkAndUpdateCmd('Bulletinvillevent1', $return['echeance'][0]['vent']);
    $this->checkAndUpdateCmd('BulletinvilletitreEcheance2', $return['echeance'][1]['titreEcheance']);
    $this->checkAndUpdateCmd('Bulletinvillepression2', $return['echeance'][1]['pression']);
    $this->checkAndUpdateCmd('BulletinvilleTS2', $return['echeance'][1]['TS']);
    $this->checkAndUpdateCmd('Bulletinvilletemperature2', $return['echeance'][1]['temperature']);
    $this->checkAndUpdateCmd('Bulletinvillevent2', $return['echeance'][1]['vent']);
    $this->checkAndUpdateCmd('BulletinvilletitreEcheance3', $return['echeance'][2]['titreEcheance']);
    $this->checkAndUpdateCmd('Bulletinvillepression3', $return['echeance'][2]['pression']);
    $this->checkAndUpdateCmd('BulletinvilleTS3', $return['echeance'][2]['TS']);
    $this->checkAndUpdateCmd('Bulletinvilletemperature3', $return['echeance'][2]['temperature']);
    $this->checkAndUpdateCmd('Bulletinvillevent3', $return['echeance'][2]['vent']);
  }

  public function getDailyExtras() {
    $url = 'https://rpcache-aa.meteofrance.com/internet2018client/2.0/forecast?lat=' . $this->getConfiguration('lat') . '&lon=' . $this->getConfiguration('lon') . '&id=&instants=&day=2';
    $return = self::callMeteoWS($url);
    $this->checkAndUpdateCmd('Meteoday0PluieCumul', $return['properties']['daily_forecast'][0]['total_precipitation_24h']);
    $this->checkAndUpdateCmd('MeteoprobaStorm', $return['properties']['probability_forecast'][0]['storm_hazard']);
    $this->checkAndUpdateCmd('MeteonowCloud', $return['properties']['forecast'][0]['total_cloud_cover']);
    $this->checkAndUpdateCmd('MeteonowPression', $return['properties']['forecast'][0]['P_sea']);
    $this->checkAndUpdateCmd('MeteonowTemperature', $return['properties']['forecast'][0]['T']);
    $this->checkAndUpdateCmd('MeteonowHumidity', $return['properties']['forecast'][0]['relative_humidity']);
    $this->checkAndUpdateCmd('Meteoday0icon', $return['properties']['forecast'][0]['weather_icon']);
    $this->checkAndUpdateCmd('hourly1icon', $return['properties']['forecast'][1]['weather_icon']);
    $this->checkAndUpdateCmd('Meteodayh1description', $return['properties']['forecast'][1]['weather_description']);
    $this->checkAndUpdateCmd('Meteodayh1temperature', $return['properties']['forecast'][1]['T']);
    $this->checkAndUpdateCmd('Meteodayh1temperatureRes', $return['properties']['forecast'][1]['T_windchill']);
    $this->checkAndUpdateCmd('MeteonowTemperatureRes', $return['properties']['forecast'][0]['T_windchill']);
    $this->checkAndUpdateCmd('Meteoday0directionVent', $return['properties']['forecast'][0]['wind_direction']);
    $this->checkAndUpdateCmd('Meteoday0vitesseVent', $return['properties']['forecast'][0]['wind_speed']);
    $this->checkAndUpdateCmd('Meteoday0forceRafales', $return['properties']['forecast'][0]['wind_speed_gust']);
    $this->checkAndUpdateCmd('Meteoday1directionVent', $return['properties']['forecast'][10]['wind_direction']);
    $this->checkAndUpdateCmd('Meteoday1vitesseVent', $return['properties']['forecast'][10]['wind_speed']);
    $this->checkAndUpdateCmd('Meteoday1forceRafales', $return['properties']['forecast'][10]['wind_speed_gust']);
    $this->checkAndUpdateCmd('Meteoday2directionVent', $return['properties']['forecast'][20]['wind_direction']);
    $this->checkAndUpdateCmd('Meteoday2vitesseVent', $return['properties']['forecast'][20]['wind_speed']);
    $this->checkAndUpdateCmd('Meteoday2forceRafales', $return['properties']['forecast'][20]['wind_speed_gust']);
    $url = 'https://rpcache-aa.meteofrance.com/internet2018client/2.0/forecast?lat=' . $this->getConfiguration('lat') . '&lon=' . $this->getConfiguration('lon') . '&id=&instants=morning,afternoon,evening,night';
    $return = self::callMeteoWS($url);
    $this->checkAndUpdateCmd('Meteoday0description', $return['properties']['daily_forecast'][0]['daily_weather_description']);
    $this->checkAndUpdateCmd('Meteoday0temperatureMin', $return['properties']['daily_forecast'][0]['T_min']);
    $this->checkAndUpdateCmd('Meteoday0temperatureMax', $return['properties']['daily_forecast'][0]['T_max']);
    $this->checkAndUpdateCmd('Meteoday0indiceUV', $return['properties']['daily_forecast'][0]['uv_index']);
    $this->checkAndUpdateCmd('Meteoday1temperatureMin', $return['properties']['daily_forecast'][1]['T_min']);
    $this->checkAndUpdateCmd('Meteoday1temperatureMax', $return['properties']['daily_forecast'][1]['T_max']);
    $this->checkAndUpdateCmd('Meteoday1indiceUV', $return['properties']['daily_forecast'][1]['uv_index']);
    $this->checkAndUpdateCmd('Meteoday2temperatureMin', $return['properties']['daily_forecast'][2]['T_min']);
    $this->checkAndUpdateCmd('Meteoday2temperatureMax', $return['properties']['daily_forecast'][2]['T_max']);
    $this->checkAndUpdateCmd('Meteoday2indiceUV', $return['properties']['daily_forecast'][2]['uv_index']);
    $this->checkAndUpdateCmd('Meteoday3temperatureMin', $return['properties']['daily_forecast'][3]['T_min']);
    $this->checkAndUpdateCmd('Meteoday3temperatureMax', $return['properties']['daily_forecast'][3]['T_max']);
    $this->checkAndUpdateCmd('Meteoday3indiceUV', $return['properties']['daily_forecast'][3]['uv_index']);
    $this->checkAndUpdateCmd('Meteoday0temperatureMin', $return['properties']['daily_forecast'][0]['T_min']);
    $this->checkAndUpdateCmd('Meteoday0temperatureMax', $return['properties']['daily_forecast'][0]['T_max']);
    $this->checkAndUpdateCmd('Meteoday0indiceUV', $return['properties']['daily_forecast'][0]['uv_index']);
    $this->checkAndUpdateCmd('Meteoday1icon', $return['properties']['daily_forecast'][1]['daily_weather_icon']);
    $this->checkAndUpdateCmd('Meteoday2icon', $return['properties']['daily_forecast'][2]['daily_weather_icon']);
    $this->checkAndUpdateCmd('Meteoday3icon', $return['properties']['daily_forecast'][3]['daily_weather_icon']);
    $this->checkAndUpdateCmd('Meteoday1description', $return['properties']['daily_forecast'][1]['daily_weather_description']);
    $this->checkAndUpdateCmd('Meteoday2description', $return['properties']['daily_forecast'][2]['daily_weather_description']);
    $this->checkAndUpdateCmd('Meteoday3description', $return['properties']['daily_forecast'][3]['daily_weather_description']);
    $this->checkAndUpdateCmd('MeteoprobaPluie', $return['properties']['probability_forecast'][0]['rain_hazard_3h']);
    $this->checkAndUpdateCmd('MeteoprobaNeige', $return['properties']['probability_forecast'][0]['snow_hazard_3h']);
    $this->checkAndUpdateCmd('MeteoprobaGel', $return['properties']['probability_forecast'][0]['freezing_hazard']);
    $this->checkAndUpdateCmd('MeteoprobaStorm', $return['properties']['probability_forecast'][0]['storm_hazard']);
  }

  public function getDetailsValues() {
    $url = 'https://rpcache-aa.meteofrance.com/internet2018client/2.0/forecast?lat=' . $this->getConfiguration('lat') . '&lon=' . $this->getConfiguration('lon') . '&id=&instants=morning,afternoon,evening,night';
    $return = self::callMeteoWS($url);
    $step = 'soirée';
    switch ($return['properties']['forecast'][0]['moment_day']) {
      case 'nuit':
        $step = 'nuit';
        break;

      case 'matin':
        $step = 'matin';
        break;

      case 'après-midi':
        $step = 'après-midi';
        break;
    }
    $i = 0;
    log::add(__CLASS__, 'debug', 'Moment journée : ' . $step);

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

    log::add(__CLASS__, 'debug', 'Meteosoir1description : ' . $i . $return['properties']['forecast'][$i]['weather_description']);
    $this->checkAndUpdateCmd('Meteosoir1description', $return['properties']['forecast'][$i]['weather_description']);
    $this->checkAndUpdateCmd('Meteosoir1directionVent', $return['properties']['forecast'][$i]['wind_direction']);
    $this->checkAndUpdateCmd('Meteosoir1vitesseVent', $return['properties']['forecast'][$i]['wind_speed']);
    $this->checkAndUpdateCmd('Meteosoir1forceRafales', $return['properties']['forecast'][$i]['wind_speed_gust']);
    $this->checkAndUpdateCmd('Meteosoir1temperatureMin', $return['properties']['forecast'][$i]['T']);
    $this->checkAndUpdateCmd('Meteosoir1temperatureMax', $return['properties']['forecast'][$i]['T_windchill']);
  }

  public function getRain() {
    if (!$this->getConfiguration('couvertPluie')) {
      return;
    }
    $url = 'https://rpcache-aa.meteofrance.com/internet2018client/2.0/nowcast/rain?lat=' . $this->getConfiguration('lat') . '&lon=' . $this->getConfiguration('lon');
    $return = self::callMeteoWS($url);
    $i = 0;
    $cumul = 0;
    $next = 0;
    $type = '';
    foreach ($return['properties']['forecast'] as $id => $rain) {
      $i++;
      $this->checkAndUpdateCmd('Rainrain' . $i, $rain['rain_intensity']);
      $this->checkAndUpdateCmd('Raindesc' . $i, $rain['rain_intensity_description']);
      if (($rain['rain_intensity'] > 1) && ($next == 0)) {
        $next = $i * 5;
        if ($i > 6) {
          $next += ($i - 6) * 5;
          //after 30 mn, steps are for 10mn
        }
        $type = $rain['rain_intensity_description'];
      }
      $cumul += $rain['rain_intensity'];
    }
    $this->checkAndUpdateCmd('Raincumul', $cumul);
    $this->checkAndUpdateCmd('Rainnext', $next);
    $this->checkAndUpdateCmd('Raintype', $type);
    $this->checkAndUpdateCmd('Rainheure',  date('Hi',strtotime($return['properties']['forecast'][0]['time'])));
  }

  public function getMarine() {
    if (!$this->getConfiguration('bulletinCote')) {
      return;
    }
    $url = 'https://rpcache-aa.meteofrance.com/internet2018client/2.0/forecast/marine?lat=' . $this->getConfiguration('lat') . '&lon=' . $this->getConfiguration('lon');
    $return = self::callMeteoWS($url);
    foreach ($return['properties']['marine'] as $id => $marine) {
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

  public function getTide() {
    if (!$this->getConfiguration('bulletinCote')) {
      return;
    }
    $url = 'https://rpcache-aa.meteofrance.com/internet2018client/2.0/tide?id=' . $this->getConfiguration('insee') . '52';
    $return = self::callMeteoWS($url);
    $this->checkAndUpdateCmd('Tidehigh_tide0time', date('Hi',strtotime($return['properties']['tide']['high_tide'][0]['time'])));
    $this->checkAndUpdateCmd('Tidehigh_tide0tidal_coefficient', $return['properties']['tide']['high_tide'][0]['tidal_coefficient']);
    $this->checkAndUpdateCmd('Tidehigh_tide0tidal_height', $return['properties']['tide']['high_tide'][0]['tidal_height']);
    $this->checkAndUpdateCmd('Tidehigh_tide1time', date('Hi',strtotime($return['properties']['tide']['high_tide'][1]['time'])));
    $this->checkAndUpdateCmd('Tidehigh_tide1tidal_coefficient', $return['properties']['tide']['high_tide'][1]['tidal_coefficient']);
    $this->checkAndUpdateCmd('Tidehigh_tide1tidal_height', $return['properties']['tide']['high_tide'][1]['tidal_height']);
    $this->checkAndUpdateCmd('Tidelow_tide0time', date('Hi',strtotime($return['properties']['tide']['low_tide'][0]['time'])));
    $this->checkAndUpdateCmd('Tidelow_tide0tidal_coefficient', $return['properties']['tide']['low_tide'][0]['tidal_coefficient']);
    $this->checkAndUpdateCmd('Tidelow_tide0tidal_height', $return['properties']['tide']['low_tide'][0]['tidal_height']);
    $this->checkAndUpdateCmd('Tidelow_tide1time', date('Hi',strtotime($return['properties']['tide']['low_tide'][1]['time'])));
    $this->checkAndUpdateCmd('Tidelow_tide1tidal_coefficient', $return['properties']['tide']['low_tide'][1]['tidal_coefficient']);
    $this->checkAndUpdateCmd('Tidelow_tide1tidal_height', $return['properties']['tide']['low_tide'][1]['tidal_height']);
  }

  public function getVigilance() {
    $value[1] = "Vert";
    $value[2] = "Jaune";
    $value[3] = "Orange";
    $value[4] = "Rouge";
    $type[1] = "Vent violent";
    $type[2] = "Pluie-inondation";
    $type[3] = "Orages";
    $type[4] = "Inondation";
    $type[5] = "Neige-verglas";
    $type[6] = "Canicule";
    $type[7] = "Grand-froid";
    $type[8] = "Avalanches";
    $type[9] = "Vagues-submersion";
    $url = 'https://rpcache-aa.meteofrance.com/internet2018client/2.0/warning/full?domain=' . $this->getConfiguration('numDept');
    $return = self::callMeteoWS($url);
    $this->checkAndUpdateCmd('Vigilancecolor_max', $return['color_max']);
    foreach ($return['timelaps'] as $id => $vigilance) {
      $phase = array();
      foreach ($vigilance['timelaps_items'] as $id2 => $segment) {
        $phase[] = date('H:i', $segment['begin_time']) . ' vigilance niveau ' . $value[$segment['color_id']];
      }
      $this->checkAndUpdateCmd('Vigilancephases' . $vigilance['phenomenon_id'], implode(', ',$phase));
    }
    $listVigilance = array();
    foreach ($return['phenomenons_items'] as $id => $vigilance) {
      $this->checkAndUpdateCmd('Vigilancephenomenon_max_color_id' . $vigilance['phenomenon_id'], $vigilance['phenomenon_max_color_id']);
      if ($vigilance['phenomenon_max_color_id'] > 1) {
        $cmd = meteofranceCmd::byEqLogicIdAndLogicalId($this->getId(),'Vigilancephases' . $vigilance['phenomenon_id']);
        $listVigilance[] = $type[$vigilance['phenomenon_id']] . ' : ' . $value[$vigilance['phenomenon_max_color_id']] . ', ' . $cmd->execCmd();
      }
    }
    $this->checkAndUpdateCmd('Vigilancelist', implode(', ',$listVigilance));
  }

  public function getAlerts() {
    $url = 'https://rpcache-aa.meteofrance.com/internet2018client/2.0/report?domain=france&report_type=message&report_subtype=infospe&format=';
    $return = self::callMeteoWS($url);
    if (isset($return['Com'][0]['titre'])) {
      $this->checkAndUpdateCmd('Alerttitre', $return['Com'][0]['titre']);
      $this->checkAndUpdateCmd('Alerttexte', $return['Com'][0]['texte']);
      $this->checkAndUpdateCmd('AlertdateDeFin', $return['Com'][0]['dateDeFin']);
      $this->checkAndUpdateCmd('AlertdateProduction', $return['Com'][0]['dateProduction']);
    }
  }

  public function getEphemeris() {
    date_default_timezone_set(config::byKey('timezone'));
    $url = 'https://rpcache-aa.meteofrance.com/internet2018client/2.0/ephemeris?lat=' . $this->getConfiguration('lat') . '&lon=' . $this->getConfiguration('lon');
    $return = self::callMeteoWS($url);
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
    $url = 'https://rpcache-aa.meteofrance.com/internet2018client/2.0/report?domain=france&report_type=forecast&report_subtype=BGP';
    $return = self::callMeteoWS($url, true);
    $this->checkAndUpdateCmd('Bulletinfrdate0', $return['groupe'][0]['date']);
    $this->checkAndUpdateCmd('Bulletinfrtitre0', $return['groupe'][0]['titre']);
    $this->checkAndUpdateCmd('Bulletinfrtemps0', $return['groupe'][0]['temps']);
    $this->checkAndUpdateCmd('Bulletinfrdate1', $return['groupe'][1]['date']);
    $this->checkAndUpdateCmd('Bulletinfrtitre1', $return['groupe'][1]['titre']);
    $this->checkAndUpdateCmd('Bulletinfrtemps1', $return['groupe'][1]['temps']);
  }

  public function getBulletinSemaine() {
    $url = 'https://rpcache-aa.meteofrance.com/internet2018client/2.0/report?domain=france&report_type=forecast&report_subtype=BGP_mensuel';
    $return = self::callMeteoWS($url, true);
    $this->checkAndUpdateCmd('Bulletindatesem', $return['groupe'][0]['date']);
    $this->checkAndUpdateCmd('Bulletintempssem', $return['groupe'][0]['titre']);
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

  public static function callMeteoWS($_url, $_xml = false, $_token = true) {
    //$token = config::byKey('token', 'meteofrance');
    if ($_token)  {
      $token = '&token=' . '__Wj7dVSTjV9YGu1guveLyDq0g7S7TfTjaHBTPTpO0kj8__';
    } else {
      $token = '';
    }
    $request_http = new com_http($_url . $token);
    $request_http->setNoSslCheck(true);
    $request_http->setNoReportError(true);
    $return = $request_http->exec(15,2);
    if ($return === false) {
      log::add(__CLASS__, 'debug', 'Unable to fetch ' . $_url);
      return;
    } else {
      log::add(__CLASS__, 'debug', 'Get ' . $_url);
      log::add(__CLASS__, 'debug', 'Result ' . $return);
    }
    if ($_xml) {
      $xml = simplexml_load_string($return, 'SimpleXMLElement', LIBXML_NOCDATA);
      $return = json_encode($xml);
      log::add(__CLASS__, 'debug', 'Result ' . $return);
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
      log::add(__CLASS__, 'debug', 'Get ' . $_url);
      log::add(__CLASS__, 'debug', 'Result ' . $return);
    }
    return json_decode($return, true);
  }

  public function loadCmdFromConf($type) {
    /*create commands based on template*/
    if (!is_file(dirname(__FILE__) . '/../config/devices/' . $type . '.json')) {
      return;
    }
    $content = file_get_contents(dirname(__FILE__) . '/../config/devices/' . $type . '.json');
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
        $cmd->save();
      }
    }
  }

  public function getMFimg($filename) {
    $url = 'https://meteofrance.com/modules/custom/mf_tools_common_theme_public/svg/weather';
    $localdir = __DIR__ ."/../../data/icones";
    if(!file_exists("$localdir/$filename")) {
      $content = file_get_contents("$url/$filename");
      if($content === false) {
        log::add(__CLASS__,'debug',"Unable to get file: $url/$filename");
        return("$url/$filename");
      }
      if(!is_dir(__DIR__ ."/../../data/icones"))
        @mkdir(__DIR__ ."/../../data/icones",0777,true);
      $res = file_put_contents("$localdir/$filename",$content);
      if($res === false) {
        log::add(__CLASS__,'debug',"Unable to save file: $localdir/$filename");
        return("$url/$filename");
      }
    }
    return("plugins/" .__CLASS__ ."/data/icones/$filename");
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

    $html_forecast = '';

    if ($_version != 'mobile' || $this->getConfiguration('fullMobileDisplay', 0) == 1) {
      $forcast_template = getTemplate('core', $version, 'forecast', 'meteofrance');
      for ($i = 0; $i < 5; $i++) {
        if ($i == 0) {
          $replace['#day#'] = "Aujourd'hui";
          $temperature_min = $this->getCmd(null, 'Meteoday0temperatureMin');
          $replace['#low_temperature#'] = is_object($temperature_min) ? round($temperature_min->execCmd()) : '';

          $temperature_max = $this->getCmd(null, 'Meteoday0temperatureMax');
          $replace['#hight_temperature#'] = is_object($temperature_max) ? round($temperature_max->execCmd()) : '';
          $replace['#tempid#'] = is_object($temperature_max) ? $temperature_max->getId() : '';

          $desc = $this->getCmd(null, 'Meteoday0description');
          $replace['#condition#'] = is_object($desc) ? $desc->execCmd() : 0;

          $icone = $this->getCmd(null, 'Meteoday0icon');
          if(is_object($icone)) {
            $img = self::getMFimg($icone->execCmd() .'.svg');
            $replace['#icone#'] = $img;
          }
        } else if ($i == 1) {
          $replace['#day#'] = '+ 1h';
          $temperature_min = $this->getCmd(null, 'Meteodayh1temperature');
          $replace['#low_temperature#'] = is_object($temperature_min) ? round($temperature_min->execCmd()) : '';

          $temperature_max = $this->getCmd(null, 'Meteodayh1temperatureRes');
          $replace['#hight_temperature#'] = is_object($temperature_max) ? round($temperature_max->execCmd()) : '';
          $replace['#tempid#'] = is_object($temperature_max) ? $temperature_max->getId() : '';

          $desc = $this->getCmd(null, 'Meteodayh1description');
          $replace['#condition#'] = is_object($desc) ? $desc->execCmd() : 0;

          $icone = $this->getCmd(null, 'hourly1icon');
          if(is_object($icone)) {
            $img = self::getMFimg($icone->execCmd() .'.svg');
            $replace['#icone#'] = $img;
          }
        } else {
          if ($i == 2) {
            $step = 'Meteoday1';
          } else if ($i == 3) {
            $step = 'Meteoday2';
          } else {
            $step = 'Meteoday3';
          }
          $j = $i - 1;
          $replace['#day#'] = date_fr(date('l', strtotime('+' . $j . ' days')));
          $temperature_min = $this->getCmd(null, $step . 'temperatureMin');
          $replace['#low_temperature#'] = is_object($temperature_min) ? round($temperature_min->execCmd()) : '';

          $temperature_max = $this->getCmd(null, $step . 'temperatureMax');
          $replace['#hight_temperature#'] = is_object($temperature_max) ? round($temperature_max->execCmd()) : '';
          $replace['#tempid#'] = is_object($temperature_max) ? $temperature_max->getId() : '';

          $desc = $this->getCmd(null, $step . 'description');
          $replace['#condition#'] = is_object($desc) ? $desc->execCmd() : 0;

          $icone = $this->getCmd(null, $step . 'icon');
          if(is_object($icone)) {
            $img = self::getMFimg($icone->execCmd() .'.svg');
            $replace['#icone#'] = $img;
          }
        }

        $html_forecast .= template_replace($replace, $forcast_template);
      }
    }

    $replace['#forecast#'] = $html_forecast;
    $replace['#city#'] = $this->getName();

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

    $wind_speed = $this->getCmd(null, 'Meteoday0vitesseVent');
    $replace['#windspeed#'] = is_object($wind_speed) ? $wind_speed->execCmd()*3.6 : '';
    $replace['#windid#'] = is_object($wind_speed) ? $wind_speed->getId() : '';

    $sunrise = $this->getCmd(null, 'Ephemerissunrise_time');
    $replace['#sunrise#'] = is_object($sunrise) ? substr_replace($sunrise->execCmd(),':',-2,0) : '';
    $replace['#sunriseid#'] = is_object($sunrise) ? $sunrise->getId() : '';

    $sunset = $this->getCmd(null, 'Ephemerissunset_time');
    $replace['#sunset#'] = is_object($sunset) ? substr_replace($sunset->execCmd(),':',-2,0) : '';
    $replace['#sunsetid#'] = is_object($sunset) ? $sunset->getId() : '';

    $wind_direction = $this->getCmd(null, 'Meteoday0directionVent');
    $replace['#wind_direction#'] = is_object($wind_direction) ? $wind_direction->execCmd() : 0;

    $refresh = $this->getCmd(null, 'refresh');
    $replace['#refresh#'] = is_object($refresh) ? $refresh->getId() : '';

    $condition = $this->getCmd(null, 'Meteoday0description');
    if (is_object($condition)) {
      $replace['#condition#'] = $condition->execCmd();
      $replace['#conditionid#'] = $condition->getId();
      $replace['#collectDate#'] = $condition->getCollectDate();
    } else {
      $replace['#condition#'] = '';
      $replace['#collectDate#'] = '';
    }

    $icone = $this->getCmd(null, 'Meteoday0icon');
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
      $replace['#h1h#'] = date('H:i',strtotime('+ 1 hour', mktime($heure[0] . $heure[1], $heure[3] . $heure[4])));
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
        $replace['#prev' . $i . '#'] = $prev->execCmd();
        $replace['#prev' . $i . 'Color#'] = $color[$prev->execCmd()];
        $replace['#prev' . $i . 'Text#'] = $text->execCmd();
      }
    }

    $color = Array();
    $color[1] = ' color: #00ff1e';
    $color[2] = ' color: #FFFF00';
    $color[3] = ' color: #FFA500';
    $color[4] = ' color: #E50000';

    $vigilance = $this->getCmd(null, 'Vigilancephenomenon_max_color_id1');
    $replace['#vig1Colors#'] = $color[$vigilance->execCmd()];
    $vigilance = $this->getCmd(null, 'Vigilancephenomenon_max_color_id2');
    $replace['#vig2Colors#'] = $color[$vigilance->execCmd()];
    $vigilance = $this->getCmd(null, 'Vigilancephenomenon_max_color_id3');
    $replace['#vig3Colors#'] = $color[$vigilance->execCmd()];
    $vigilance = $this->getCmd(null, 'Vigilancephenomenon_max_color_id4');
    $replace['#vig4Colors#'] = $color[$vigilance->execCmd()];
    $vigilance = $this->getCmd(null, 'Vigilancephenomenon_max_color_id5');
    $replace['#vig5Colors#'] = $color[$vigilance->execCmd()];

    return $this->postToHtml($_version, template_replace($replace, getTemplate('core', $version, 'current', 'meteofrance')));
  }

}

class meteofranceCmd extends cmd {
  public function execute($_options = null) {
    if ($this->getLogicalId() == 'refresh') {
      $this->getEqLogic()->getRain();
      $this->getEqLogic()->getInformations();
    }
  }
}

?>
