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

  public static function cron5() {
    foreach (eqLogic::byType(__CLASS__, true) as $meteofrance) {
      $meteofrance->getRain();
    }
  }

  public static function cronHourly() {
    foreach (eqLogic::byType(__CLASS__, true) as $meteofrance) {
      $meteofrance->getVigilance();
      $meteofrance->getMarine();
      $meteofrance->getTide();
      $meteofrance->getAlerts();
      $meteofrance->getBulletinFrance();
      $meteofrance->getDetailsValues();
      $meteofrance->getBulletinVille();
      $meteofrance->getDailyExtras();
    }
  }

  public static function cronDaily() {
    foreach (eqLogic::byType(__CLASS__, true) as $meteofrance) {
      $meteofrance->getEphemeris();
      $meteofrance->getBulletinSemaine();
    }
  }

  public function preSave() {
    $args = $this->getInsee();
    $this->getDetails($args);
    $this->getBulletinDetails($args);
  }

  public function postSave() {
    $this->getInformations();
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
    $url = 'https://api-adresse.data.gouv.fr/search/?q=postcode=' . $array['zip'] . '&limit=1';
    $return = self::callURL($url);
    log::add(__CLASS__, 'debug', 'Insee ' . print_r($return['features'][0]['properties'],true));
    $array['insee'] = $return['features'][0]['properties']['citycode'];
    $array['ville'] = self::lowerAccent($array['ville']);
    return $array;
  }

  public function getDetails($_array = array()) {
    $url = 'http://ws.meteofrance.com/ws/getDetail/france/' . $_array['insee'] . '0.json';
    $return = self::callURL($url);
    $this->setConfiguration('bulletinCote', $return['result']['ville']['bulletinCote']);
    $this->setConfiguration('couvertPluie', $return['result']['ville']['couvertPluie']);
    $this->setConfiguration('lat', $return['result']['ville']['latitude']);
    $this->setConfiguration('lon', $return['result']['ville']['longitude']);
    $this->setConfiguration('numDept', $return['result']['ville']['numDept']);
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
    $url = 'https://rpcache-aa.meteofrance.com/internet2018client/2.0/forecast?lat=' . $this->getConfiguration('lat') . '&lon=' . $this->getConfiguration('lon') . '&id=&instants=&day=0';
    $return = self::callMeteoWS($url);
    $this->checkAndUpdateCmd('Meteoday0PluieCumul', $return['properties']['daily_forecast']['total_precipitation_24h']);
    $this->checkAndUpdateCmd('MeteoprobaStorm', $return['properties']['probability_forecast'][0]['storm_hazard']);
    $this->checkAndUpdateCmd('MeteonowCloud', $return['properties']['forecast'][0]['total_cloud_cover']);
    $this->checkAndUpdateCmd('MeteonowPression', $return['properties']['forecast'][0]['P_sea']);
    $this->checkAndUpdateCmd('MeteonowTemperature', $return['properties']['forecast'][0]['T']);
    $this->checkAndUpdateCmd('MeteonowHumidity', $return['properties']['forecast'][0]['relative_humidity']);
  }

  public function getDetailsValues() {
    $url = 'http://ws.meteofrance.com/ws/getDetail/france/' . $this->getConfiguration('insee') . '0.json';
    $return = self::callURL($url);
    $this->checkAndUpdateCmd('Meteoday0description', $return['result']['resumes']['0_resume']['description']);
    $this->checkAndUpdateCmd('Meteoday0directionVent', $return['result']['resumes']['0_resume']['directionVent']);
    $this->checkAndUpdateCmd('Meteoday0vitesseVent', $return['result']['resumes']['0_resume']['vitesseVent']);
    $this->checkAndUpdateCmd('Meteoday0forceRafales', $return['result']['resumes']['0_resume']['forceRafales']);
    $this->checkAndUpdateCmd('Meteoday0temperatureMin', $return['result']['resumes']['0_resume']['temperatureMin']);
    $this->checkAndUpdateCmd('Meteoday0temperatureMax', $return['result']['resumes']['0_resume']['temperatureMax']);
    $this->checkAndUpdateCmd('Meteoday0indiceUV', $return['result']['resumes']['0_resume']['indiceUV']);
    $this->checkAndUpdateCmd('Meteoday1description', $return['result']['resumes']['1_resume']['description']);
    $this->checkAndUpdateCmd('Meteoday1directionVent', $return['result']['resumes']['1_resume']['directionVent']);
    $this->checkAndUpdateCmd('Meteoday1vitesseVent', $return['result']['resumes']['1_resume']['vitesseVent']);
    $this->checkAndUpdateCmd('Meteoday1forceRafales', $return['result']['resumes']['1_resume']['forceRafales']);
    $this->checkAndUpdateCmd('Meteoday1temperatureMin', $return['result']['resumes']['1_resume']['temperatureMin']);
    $this->checkAndUpdateCmd('Meteoday1temperatureMax', $return['result']['resumes']['1_resume']['temperatureMax']);
    $this->checkAndUpdateCmd('Meteoday1indiceUV', $return['result']['resumes']['1_resume']['indiceUV']);
    $this->checkAndUpdateCmd('Meteoday2description', $return['result']['resumes']['2_resume']['description']);
    $this->checkAndUpdateCmd('Meteoday2directionVent', $return['result']['resumes']['2_resume']['directionVent']);
    $this->checkAndUpdateCmd('Meteoday2vitesseVent', $return['result']['resumes']['2_resume']['vitesseVent']);
    $this->checkAndUpdateCmd('Meteoday2forceRafales', $return['result']['resumes']['2_resume']['forceRafales']);
    $this->checkAndUpdateCmd('Meteoday2temperatureMin', $return['result']['resumes']['2_resume']['temperatureMin']);
    $this->checkAndUpdateCmd('Meteoday2temperatureMax', $return['result']['resumes']['2_resume']['temperatureMax']);
    $this->checkAndUpdateCmd('Meteoday2indiceUV', $return['result']['resumes']['2_resume']['indiceUV']);
    $this->checkAndUpdateCmd('Meteomatin0description', $return['result']['previsions']['0_matin']['description']);
    $this->checkAndUpdateCmd('Meteomatin0directionVent', $return['result']['previsions']['0_matin']['directionVent']);
    $this->checkAndUpdateCmd('Meteomatin0vitesseVent', $return['result']['previsions']['0_matin']['vitesseVent']);
    $this->checkAndUpdateCmd('Meteomatin0forceRafales', $return['result']['previsions']['0_matin']['forceRafales']);
    $this->checkAndUpdateCmd('Meteomatin0temperatureMin', $return['result']['previsions']['0_matin']['temperatureMin']);
    $this->checkAndUpdateCmd('Meteomatin0temperatureMax', $return['result']['previsions']['0_matin']['temperatureMax']);
    $this->checkAndUpdateCmd('Meteomatin1description', $return['result']['previsions']['1_matin']['description']);
    $this->checkAndUpdateCmd('Meteomatin1directionVent', $return['result']['previsions']['1_matin']['directionVent']);
    $this->checkAndUpdateCmd('Meteomatin1vitesseVent', $return['result']['previsions']['1_matin']['vitesseVent']);
    $this->checkAndUpdateCmd('Meteomatin1forceRafales', $return['result']['previsions']['1_matin']['forceRafales']);
    $this->checkAndUpdateCmd('Meteomatin1temperatureMin', $return['result']['previsions']['1_matin']['temperatureMin']);
    $this->checkAndUpdateCmd('Meteomatin1temperatureMax', $return['result']['previsions']['1_matin']['temperatureMax']);
    $this->checkAndUpdateCmd('Meteomidi0description', $return['result']['previsions']['0_midi']['description']);
    $this->checkAndUpdateCmd('Meteomidi0directionVent', $return['result']['previsions']['0_midi']['directionVent']);
    $this->checkAndUpdateCmd('Meteomidi0vitesseVent', $return['result']['previsions']['0_midi']['vitesseVent']);
    $this->checkAndUpdateCmd('Meteomidi0forceRafales', $return['result']['previsions']['0_midi']['forceRafales']);
    $this->checkAndUpdateCmd('Meteomidi0temperatureMin', $return['result']['previsions']['0_midi']['temperatureMin']);
    $this->checkAndUpdateCmd('Meteomidi0temperatureMax', $return['result']['previsions']['0_midi']['temperatureMax']);
    $this->checkAndUpdateCmd('Meteomidi1description', $return['result']['previsions']['1_midi']['description']);
    $this->checkAndUpdateCmd('Meteomidi1directionVent', $return['result']['previsions']['1_midi']['directionVent']);
    $this->checkAndUpdateCmd('Meteomidi1vitesseVent', $return['result']['previsions']['1_midi']['vitesseVent']);
    $this->checkAndUpdateCmd('Meteomidi1forceRafales', $return['result']['previsions']['1_midi']['forceRafales']);
    $this->checkAndUpdateCmd('Meteomidi1temperatureMin', $return['result']['previsions']['1_midi']['temperatureMin']);
    $this->checkAndUpdateCmd('Meteomidi1temperatureMax', $return['result']['previsions']['1_midi']['temperatureMax']);
    $this->checkAndUpdateCmd('Meteosoir0description', $return['result']['previsions']['0_soir']['description']);
    $this->checkAndUpdateCmd('Meteosoir0directionVent', $return['result']['previsions']['0_soir']['directionVent']);
    $this->checkAndUpdateCmd('Meteosoir0vitesseVent', $return['result']['previsions']['0_soir']['vitesseVent']);
    $this->checkAndUpdateCmd('Meteosoir0forceRafales', $return['result']['previsions']['0_soir']['forceRafales']);
    $this->checkAndUpdateCmd('Meteosoir0temperatureMin', $return['result']['previsions']['0_soir']['temperatureMin']);
    $this->checkAndUpdateCmd('Meteosoir0temperatureMax', $return['result']['previsions']['0_soir']['temperatureMax']);
    $this->checkAndUpdateCmd('Meteosoir1description', $return['result']['previsions']['1_soir']['description']);
    $this->checkAndUpdateCmd('Meteosoir1directionVent', $return['result']['previsions']['1_soir']['directionVent']);
    $this->checkAndUpdateCmd('Meteosoir1vitesseVent', $return['result']['previsions']['1_soir']['vitesseVent']);
    $this->checkAndUpdateCmd('Meteosoir1forceRafales', $return['result']['previsions']['1_soir']['forceRafales']);
    $this->checkAndUpdateCmd('Meteosoir1temperatureMin', $return['result']['previsions']['1_soir']['temperatureMin']);
    $this->checkAndUpdateCmd('Meteosoir1temperatureMax', $return['result']['previsions']['1_soir']['temperatureMax']);
    $this->checkAndUpdateCmd('Meteonuit0description', $return['result']['previsions']['0_nuit']['description']);
    $this->checkAndUpdateCmd('Meteonuit0directionVent', $return['result']['previsions']['0_nuit']['directionVent']);
    $this->checkAndUpdateCmd('Meteonuit0vitesseVent', $return['result']['previsions']['0_nuit']['vitesseVent']);
    $this->checkAndUpdateCmd('Meteonuit0forceRafales', $return['result']['previsions']['0_nuit']['forceRafales']);
    $this->checkAndUpdateCmd('Meteonuit0temperatureMin', $return['result']['previsions']['0_nuit']['temperatureMin']);
    $this->checkAndUpdateCmd('Meteonuit0temperatureMax', $return['result']['previsions']['0_nuit']['temperatureMax']);
    $this->checkAndUpdateCmd('Meteonuit1description', $return['result']['previsions']['1_nuit']['description']);
    $this->checkAndUpdateCmd('Meteonuit1directionVent', $return['result']['previsions']['1_nuit']['directionVent']);
    $this->checkAndUpdateCmd('Meteonuit1vitesseVent', $return['result']['previsions']['1_nuit']['vitesseVent']);
    $this->checkAndUpdateCmd('Meteonuit1forceRafales', $return['result']['previsions']['1_nuit']['forceRafales']);
    $this->checkAndUpdateCmd('Meteonuit1temperatureMin', $return['result']['previsions']['1_nuit']['temperatureMin']);
    $this->checkAndUpdateCmd('Meteonuit1temperatureMax', $return['result']['previsions']['1_nuit']['temperatureMax']);

    foreach(array_slice($return['result']['previsions48h'], 0, 1) as $value) {
      log::add(__CLASS__, 'debug', 'Proba ' . print_r($value,true));
      $this->checkAndUpdateCmd('MeteoprobaPluie', $value['probaPluie']);
      $this->checkAndUpdateCmd('MeteoprobaNeige', $value['probaNeige']);
      $this->checkAndUpdateCmd('MeteoprobaGel', $value['probaGel']);
    }
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
    $return['nuit claire'] = 'clear-night';
    $return['tres nuageux'] = 'cloudy';
    $return['couvert'] = 'cloudy';
    $return['brume'] = 'fog';
    $return['brume ou bancs de brouillard'] = 'fog';
    $return['brouillard'] = 'fog';
    $return['brouillard givrant'] = 'fog';
    $return['risque de grele'] = 'hail';
    $return['orages'] = 'lightning';
    $return['risque d\'orages'] = 'lightning';
    $return['pluie orageuses'] = 'lightning-rainy';
    $return['pluies orageuses'] = 'lightning-rainy';
    $return['averses orageuses'] = 'lightning-rainy';
    $return['ciel voile'] = 'partlycloudy';
    $return['ciel voile nuit'] = 'partlycloudy';
    $return['eclaircies'] = 'partlycloudy';
    $return['peu nuageux'] = 'partlycloudy';
    $return['pluie forte'] = 'pouring';
    $return['bruine / pluie faible'] = 'rainy';
    $return['bruine'] = 'rainy';
    $return['pluie faible'] = 'rainy';
    $return['pluies eparses / rares averses'] = 'rainy';
    $return['pluies eparses'] = 'rainy';
    $return['rares averses'] = 'rainy';
    $return['pluie moderee'] = 'rainy';
    $return['pluie / averses'] = 'rainy';
    $return['pluie faible'] = 'rainy';
    $return['averses'] = 'rainy';
    $return['pluie'] = 'rainy';
    $return['neige'] = 'snowy';
    $return['neige forte'] = 'snowy';
    $return['quelques flocons'] = 'snowy';
    $return['averses de neige'] = 'snowy';
    $return['neige / averses de neige'] = 'snowy';
    $return['pluie et neige'] = 'snowy-rainy';
    $return['pluie verglacante'] = 'snowy-rainy';
    $return['ensoleille'] = 'sunny';
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
    if ($result === false) {
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
    if ($result === false) {
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

}

class meteofranceCmd extends cmd {
  public function execute($_options = null) {
    if ($this->getLogicalId() == 'refresh') {
      $this->getEqLogic()->getInformations();
    }
  }
}

?>
