<?php

if (!isConnect()) {
    throw new Exception(__('401 - Accès non autorisé', __FILE__));
}

$plugin = plugin::byId('apsystemsCloud');
?>

<div class="col-lg-12 col-md-12 col-sm-12">
  <legend><i class="fas fa-cog"></i> {{Configuration APsystems Cloud v2}}</legend>

  <form class="form-horizontal">

    <!-- Identifiants -->
    <div class="form-group">
      <label class="col-sm-3 control-label">{{app_id}}</label>
      <div class="col-sm-3">
       <input type="text" class="configKey form-control" data-l1key="app_id" />

      </div>
    </div>

    <div class="form-group">
      <label class="col-sm-3 control-label">{{app_secret}}</label>
      <div class="col-sm-3">
        <input type="password" class="configKey form-control" data-l1key="app_secret" />
      </div>
    </div>

   

    <!-- Quota -->
    <div class="form-group">
      <label class="col-sm-3 control-label">{{Quota mensuel maximum}}</label>
      <div class="col-sm-3">
        <input type="number" class="configKey form-control" data-l1key="apsystemsCloud_api_quota_month" min="100" max="1000" />
      </div>
      <div class="col-sm-6">
        <span class="help-block">
          {{APsystems limite à 1000 requêtes/mois. Vous pouvez réduire pour plus de sécurité.}}
        </span>
      </div>
    </div>

    <!-- Bouton test -->
    <div class="form-group">
      <label class="col-sm-3 control-label"></label>
      <div class="col-sm-3">
        <a class="btn btn-primary" id="apsystemsCloud_testConnection">
          <i class="fas fa-plug"></i> {{Tester la connexion}}
        </a>
      </div>
    </div>

    <!-- Quota actuel -->
    <div class="form-group">
      <label class="col-sm-3 control-label">{{Quota consommé ce mois}}</label>
      <div class="col-sm-3">
        <?php
        $quota = config::byKey('apsystemsCloud_quota', 'apsystemsCloud', '{"month":"--","count":0}');
        $quota = json_decode($quota, true);
        ?>
        <span class="label label-info">
          <?php echo $quota['count']; ?> / <?php echo config::byKey('apsystemsCloud_api_quota_month', 'apsystemsCloud', 1000); ?>
        </span>
      </div>
    </div>

  </form>
</div>

<script>
$('#apsystemsCloud_testConnection').on('click', function () {
  var app_id = $('.configKey[data-l1key=app_id]').val();
  var app_secret = $('.configKey[data-l1key=app_secret]').val();

  $.ajax({
    type: "POST",
    url: "plugins/apsystemsCloud/core/ajax/apsystemsCloud.ajax.php",
    data: {
      action: "testConnection",
      app_id: app_id,
      app_secret: app_secret
    },
    dataType: 'json',
    error: function (request, status, error) {
      $('#div_alert').showAlert({message: error, level: 'danger'});
    },
    success: function (data) {
      if (data.state != 'ok') {
        $('#div_alert').showAlert({message: data.result, level: 'danger'});
        return;
      }
      $('#div_alert').showAlert({message: data.result, level: 'success'});
    }
  });
});
</script>