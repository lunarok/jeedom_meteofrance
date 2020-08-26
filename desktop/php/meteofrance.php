<?php

if (!isConnect('admin')) {
  throw new Exception('{{401 - Accès non autorisé}}');
}
sendVarToJS('eqType', 'meteofrance');
$eqLogics = eqLogic::byType('meteofrance');

?>

<div class="row row-overflow">
  <div class="col-lg-2 col-sm-3 col-sm-4" id="hidCol" style="display: none;">
    <div class="bs-sidebar">
      <ul id="ul_eqLogic" class="nav nav-list bs-sidenav">
        <li class="filter" style="margin-bottom: 5px;"><input class="filter form-control input-sm" placeholder="{{Rechercher}}" style="width: 100%"/></li>
        <?php
        foreach ($eqLogics as $eqLogic) {
          echo '<li class="cursor li_eqLogic" data-eqLogic_id="' . $eqLogic->getId() . '"><a>' . $eqLogic->getHumanName(true) . '</a></li>';
        }
        ?>
      </ul>
    </div>
  </div>

  <div class="col-lg-12 eqLogicThumbnailDisplay" id="listCol">
    <legend><i class="fas fa-cog"></i>  {{Gestion}}</legend>
    <div class="eqLogicThumbnailContainer logoPrimary">

      <div class="cursor eqLogicAction logoSecondary" data-action="add">
        <i class="fas fa-plus-circle"></i>
        <br/>
        <span>{{Ajouter}}</span>
      </div>
      <div class="cursor eqLogicAction logoSecondary" data-action="gotoPluginConf">
        <i class="fas fa-wrench"></i>
        <br/>
        <span>{{Configuration}}</span>
      </div>

    </div>

    <input class="form-control" placeholder="{{Rechercher}}" id="in_searchEqlogic" />

    <legend><i class="fas fa-home" id="butCol"></i> {{Mes Equipements}}</legend>
    <div class="eqLogicThumbnailContainer">
      <?php
      foreach ($eqLogics as $eqLogic) {
        $opacity = ($eqLogic->getIsEnable()) ? '' : jeedom::getConfiguration('eqLogic:style:noactive');
        echo '<div class="eqLogicDisplayCard cursor" data-eqLogic_id="' . $eqLogic->getId() . '" style="background-color : #ffffff ; height : 200px;margin-bottom : 10px;padding : 5px;border-radius: 2px;width : 160px;margin-left : 10px;' . $opacity . '" >';
        echo "<center>";
        echo '<img src="plugins/meteofrance/plugin_info/meteofrance_icon.png" height="105" width="95" />';
        echo "</center>";
        echo '<span style="font-size : 1.1em;position:relative; top : 15px;word-break: break-all;white-space: pre-wrap;word-wrap: break-word;"><center>' . $eqLogic->getHumanName(true, true) . '</center></span>';
        echo '</div>';
      }
      ?>
    </div>
  </div>


  <div class="col-lg-10 col-md-9 col-sm-8 eqLogic" style="border-left: solid 1px #EEE; padding-left: 25px;display: none;">
    <a class="btn btn-success eqLogicAction pull-right" data-action="save"><i class="fas fa-check-circle"></i> {{Sauvegarder}}</a>
    <a class="btn btn-danger eqLogicAction pull-right" data-action="remove"><i class="fas fa-minus-circle"></i> {{Supprimer}}</a>
    <a class="btn btn-default eqLogicAction pull-right" data-action="configure"><i class="fas fa-cogs"></i> {{Configuration avancée}}</a>
    <ul class="nav nav-tabs" role="tablist">
      <li role="presentation"><a href="#" class="eqLogicAction" aria-controls="home" role="tab" data-toggle="tab" data-action="returnToThumbnailDisplay"><i class="fas fa-arrow-circle-left"></i></a></li>
      <li role="presentation" class="active"><a href="#eqlogictab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-tachometer-alt"></i> {{Equipement}}</a></li>
      <li role="presentation"><a href="#commandtab" aria-controls="profile" role="tab" data-toggle="tab"><i class="fas fa-list-alt"></i> {{Commandes}}</a></li>
    </ul>
    <div class="tab-content" style="height:calc(100% - 50px);overflow:auto;overflow-x: hidden;">
      <div role="tabpanel" class="tab-pane active" id="eqlogictab">
        <br/>
        <form class="form-horizontal">
          <fieldset>
            <div class="form-group">
              <label class="col-sm-3 control-label">{{Nom}}</label>
              <div class="col-sm-3">
                <input type="text" class="eqLogicAttr form-control" data-l1key="id" style="display : none;" />
                <input type="text" class="eqLogicAttr form-control" data-l1key="name" placeholder="{{Nom de l'équipement Vigilances Météo}}"/>
              </div>
            </div>
            <div class="form-group">
              <label class="col-sm-3 control-label" >{{Objet parent}}</label>
              <div class="col-sm-3">
                <select class="form-control eqLogicAttr" data-l1key="object_id">
                  <option value="">{{Aucun}}</option>
                  <?php
                  foreach (jeeObject::all() as $object) {
                    echo '<option value="' . $object->getId() . '">' . $object->getName() . '</option>';
                  }
                  ?>
                </select>
              </div>
            </div>
            <div class="form-group">
              <label class="col-sm-3 control-label">{{Catégorie}}</label>
              <div class="col-sm-8">
                <?php
                foreach (jeedom::getConfiguration('eqLogic:category') as $key => $value) {
                  echo '<label class="checkbox-inline">';
                  echo '<input type="checkbox" class="eqLogicAttr" data-l1key="category" data-l2key="' . $key . '" />' . $value['name'];
                  echo '</label>';
                }
                ?>
              </div>
            </div>
            <div class="form-group">
              <label class="col-sm-3 control-label"></label>
              <div class="col-sm-8">
                <label class="checkbox-inline"><input type="checkbox" class="eqLogicAttr" data-l1key="isEnable" checked/>{{Activer}}</label>
                <label class="checkbox-inline"><input type="checkbox" class="eqLogicAttr" data-l1key="isVisible" checked/>{{Visible}}</label>
              </div>
            </div>

            <div id="geolocEq" class="form-group">
              <label class="col-sm-3 control-label">{{Localisation à utiliser}}</label>
              <div class="col-sm-3">
                <select class="form-control eqLogicAttr configuration" id="geoloc" data-l1key="configuration" data-l2key="geoloc">
                  <?php
                  $none = 0;
                  if (class_exists('geotravCmd')) {
                    foreach (eqLogic::byType('geotrav') as $geoloc) {
                      if ($geoloc->getConfiguration('type') == 'location') {
                        $none = 1;
                        echo '<option value="' . $geoloc->getId() . '">' . $geoloc->getName() . '</option>';
                      }
                    }
                  }
                  if ((config::byKey('info::latitude') != '') && (config::byKey('info::longitude') != '') && (config::byKey('info::postalCode') != '') && (config::byKey('info::stateCode') != '')) {
                    echo '<option value="jeedom">Configuration Jeedom</option>';
                    $none = 1;
                  }
                  if ($none == 0) {
                    echo '<option value="">Pas de localisation disponible</option>';
                  }
                  ?>
                </select>
              </div>
            </div>

            <div class="form-group" id="zoneRain">
              <label class="col-sm-3 control-label"></label>
              <div class="col-sm-3">
                <input type="checkbox" class="eqLogicAttr form-control configuration" data-l1key="configuration" data-l2key="couvertPluie" style="display : none;" id="rain"/>
                <a class="btn btn-default" id='btRain'><i class="fas fa-umbrella"></i> {{Créer les commandes Pluie 1h}}</a>
              </div>
            </div>

            <div class="form-group">
              <label class="col-sm-3 control-label"></label>
              <div class="col-sm-3">
                <a class="btn btn-default" id='btVigilance'><i class="fas fa-exclamation"></i> {{Créer les commandes Vigilance}}</a>
              </div>
            </div>

            <div class="form-group">
              <label class="col-sm-3 control-label"></label>
              <div class="col-sm-3">
                <a class="btn btn-default" id='btEphemeris'><i class="fas fa-moon"></i> {{Créer les commandes Ephéméride}}</a>
              </div>
            </div>

            <div class="form-group">
              <label class="col-sm-3 control-label"></label>
              <div class="col-sm-3">
                <a class="btn btn-default" id='btMeteo'><i class="fas fa-sun"></i> {{Créer les commandes Météo}}</a>
              </div>
            </div>

            <div class="form-group">
              <label class="col-sm-3 control-label"></label>
              <div class="col-sm-3">
                <a class="btn btn-default" id='btBulletin'><i class="fas fa-temperature-high"></i> {{Créer les commandes Bulletin Météo}}</a>
              </div>
            </div>

            <div class="form-group" id="zoneBulletin">
              <label class="col-sm-3 control-label"></label>
              <div class="col-sm-3">
                <input type="checkbox" class="eqLogicAttr form-control configuration" data-l1key="configuration" data-l2key="bulletinVille" style="display : none;" id="ville"/>
                <a class="btn btn-default" id='btBulletinVille'><i class="fas fa-temperature-low"></i> {{Créer les commandes Bulletin Météo Ville}}</a>
              </div>
            </div>

            <div class="form-group" id="zoneMarine">
              <label class="col-sm-3 control-label"></label>
              <div class="col-sm-3">
                <input type="checkbox" class="eqLogicAttr form-control configuration" data-l1key="configuration" data-l2key="bulletinCote" style="display : none;" id="marine"/>
                <a class="btn btn-default" id='btMarine'><i class="fas fa-ship"></i> {{Créer les commandes Météo Marine}}</a>
              </div>
            </div>

            <div class="form-group" id="zoneCrue">
              <label class="col-sm-3 control-label"></label>
              <div class="col-sm-3">
                <input type="checkbox" class="eqLogicAttr form-control configuration" data-l1key="configuration" data-l2key="crue" style="display : none;" id="crue"/>
                <a class="btn btn-default" id='btCrue'><i class="fas fa-water"></i> {{Créer les commandes Vigicrues}}</a>
              </div>
            </div>

          </fieldset>
        </form>
      </div>
      <div role="tabpanel" class="tab-pane" id="commandtab">
        <br/>
        <table id="table_cmd" class="table table-bordered table-condensed">
          <thead>
            <tr>
              <th style="width: 100px;">#</th>
              <th style="width: 500px;">{{Nom}}</th>
              <th style="width: 200px;">{{Options}}</th>
              <th style="width: 150px;"></th>
            </tr>
          </thead>
          <tbody>

          </tbody>
        </table>

      </div>
    </div>
  </div>
</div>

<?php include_file('desktop', 'meteofrance', 'js', 'meteofrance'); ?>
<?php include_file('core', 'plugin.template', 'js'); ?>
