(function ($, Api, Push, Preferences, Session, Utils) {
  function app() {
    const $app = $('#inskill-recall-app');
    if (!$app.length) {
      return;
    }

    let state = null;

    function bindGlobalHandlers() {
      $(document).off('click.inskillStart', '.inskill-open-queue');
      $(document).on('click.inskillStart', '.inskill-open-queue', function (e) {
        e.preventDefault();
        const groupId = parseInt($(this).data('group-id'), 10);
        Session.renderQueue(state, $app, groupId, afterRender);
      });

      $(document).off('click.inskillNotificationsUnderstood', '#inskill-notifications-understood');
      $(document).on('click.inskillNotificationsUnderstood', '#inskill-notifications-understood', function (e) {
        e.preventDefault();
        Push.showActivationStep();
      });

      $(document).off('click.inskillEnableNotifications', '#inskill-enable-notifications');
      $(document).on('click.inskillEnableNotifications', '#inskill-enable-notifications', function (e) {
        e.preventDefault();
        Push.activateNotificationsFromClick(function () {
          loadDashboard();
        });
      });

      $(document).off('click.inskillVerifyNotifications', '#inskill-verify-notifications');
      $(document).on('click.inskillVerifyNotifications', '#inskill-verify-notifications', function (e) {
        e.preventDefault();
        Push.verifyGate(function (isReady) {
          if (isReady) {
            loadDashboard();
          }
        });
      });

      $(document).off('click.inskillRetryNotifications', '#inskill-retry-notifications');
      $(document).on('click.inskillRetryNotifications', '#inskill-retry-notifications', function (e) {
        e.preventDefault();
        Push.renderGateScreen();
      });

      Preferences.bindToggle();
      Preferences.bindForm(state, function () {});
    }

    function afterRender() {
      bindGlobalHandlers();
      Push.autoSyncExistingSubscription();
    }

    function renderLoadError() {
      $app.html('<div class="inskill-recall-box">Erreur de chargement.</div>');
    }

    function loadDashboard() {
      Api.getDashboard()
        .done(function (resp) {
          if (!resp || !resp.success) {
            renderLoadError();
            return;
          }

          state = resp.data;
          Session.renderDashboard(state, $app);
          afterRender();
        })
        .fail(function () {
          renderLoadError();
        });
    }

    function boot() {
      Push.init($app, function () {
        loadDashboard();
      });
      bindGlobalHandlers();
    }

    boot();
  }

  $(app);
})(jQuery, window.InSkillRecallApi, window.InSkillRecallPush, window.InSkillRecallPreferences, window.InSkillRecallSession, window.InSkillRecallUtils);