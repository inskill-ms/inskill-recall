window.InSkillRecallPreferences = (function ($, Utils, Api) {
  function getPreferenceSummary(state) {
    const prefs = state && state.preferences ? state.preferences : null;
    if (!prefs) return '';

    const daysLabel = prefs.allow_weekend
      ? (InSkillRecall.labels.preferencesSummaryEveryday || 'tous les jours')
      : (InSkillRecall.labels.preferencesSummaryWeekdays || 'du lundi au vendredi');

    const timezoneLabel = prefs.timezone_label || prefs.timezone || '';
    const hourLabel = String(prefs.hour).padStart(2, '0') + ':00';

    return (
      '<p class="inskill-settings-summary">' +
        '<strong>' + Utils.esc(InSkillRecall.labels.preferencesSummaryPrefix || 'Réglage actuel :') + '</strong> ' +
        Utils.esc(hourLabel + ' — ' + timezoneLabel + ', ' + daysLabel) +
      '</p>'
    );
  }

  function renderPreferencesBox(state) {
    const prefs = state && state.preferences ? state.preferences : {
      hour: 9,
      minute: 0,
      timezone: 'Africa/Casablanca',
      timezone_label: 'Maroc - Casablanca',
      timezone_options: [
        { value: 'Africa/Casablanca', label: 'Maroc - Casablanca' }
      ],
      allow_weekend: 0,
      time_label: '09:00'
    };

    const hourOptions = [];
    const timezoneOptions = Array.isArray(prefs.timezone_options) && prefs.timezone_options.length
      ? prefs.timezone_options
      : [{ value: 'Africa/Casablanca', label: 'Maroc - Casablanca' }];

    for (let h = 0; h <= 23; h++) {
      const value = String(h).padStart(2, '0');
      hourOptions.push(
        '<option value="' + h + '"' + (h === Number(prefs.hour) ? ' selected' : '') + '>' + value + '</option>'
      );
    }

    const timezoneSelectOptions = timezoneOptions.map(function (option) {
      const value = option && option.value ? String(option.value) : '';
      const label = option && option.label ? String(option.label) : value;
      const selected = value === String(prefs.timezone || '') ? ' selected' : '';
      return '<option value="' + Utils.esc(value) + '"' + selected + '>' + Utils.esc(label) + '</option>';
    });

    const checked = Number(prefs.allow_weekend) === 1 ? ' checked' : '';

    return [
      '<div class="inskill-settings-box">',
      '<button type="button" class="inskill-settings-toggle" id="inskill-preferences-toggle" aria-expanded="false">',
      '<span>' + Utils.esc(InSkillRecall.labels.preferencesTitle || 'Préférences de notification') + '</span>',
      '<span class="inskill-settings-toggle-icon">▾</span>',
      '</button>',
      '<div class="inskill-settings-panel" id="inskill-preferences-panel" hidden>',
      '<p class="inskill-notification-text">' + Utils.esc(InSkillRecall.labels.preferencesIntro || 'Définissez l’heure à laquelle vous souhaitez recevoir vos notifications.') + '</p>',
      getPreferenceSummary(state),
      '<form id="inskill-preferences-form">',
      '<div class="inskill-settings-row">',
      '<label class="inskill-settings-label" for="inskill-notification-timezone">' + Utils.esc(InSkillRecall.labels.preferencesTimezone || 'Fuseau horaire') + '</label>',
      '<select id="inskill-notification-timezone" name="notification_timezone" class="inskill-timezone-select">' + timezoneSelectOptions.join('') + '</select>',
      '</div>',
      '<div class="inskill-settings-row">',
      '<label class="inskill-settings-label" for="inskill-notification-hour">' + Utils.esc(InSkillRecall.labels.preferencesHour || 'Heure souhaitée') + '</label>',
      '<div class="inskill-time-selects">',
      '<select id="inskill-notification-hour" name="notification_hour">' + hourOptions.join('') + '</select>',
      '<span class="inskill-time-separator">:00</span>',
      '</div>',
      '</div>',
      '<div class="inskill-settings-row">',
      '<div class="inskill-switch-row">',
      '<div>',
      '<div class="inskill-settings-label">' + Utils.esc(InSkillRecall.labels.preferencesWeekend || 'Recevoir les notifications le week-end') + '</div>',
      '<p class="inskill-settings-help">' + Utils.esc(InSkillRecall.labels.preferencesWeekendHelp || '') + '</p>',
      '</div>',
      '<label class="inskill-switch">',
      '<input type="checkbox" id="inskill-notifications-weekend" name="notifications_weekend" value="1"' + checked + '>',
      '<span class="inskill-switch-slider"></span>',
      '</label>',
      '</div>',
      '</div>',
      '<div class="inskill-actions">',
      '<button type="submit" class="inskill-btn inskill-btn-secondary" id="inskill-save-preferences">' + Utils.esc(InSkillRecall.labels.preferencesSave || 'Enregistrer mes préférences') + '</button>',
      '</div>',
      '<div id="inskill-preferences-message"></div>',
      '</form>',
      '</div>',
      '</div>'
    ].join('');
  }

  function renderPreferencesMessage(message, isSuccess) {
    const $box = $('#inskill-preferences-message');
    if (!$box.length) return;

    const klass = isSuccess ? 'inskill-notification-success' : 'inskill-notification-warning';
    $box.html(
      '<div class="inskill-notification-message ' + klass + '">' +
        Utils.esc(message) +
      '</div>'
    );
  }

  function savePreferences(state, onSaved) {
    const $button = $('#inskill-save-preferences');
    const hour = parseInt($('#inskill-notification-hour').val(), 10);
    const minute = 0;
    const timezone = $('#inskill-notification-timezone').val() || '';
    const allowWeekend = $('#inskill-notifications-weekend').is(':checked') ? 1 : 0;

    $button.prop('disabled', true);

    Api.savePreferences(hour, minute, allowWeekend, timezone)
      .done(function (resp) {
        if (!resp || !resp.success || !resp.data || !resp.data.preferences) {
          renderPreferencesMessage(
            InSkillRecall.labels.preferencesSaveError || 'Impossible d’enregistrer les préférences.',
            false
          );
          return;
        }

        state.preferences = resp.data.preferences;
        renderPreferencesMessage(
          InSkillRecall.labels.preferencesSaved || 'Préférences enregistrées.',
          true
        );

        $('.inskill-settings-summary').replaceWith(getPreferenceSummary(state));

        if (typeof onSaved === 'function') {
          onSaved(state.preferences);
        }
      })
      .fail(function () {
        renderPreferencesMessage(
          InSkillRecall.labels.preferencesSaveError || 'Impossible d’enregistrer les préférences.',
          false
        );
      })
      .always(function () {
        $button.prop('disabled', false);
      });
  }

  function bindToggle() {
    $(document).off('click.inskillPreferencesToggle', '#inskill-preferences-toggle');
    $(document).on('click.inskillPreferencesToggle', '#inskill-preferences-toggle', function () {
      const $button = $(this);
      const $panel = $('#inskill-preferences-panel');
      const expanded = $button.attr('aria-expanded') === 'true';

      $button.attr('aria-expanded', expanded ? 'false' : 'true');
      $panel.prop('hidden', expanded);

      const $icon = $button.find('.inskill-settings-toggle-icon');
      $icon.text(expanded ? '▾' : '▴');
    });
  }

  function bindForm(state, onSaved) {
    $(document).off('submit.inskillPreferencesForm', '#inskill-preferences-form');
    $(document).on('submit.inskillPreferencesForm', '#inskill-preferences-form', function (e) {
      e.preventDefault();
      savePreferences(state, onSaved);
    });
  }

  return {
    renderPreferencesBox: renderPreferencesBox,
    bindToggle: bindToggle,
    bindForm: bindForm
  };
})(jQuery, window.InSkillRecallUtils, window.InSkillRecallApi);