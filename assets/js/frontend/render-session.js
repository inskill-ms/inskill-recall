window.InSkillRecallSession = (function ($, Utils, Api, Push, Preferences) {
  function getGroupById(state, groupId) {
    if (!state || !Array.isArray(state.groups)) {
      return null;
    }

    for (let i = 0; i < state.groups.length; i++) {
      if (Number(state.groups[i].group.id) === Number(groupId)) {
        return state.groups[i];
      }
    }

    return null;
  }

  function getQuestionNumberMap(groupPayload) {
    const map = {};
    const rows = groupPayload && Array.isArray(groupPayload.question_index) ? groupPayload.question_index : [];

    rows.forEach(function (row, index) {
      map[String(row.question_id)] = index + 1;
    });

    return map;
  }

  function getQuestionMeta(groupPayload, questionId) {
    const rows = groupPayload && Array.isArray(groupPayload.question_index) ? groupPayload.question_index : [];
    const targetId = Number(questionId);

    for (let i = 0; i < rows.length; i++) {
      if (Number(rows[i].question_id) === targetId) {
        return {
          displayNumber: i + 1,
          questionText: rows[i].question_text || '',
          internalLabel: rows[i].internal_label || rows[i].label || ''
        };
      }
    }

    return {
      displayNumber: targetId,
      questionText: '',
      internalLabel: ''
    };
  }

  function reloadState(callback) {
    Api.getDashboard()
      .done(function (resp) {
        if (!resp || !resp.success || !resp.data) {
          callback(null);
          return;
        }
        callback(resp.data);
      })
      .fail(function () {
        callback(null);
      });
  }

  function renderStatusBadge(status, label) {
    const normalized = status || 'active';
    return '<span class="inskill-status-badge status-' + Utils.esc(normalized) + '">' + Utils.esc(label || normalized) + '</span>';
  }

  function renderDashboard(state, $app) {
    const user = state && state.user ? state.user : {};
    const groups = state && Array.isArray(state.groups) ? state.groups : [];
    const prefsState = state || {};

    if (!groups.length) {
      const htmlEmpty = [
        '<div class="inskill-recall-box">',
        '<h2>' + Utils.esc(InSkillRecall.labels.empty) + '</h2>',
        Push.renderNotificationBox(),
        Preferences.renderPreferencesBox(prefsState),
        '</div>'
      ].join('');

      $app.html(htmlEmpty);
      return;
    }

    const blocks = groups.map(function (groupPayload) {
      const group = groupPayload.group || {};
      const summary = groupPayload.summary || {};
      const queue = Array.isArray(groupPayload.queue) ? groupPayload.queue : [];
      const history = Array.isArray(groupPayload.history) ? groupPayload.history : [];
      const upcoming = Array.isArray(groupPayload.upcoming) ? groupPayload.upcoming : [];
      const questionIndex = Array.isArray(groupPayload.question_index) ? groupPayload.question_index : [];
      const leaderboard = Array.isArray(groupPayload.leaderboard) ? groupPayload.leaderboard : [];

      return [
        '<div class="inskill-recall-box">',
        '<div class="inskill-recall-pill">' + Utils.esc(group.name || '') + '</div>',
        '<div class="inskill-dashboard-header">',
        '<div>',
        '<h2>' + Utils.esc(user.first_name ? ('Bonjour ' + user.first_name) : 'Bonjour') + '</h2>',
        '<p class="inskill-recall-subtext">' + Utils.esc(summary.message || '') + '</p>',
        '</div>',
        '<div>' + renderStatusBadge(summary.participant_status, summary.participant_status_label) + '</div>',
        '</div>',

        '<div class="inskill-summary-grid">',
        '<div><strong>' + Utils.esc(summary.score_total || 0) + '</strong><span>Score</span></div>',
        '<div><strong>' + Utils.esc(summary.rank || '—') + '</strong><span>Rang</span></div>',
        '<div><strong>' + Utils.esc((summary.mastered_questions || 0) + ' / ' + (summary.total_questions || 0)) + '</strong><span>Maîtrisées</span></div>',
        '<div><strong>' + Utils.esc(summary.today_correct || 0) + '</strong><span>Bonnes aujourd’hui</span></div>',
        '<div><strong>' + Utils.esc(summary.today_incorrect || 0) + '</strong><span>Erreurs</span></div>',
        '<div><strong>' + Utils.esc(summary.today_remaining || 0) + '</strong><span>Restantes</span></div>',
        '</div>',

        Push.renderNotificationBox(),

        '<div class="inskill-actions">',
        '<button class="inskill-btn inskill-open-queue" data-group-id="' + Utils.esc(group.id) + '">' + Utils.esc(summary.action_label || InSkillRecall.labels.start) + '</button>',
        '</div>',

        renderQueuePreview(groupPayload, queue),
        renderHistory(groupPayload, history),
        renderUpcoming(groupPayload, upcoming),
        renderQuestionIndex(questionIndex),
        renderLeaderboard(leaderboard),
        Preferences.renderPreferencesBox(prefsState),

        '</div>'
      ].join('');
    });

    $app.html(blocks.join(''));
  }

  function renderQueuePreview(groupPayload, queue) {
    const rows = Array.isArray(queue) ? queue : [];

    let html = '<div class="inskill-section"><h3>' + Utils.esc(InSkillRecall.labels.questionListTitle || 'Questions du jour') + '</h3>';

    if (!rows.length) {
      html += '<p class="inskill-empty-line">Aucune question à afficher.</p></div>';
      return html;
    }

    html += '<div class="inskill-list">';
    rows.forEach(function (row) {
      const meta = getQuestionMeta(groupPayload, row.question_id);

      html += '<div class="inskill-list-row">';
      html += '<div>';
      html += '<strong>Q' + Utils.esc(meta.displayNumber) + '</strong> — ' + Utils.esc(row.display_level || '');
      html += '<div class="inskill-list-subtext">';
      html += Utils.esc(meta.questionText || '');
      html += '</div>';
      html += '</div>';
      html += '<div>' + Utils.esc(Utils.toOccurrenceStatusLabel(row.status)) + '</div>';
      html += '</div>';
    });
    html += '</div></div>';

    return html;
  }

  function renderHistory(groupPayload, history) {
    const rows = Array.isArray(history) ? history : [];

    let html = '<div class="inskill-section"><h3>' + Utils.esc(InSkillRecall.labels.calendarTitle || 'Historique') + '</h3>';

    if (!rows.length) {
      html += '<p class="inskill-empty-line">Aucun historique.</p></div>';
      return html;
    }

    html += '<div class="inskill-list">';
    rows.forEach(function (row) {
      const meta = getQuestionMeta(groupPayload, row.question_id);

      html += '<div class="inskill-list-row">';
      html += '<div>' + Utils.esc(row.scheduled_date) + '</div>';
      html += '<div>Q' + Utils.esc(meta.displayNumber) + ' — ' + Utils.esc(row.display_level || '') + ' — ' + Utils.esc(Utils.toOccurrenceStatusIcon(row.status)) + '</div>';
      html += '</div>';
    });
    html += '</div></div>';

    return html;
  }

  function renderUpcoming(groupPayload, upcoming) {
    const rows = Array.isArray(upcoming) ? upcoming : [];

    let html = '<div class="inskill-section"><h3>' + Utils.esc(InSkillRecall.labels.upcomingTitle || 'Prochains rappels') + '</h3>';

    if (!rows.length) {
      html += '<p class="inskill-empty-line">Aucun rappel futur.</p></div>';
      return html;
    }

    html += '<div class="inskill-list">';
    rows.forEach(function (row) {
      const meta = getQuestionMeta(groupPayload, row.question_id);

      html += '<div class="inskill-list-row">';
      html += '<div>Q' + Utils.esc(meta.displayNumber) + ' (' + Utils.esc(row.current_level || '') + ')</div>';
      html += '<div>' + Utils.esc(row.scheduled_date) + '</div>';
      html += '</div>';
    });
    html += '</div></div>';

    return html;
  }

  function renderQuestionIndex(questionIndex) {
    const rows = Array.isArray(questionIndex) ? questionIndex : [];

    let html = '<div class="inskill-section"><h3>' + Utils.esc(InSkillRecall.labels.indexTitle || 'Index des questions') + '</h3>';

    if (!rows.length) {
      html += '<p class="inskill-empty-line">Aucune question.</p></div>';
      return html;
    }

    html += '<div class="inskill-list">';
    rows.forEach(function (row, index) {
      html += '<div class="inskill-list-row inskill-list-row-index">';
      html += '<div><strong>Q' + Utils.esc(index + 1) + '</strong></div>';
      html += '<div class="inskill-index-text">' + Utils.esc(Utils.truncate(row.question_text || '', 120)) + '</div>';
      html += '</div>';
    });
    html += '</div></div>';

    return html;
  }

  function renderLeaderboard(leaderboard) {
    const rows = Array.isArray(leaderboard) ? leaderboard : [];

    let html = '<div class="inskill-section"><h3>' + Utils.esc(InSkillRecall.labels.leaderboardTitle || 'Classement') + '</h3>';

    if (!rows.length) {
      html += '<p class="inskill-empty-line">Aucun classement à afficher.</p></div>';
      return html;
    }

    html += '<div class="inskill-list">';
    rows.forEach(function (row) {
      html += '<div class="inskill-list-row">';
      html += '<div><strong>' + Utils.esc(row.rank) + '.</strong> ' + Utils.esc(row.name) + '</div>';
      html += '<div>' + Utils.esc(row.score_total + ' pts — ' + row.mastered_questions + ' / ' + row.total_questions) + ' ' + renderStatusBadge(row.participant_status, row.participant_status_label) + '</div>';
      html += '</div>';
    });
    html += '</div></div>';

    return html;
  }

  function renderQueue(state, $app, groupId, onDone) {
    const groupPayload = getGroupById(state, groupId);
    if (!groupPayload) {
      renderDashboard(state, $app);
      if (typeof onDone === 'function') {
        onDone();
      }
      return;
    }

    const queue = Array.isArray(groupPayload.queue) ? groupPayload.queue : [];
    const pendingRows = queue.filter(function (row) {
      return row.status === 'pending';
    });

    let html = '<div class="inskill-recall-box">';
    html += '<div class="inskill-recall-meta"><span>' + Utils.esc(groupPayload.group.name) + '</span><span>' + Utils.esc(pendingRows.length) + ' question(s) à traiter</span></div>';
    html += '<h2>' + Utils.esc(InSkillRecall.labels.questionListTitle || 'Questions du jour') + '</h2>';

    if (!pendingRows.length) {
      html += '<p class="inskill-empty-line">' + Utils.esc(groupPayload.summary.message || '') + '</p>';
      html += '<div class="inskill-actions"><button class="inskill-btn inskill-btn-secondary" id="inskill-back-dashboard">Retour</button></div>';
      html += '</div>';

      $app.html(html);

      $('#inskill-back-dashboard').off('click').on('click', function (e) {
        e.preventDefault();
        renderDashboard(state, $app);
        if (typeof onDone === 'function') {
          onDone();
        }
      });
      return;
    }

    html += '<div class="inskill-list">';
    pendingRows.forEach(function (row, index) {
      const meta = getQuestionMeta(groupPayload, row.question_id);

      html += '<div class="inskill-list-row">';
      html += '<div>';
      html += '<strong>Q' + Utils.esc(meta.displayNumber) + '</strong> — ' + Utils.esc(row.display_level || '');
      html += '<div class="inskill-list-subtext">';
      html += Utils.esc(meta.questionText || '');
      html += '</div>';
      html += '</div>';
      html += '<div><button class="inskill-btn inskill-btn-secondary inskill-open-question" data-group-id="' + Utils.esc(groupId) + '" data-occurrence-id="' + Utils.esc(row.occurrence_id) + '" data-index="' + Utils.esc(index) + '">Ouvrir</button></div>';
      html += '</div>';
    });
    html += '</div>';

    html += '<div class="inskill-actions"><button class="inskill-btn inskill-btn-secondary" id="inskill-back-dashboard">Retour</button></div>';
    html += '</div>';

    $app.html(html);

    $('#inskill-back-dashboard').off('click').on('click', function (e) {
      e.preventDefault();
      renderDashboard(state, $app);
      if (typeof onDone === 'function') {
        onDone();
      }
    });

    $('.inskill-open-question').off('click').on('click', function (e) {
      e.preventDefault();
      const occurrenceId = parseInt($(this).data('occurrence-id'), 10);
      const index = parseInt($(this).data('index'), 10);
      renderQuestion(state, $app, groupId, occurrenceId, index, onDone);
    });
  }

  function renderQuestion(state, $app, groupId, occurrenceId, queueIndex, onDone) {
    Api.getQuestion(occurrenceId)
      .done(function (resp) {
        if (!resp || !resp.success || !resp.data) {
          $app.html('<div class="inskill-recall-box">Erreur de chargement.</div>');
          return;
        }

        const item = resp.data;
        const choices = Array.isArray(item.choices) ? item.choices : [];
        const groupPayload = getGroupById(state, groupId);
        const meta = getQuestionMeta(groupPayload, item.question_id);

        let html = '';
        html += '<div class="inskill-recall-box">';
        html += '<div class="inskill-recall-meta"><span>' + Utils.esc(item.display_level || '') + '</span><span>Q' + Utils.esc(meta.displayNumber) + '</span></div>';
        html += '<div class="inskill-question">' + item.question_text + '</div>';

        if (item.image_url) {
          html += '<div class="inskill-image"><img src="' + Utils.esc(item.image_url) + '" alt=""></div>';
        }

        html += '<form id="inskill-answer-form">';
        choices.forEach(function (choice) {
          const checked = choice.selected ? ' checked' : '';
          const disabled = choice.disabled ? ' disabled' : '';
          const inputType = item.question_type === 'qcu' ? 'radio' : 'checkbox';
          const inputName = item.question_type === 'qcu' ? 'choice_ids_single' : 'choice_ids[]';

          html += '<label class="inskill-choice">';
          html += '<input type="' + inputType + '" name="' + inputName + '" value="' + choice.id + '"' + checked + disabled + '>';
          html += '<span>' + choice.text + '</span>';
          html += '</label>';
        });

        if (item.status === 'pending') {
          html += '<div class="inskill-actions">';
          html += '<button type="submit" class="inskill-btn">Valider</button>';
          html += '<button type="button" class="inskill-btn inskill-btn-secondary" id="inskill-back-queue">Retour à la liste</button>';
          html += '</div>';
        } else {
          html += renderFeedback(item);
        }

        html += '</form>';
        html += '</div>';

        $app.html(html);

        $('#inskill-back-queue').off('click').on('click', function (e) {
          e.preventDefault();
          renderQueue(state, $app, groupId, onDone);
        });

        if (item.status === 'pending') {
          $('#inskill-answer-form').off('submit').on('submit', function (e) {
            e.preventDefault();
            submitAnswer(state, $app, groupId, occurrenceId, queueIndex, onDone);
          });
        } else {
          bindAfterAnswer(state, $app, groupId, onDone);
        }
      })
      .fail(function () {
        $app.html('<div class="inskill-recall-box">Erreur de chargement.</div>');
      });
  }

  function renderFeedback(item) {
    let correctSelected = true;
    const choices = Array.isArray(item.choices) ? item.choices : [];

    choices.forEach(function (choice) {
      if ((choice.selected && !choice.is_correct) || (!choice.selected && choice.is_correct)) {
        correctSelected = false;
      }
    });

    let html = '<div class="inskill-recall-feedback ' + (correctSelected ? 'correct' : 'incorrect') + '">';
    html += '<h3>' + Utils.esc(correctSelected ? InSkillRecall.labels.correct : InSkillRecall.labels.incorrect) + '</h3>';
    html += '<div class="inskill-feedback-choices">';

    choices.forEach(function (choice) {
      const classes = ['inskill-choice-result'];

      if (choice.is_correct) {
        classes.push('is-correct');
      }

      if (choice.selected) {
        classes.push('is-selected');
      }

      html += '<div class="' + classes.join(' ') + '">' + choice.text + '</div>';
    });

    html += '</div>';

    if (item.explanation) {
      html += '<div class="inskill-explanation"><strong>Explication</strong><div>' + item.explanation + '</div></div>';
    }

    html += '<div class="inskill-actions">';
    html += '<button class="inskill-btn" id="inskill-next-after-answer">' + Utils.esc(InSkillRecall.labels.next || 'Question suivante') + '</button>';
    html += '<button class="inskill-btn inskill-btn-secondary" id="inskill-back-queue-after-answer">' + Utils.esc(InSkillRecall.labels.backToList || 'Retour à la liste') + '</button>';
    html += '</div>';
    html += '</div>';

    return html;
  }

  function bindAfterAnswer(state, $app, groupId, onDone) {
    $('#inskill-next-after-answer').off('click').on('click', function (e) {
      e.preventDefault();
      reloadState(function (freshState) {
        if (!freshState) {
          window.location.reload();
          return;
        }

        renderQueue(freshState, $app, groupId, onDone);
        if (typeof onDone === 'function') {
          onDone();
        }
      });
    });

    $('#inskill-back-queue-after-answer').off('click').on('click', function (e) {
      e.preventDefault();
      reloadState(function (freshState) {
        if (!freshState) {
          window.location.reload();
          return;
        }

        renderQueue(freshState, $app, groupId, onDone);
        if (typeof onDone === 'function') {
          onDone();
        }
      });
    });
  }

  function submitAnswer(state, $app, groupId, occurrenceId, queueIndex, onDone) {
    const values = [];

    $('#inskill-answer-form input:checked').each(function () {
      values.push(parseInt($(this).val(), 10));
    });

    if (!values.length) {
      alert(InSkillRecall.labels.noSelection || 'Veuillez sélectionner au moins une réponse.');
      return;
    }

    const groupPayload = getGroupById(state, groupId);
    const queue = groupPayload && Array.isArray(groupPayload.queue) ? groupPayload.queue : [];
    const currentRow = queue[queueIndex] || null;
    const level = currentRow && currentRow.display_level ? String(currentRow.display_level) : 'nv0';

    if (level !== 'nv0') {
      const confirmed = window.confirm(
        (InSkillRecall.labels.confirmAnswerText || 'Confirmez-vous cette réponse ?')
      );

      if (!confirmed) {
        return;
      }
    }

    Api.submitAnswer(occurrenceId, values)
      .done(function (resp) {
        if (!resp || !resp.success || !resp.data || !resp.data.question) {
          alert((resp && resp.data && resp.data.message) ? resp.data.message : (InSkillRecall.labels.saveError || 'Erreur lors de l’enregistrement.'));
          return;
        }

        const item = resp.data.question;
        const currentGroupPayload = getGroupById(state, groupId);
        const meta = getQuestionMeta(currentGroupPayload, item.question_id);

        let html = '';
        html += '<div class="inskill-recall-box">';
        html += '<div class="inskill-recall-meta"><span>' + Utils.esc(item.display_level || '') + '</span><span>Q' + Utils.esc(meta.displayNumber) + '</span></div>';
        html += '<div class="inskill-question">' + item.question_text + '</div>';

        if (item.image_url) {
          html += '<div class="inskill-image"><img src="' + Utils.esc(item.image_url) + '" alt=""></div>';
        }

        html += renderFeedback(item);
        html += '</div>';

        $app.html(html);
        bindAfterAnswer(state, $app, groupId, onDone);
      })
      .fail(function () {
        alert(InSkillRecall.labels.saveError || 'Erreur lors de l’enregistrement.');
      });
  }

  return {
    renderDashboard: renderDashboard,
    renderQueue: renderQueue,
    renderQuestion: renderQuestion
  };
})(jQuery, window.InSkillRecallUtils, window.InSkillRecallApi, window.InSkillRecallPush, window.InSkillRecallPreferences);