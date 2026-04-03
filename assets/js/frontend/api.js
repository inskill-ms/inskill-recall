window.InSkillRecallApi = (function ($) {
  function request(action, data) {
    return $.post(
      InSkillRecall.ajaxUrl,
      Object.assign(
        {
          action: action,
          nonce: InSkillRecall.nonce,
          token: InSkillRecall.token || ''
        },
        data || {}
      )
    );
  }

  function getDashboard() {
    return request('inskill_recall_get_dashboard');
  }

  function getQuestion(occurrenceId) {
    return request('inskill_recall_get_question', {
      occurrence_id: occurrenceId
    });
  }

  function submitAnswer(occurrenceId, selectedChoiceIds) {
    return request('inskill_recall_submit_answer_v2', {
      occurrence_id: occurrenceId,
      selected_choice_ids: selectedChoiceIds
    });
  }

  function savePushSubscription(subscription) {
    return request('inskill_recall_save_push_subscription', {
      subscription: JSON.stringify(subscription)
    });
  }

  function savePreferences(hour, minute, allowWeekend, timezone) {
    return request('inskill_recall_save_preferences', {
      notification_hour: hour,
      notification_minute: minute,
      notification_timezone: timezone || '',
      notifications_weekend: allowWeekend ? 1 : 0
    });
  }

  return {
    request: request,
    getDashboard: getDashboard,
    getQuestion: getQuestion,
    submitAnswer: submitAnswer,
    savePushSubscription: savePushSubscription,
    savePreferences: savePreferences
  };
})(jQuery);