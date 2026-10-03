<?php

if (!isConnect()) {
    throw new Exception(__('401 - Accès non autorisé', __FILE__));
}

$plugin = plugin::byId('apsystemsCloud');
sendVarToJS('eqType', $plugin->getId());

$eqLogics = eqLogic::byType('apsystemsCloud');


?>

<div class="row row-overflow">
  <div class="col-xs-12 eqLogicThumbnailDisplay">
    <legend><i class="fas fa-solar-panel"></i> {{APsystems Cloud v2}}</legend>
    <div class="eqLogicThumbnailContainer">
      <div class="cursor eqLogicAction logoPrimary" data-action="add">
        <i class="fas fa-plus-circle"></i>
        <br/>
        <span>{{Ajouter}}</span>
      </div>
      <?php
      foreach ($eqLogics as $eqLogic) {
          echo '<div class="eqLogicDisplayCard cursor" data-eqLogic_id="' . $eqLogic->getId() . '">';
          echo '<i class="fas fa-bolt"></i><br>';
          echo '<span class="name">' . $eqLogic->getHumanName(true, true) . '</span>';
          echo '</div>';
      }
      ?>
    </div>
  </div>

  <div class="col-xs-12 eqLogic" style="display:none;">
    <div class="input-group pull-right" style="margin-top:5px;">
      <span class="input-group-btn">
        <a class="btn btn-default eqLogicAction pull-right" data-action="configure"><i class="fas fa-cogs"></i> {{Configuration avancée}}</a>
        <a class="btn btn-default eqLogicAction pull-right" data-action="copy"><i class="far fa-copy"></i> {{Dupliquer}}</a>
        <a class="btn btn-danger eqLogicAction pull-right" data-action="remove"><i class="fas fa-trash"></i> {{Supprimer}}</a>
        <a class="btn btn-success eqLogicAction pull-right" data-action="save"><i class="fas fa-check-circle"></i> {{Sauvegarder}}</a>
      </span>
    </div>

    <ul class="nav nav-tabs" role="tablist">
      <li role="presentation" class="active"><a href="#eqlogictab" role="tab" data-toggle="tab">{{Équipement}}</a></li>
      <li role="presentation"><a href="#commandtab" role="tab" data-toggle="tab">{{Commandes}}</a></li>
    </ul>

    <div class="tab-content">
      <div role="tabpanel" class="tab-pane active" id="eqlogictab">
        <br/>
        <form class="form-horizontal">
          <fieldset>
            <div class="form-group">
              <label class="col-sm-3 control-label">{{Nom de l'équipement}}</label>
              <div class="col-sm-3">
                <input type="text" class="eqLogicAttr form-control" data-l1key="id" style="display : none;" />
                <input type="text" class="eqLogicAttr form-control" data-l1key="name"/>
              </div>
            </div>

            <div class="form-group">
              <label class="col-sm-3 control-label">{{Objet parent}}</label>
              <div class="col-sm-3">
                <select class="eqLogicAttr form-control" data-l1key="object_id">
                  <?php
                  foreach (jeeObject::all() as $object) {
                      echo '<option value="' . $object->getId() . '">' . $object->getName() . '</option>';
                  }
                  ?>
                </select>
              </div>
            </div>

            <div class="form-group">
              <label class="col-sm-3 control-label">{{Activer}}</label>
              <div class="col-sm-3">
                <input type="checkbox" class="eqLogicAttr" data-l1key="isEnable"/>
              </div>
            </div>

            <div class="form-group">
              <label class="col-sm-3 control-label">{{Visible}}</label>
              <div class="col-sm-3">
                <input type="checkbox" class="eqLogicAttr" data-l1key="isVisible"/>
              </div>
            </div>

            <div class="form-group">
              <label class="col-sm-3 control-label">{{Plant ID}}</label>
              <div class="col-sm-3">
                <input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="plant_id"/>
              </div>
            </div>
            <?php
                         
              $quota_month_used  = config::byKey('apsystemsCloud_quota_used', 'apsystemsCloud', 0);
              $quota_month_limit = config::byKey('apsystemsCloud_api_quota_month', 'apsystemsCloud', 1000);
              $percent_month = ($quota_month_limit > 0) ? min(100, round(($quota_month_used / $quota_month_limit) * 100)) : 0;
              $quota_day_used  = config::byKey('apsystemsCloud_quota_day_used', 'apsystemsCloud', 0);
              $quota_day_limit = config::byKey('apsystemsCloud_quota_day_limit', 'apsystemsCloud', 100);
              $percent_day = ($quota_day_limit > 0) ? min(100, round(($quota_day_used / $quota_day_limit) * 100)) : 0;

              
            ?>

            <div class="form-group">
                <label class="col-sm-3 control-label">Quota API :</label>
                <div class="col-sm-3">
                    <span id="quota_month_display">
                         <?php echo $quota_month_used . ' / ' . $quota_month_limit . ' (' . $percent_month . '%)'; ?>
                    </span>
                </div>
            </div>
            <div class="form-group">
                <label class="col-sm-3 control-label">Quota Aujourd'hui :</label>
                <div class="col-sm-3">
                    <span id="quota_day_display">
                         <?php echo $quota_day_used . ' / ' . $quota_day_limit . ' (' . $percent_day . '%)'; ?>
                    </span>
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
              <th>{{Nom}}</th>
              <th>{{Type}}</th>
              <th>{{Sous-type}}</th>
              <th>{{Paramètres}}</th>
              <th>{{Actions}}</th>
            </tr>
          </thead>
          <tbody></tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<script>

function refreshQuota() {
  domUtils.ajax({
    type: "POST",
    url: "plugins/apsystemsCloud/core/ajax/apsystemsCloud.ajax.php",
    data: {
      action: "getQuota",
    },
    global: true,
    async: false,
    error: (request, status, error) => handleAjaxError(request, status, error),
    success: (data) => {
      if (data.state !== 'ok') {
        jeedomUtils.showAlert({ message: data.result, level: 'danger' });
      } else {
        const monthSpan = document.getElementById('quota_month_display');
        const daySpan   = document.getElementById('quota_day_display');

        if (monthSpan) monthSpan.textContent = data.result.month;
        if (daySpan)   daySpan.textContent   = data.result.day;

      }
    }
  });
}
 
  // Mise à jour toutes les 60 secondes
  setInterval(refreshQuota, 60000);
</script>

<?php
include_file('desktop', 'apsystemsCloud', 'js', 'apsystemsCloud');
include_file('core', 'plugin.template', 'js');
?>