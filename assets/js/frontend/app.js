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

      $(document).off('click.inskillEnableNotifications', '#inskill-enable-notifications');
      $(document).on('click.inskillEnableNotifications', '#inskill-enable-notifications', function (e) {
        e.preventDefault();
        Push.activateNotificationsFromClick();
      });

      Preferences.bindToggle();
      Preferences.bindForm(state, function () {});
    }

    function afterRender() {
      bindGlobalHandlers();
      Push.autoSyncExistingSubscription();
    }

    function load() {
      Api.getDashboard()
        .done(function (resp) {
          if (!resp || !resp.success) {
            $app.html('<div class="inskill-recall-box">Erreur de chargement.</div>');
            return;
          }

          state = resp.data;
          Session.renderDashboard(state, $app);
          afterRender();
        })
        .fail(function () {
          $app.html('<div class="inskill-recall-box">Erreur de chargement.</div>');
        });
    }

    load();
  }

  $(app);
})(jQuery, window.InSkillRecallApi, window.InSkillRecallPush, window.InSkillRecallPreferences, window.InSkillRecallSession, window.InSkillRecallUtils);