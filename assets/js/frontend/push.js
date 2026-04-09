window.InSkillRecallPush = (function ($, Utils, Api) {
  let swRegistration = null;
  let pushReadyPromise = null;
  let $appRoot = null;
  let onReadyCallback = null;

  function isPushAvailable() {
    return (
      'serviceWorker' in navigator &&
      'PushManager' in window &&
      'Notification' in window
    );
  }

  function getPermissionState() {
    if (!isPushAvailable()) {
      return 'unsupported';
    }

    return String(Notification.permission || 'default');
  }

  function getStatusContainer() {
    return $('#inskill-notification-gate-status');
  }

  function setAppRoot($root) {
    $appRoot = $root;
  }

  function setReadyCallback(callback) {
    onReadyCallback = typeof callback === 'function' ? callback : null;
  }

  function triggerReady() {
    if (typeof onReadyCallback === 'function') {
      onReadyCallback();
    }
  }

  function renderRoot(html) {
    if ($appRoot && $appRoot.length) {
      $appRoot.html(html);
    }
  }

  function renderStatus(html) {
    const $status = getStatusContainer();
    if ($status.length) {
      $status.html(html || '');
    }
  }

  function renderMessage(message, type) {
    const klass = type === 'success'
      ? 'inskill-notification-success'
      : 'inskill-notification-warning';

    renderStatus(
      '<div class="inskill-notification-message ' + klass + '">' +
        Utils.esc(message) +
      '</div>'
    );
  }

  function renderPending(stepLabel) {
    const message = stepLabel ? ('Activation en cours… ' + stepLabel) : 'Activation en cours…';
    renderMessage(message, 'warning');
  }

  function waitForRegistrationUsable(registration) {
    return new Promise(function (resolve, reject) {
      if (!registration) {
        reject(new Error('missing_registration'));
        return;
      }

      if (registration.active || registration.waiting) {
        resolve(registration);
        return;
      }

      const worker = registration.installing;
      if (!worker) {
        resolve(registration);
        return;
      }

      const timeout = setTimeout(function () {
        reject(new Error('service_worker_timeout'));
      }, 10000);

      worker.addEventListener('statechange', function () {
        if (worker.state === 'activated' || registration.active || registration.waiting) {
          clearTimeout(timeout);
          resolve(registration);
        } else if (worker.state === 'redundant') {
          clearTimeout(timeout);
          reject(new Error('service_worker_redundant'));
        }
      });
    });
  }

  function ensureServiceWorkerReady() {
    if (!isPushAvailable()) {
      return Promise.resolve(null);
    }

    if (swRegistration) {
      return Promise.resolve(swRegistration);
    }

    if (pushReadyPromise) {
      return pushReadyPromise;
    }

    const swUrl = InSkillRecall.swUrl || '';
    if (!swUrl) {
      return Promise.resolve(null);
    }

    pushReadyPromise = Utils.withTimeout(
      navigator.serviceWorker.register(swUrl)
        .then(function (registration) {
          return waitForRegistrationUsable(registration);
        })
        .then(function (registration) {
          swRegistration = registration;
          return registration;
        }),
      12000,
      'service_worker_timeout'
    ).catch(function () {
      return null;
    });

    return pushReadyPromise;
  }

  function saveSubscription(subscription) {
    if (!subscription) {
      return $.Deferred().reject('missing_subscription').promise();
    }

    return Api.savePushSubscription(subscription);
  }

  function getOrCreateSubscription(registration) {
    if (!registration || !InSkillRecall.vapidPublicKey) {
      return Promise.resolve(null);
    }

    return Utils.withTimeout(
      registration.pushManager.getSubscription().then(function (existingSubscription) {
        if (existingSubscription) {
          return existingSubscription;
        }

        return registration.pushManager.subscribe({
          userVisibleOnly: true,
          applicationServerKey: Utils.urlBase64ToUint8Array(InSkillRecall.vapidPublicKey)
        });
      }),
      15000,
      'subscribe_timeout'
    );
  }

  function requestNotificationPermission() {
    if (!('Notification' in window)) {
      return Promise.resolve('denied');
    }

    if (Notification.permission === 'granted') {
      return Promise.resolve('granted');
    }

    if (Notification.permission === 'denied') {
      return Promise.resolve('denied');
    }

    return Notification.requestPermission();
  }

  function autoSyncExistingSubscription() {
    if (!isPushAvailable()) {
      return;
    }

    if (Notification.permission !== 'granted') {
      return;
    }

    ensureServiceWorkerReady().then(function (registration) {
      if (!registration) {
        return;
      }

      return getOrCreateSubscription(registration).then(function (subscription) {
        if (!subscription) {
          return;
        }

        saveSubscription(subscription).fail(function (err) {
          Utils.log('Auto sync failed', err);
        });
      });
    });
  }

  function verifyCurrentAccess() {
    const permission = getPermissionState();

    if (permission === 'unsupported') {
      return Promise.resolve({
        ready: false,
        state: 'unsupported'
      });
    }

    if (permission === 'default') {
      return Promise.resolve({
        ready: false,
        state: 'default'
      });
    }

    if (permission === 'denied') {
      return Promise.resolve({
        ready: false,
        state: 'denied'
      });
    }

    if (!InSkillRecall.vapidPublicKey) {
      return Promise.resolve({
        ready: false,
        state: 'unsupported',
        detail: 'missing_vapid_public_key'
      });
    }

    return ensureServiceWorkerReady().then(function (registration) {
      if (!registration) {
        return {
          ready: false,
          state: 'error',
          reason: 'sw_timeout'
        };
      }

      return getOrCreateSubscription(registration)
        .then(function (subscription) {
          if (!subscription) {
            return {
              ready: false,
              state: 'error',
              reason: 'subscribe_failed'
            };
          }

          return saveSubscription(subscription)
            .then(function (resp) {
              if (resp && resp.success) {
                return {
                  ready: true,
                  state: 'granted'
                };
              }

              return {
                ready: false,
                state: 'error',
                reason: 'save_failed',
                detail: resp
              };
            })
            .catch(function (err) {
              return {
                ready: false,
                state: 'error',
                reason: 'save_failed',
                detail: err
              };
            });
        })
        .catch(function (error) {
          const msg = String(error && error.message ? error.message : error || '');
          if (msg.indexOf('subscribe_timeout') !== -1) {
            return {
              ready: false,
              state: 'error',
              reason: 'subscribe_failed',
              detail: error
            };
          }

          return {
            ready: false,
            state: 'error',
            reason: 'sw_timeout',
            detail: error
          };
        });
    });
  }

  function renderGateShell(innerHtml) {
    return [
      '<div id="inskill-notification-gate" class="inskill-recall-box inskill-gate-box">',
      innerHtml,
      '<div id="inskill-notification-gate-status"></div>',
      '</div>'
    ].join('');
  }

  function renderDefaultIntroScreen() {
    return renderGateShell([
      '<div class="inskill-gate-header">',
      '<div class="inskill-recall-pill">Notifications obligatoires</div>',
      '<h2 class="inskill-gate-title">Activez les notifications pour accéder à votre espace</h2>',
      '<p class="inskill-gate-text">Les notifications sont obligatoires pour utiliser InSkill Recall.</p>',
      '<p class="inskill-gate-text">Quand vous cliquerez sur le bouton d’activation, votre navigateur affichera une demande d’autorisation.</p>',
      '<p class="inskill-gate-text"><strong>Pour continuer, vous devrez impérativement cliquer sur “Autoriser”.</strong></p>',
      '</div>',
      '<div class="inskill-actions">',
      '<button type="button" class="inskill-btn" id="inskill-notifications-understood">J’ai compris</button>',
      '</div>'
    ].join(''));
  }

  function renderActivationStepScreen() {
    return renderGateShell([
      '<div class="inskill-gate-header">',
      '<div class="inskill-recall-pill">Étape finale</div>',
      '<h2 class="inskill-gate-title">Activez maintenant les notifications</h2>',
      '<p class="inskill-gate-text">Après avoir cliqué ci-dessous, le navigateur va vous demander l’autorisation.</p>',
      '<p class="inskill-gate-text"><strong>Cliquez bien sur “Autoriser”.</strong></p>',
      '</div>',
      '<div class="inskill-actions">',
      '<button type="button" class="inskill-btn" id="inskill-enable-notifications">Activer les notifications</button>',
      '</div>'
    ].join(''));
  }

  function renderDeniedScreen() {
    return renderGateShell([
      '<div class="inskill-gate-header">',
      '<div class="inskill-recall-pill">Notifications requises</div>',
      '<h2 class="inskill-gate-title">Les notifications sont actuellement bloquées</h2>',
      '<p class="inskill-gate-text">Pour accéder à votre espace, vous devez autoriser les notifications pour ce site dans votre navigateur, puis revenir sur cette page.</p>',
      '</div>',
      '<div class="inskill-actions">',
      '<button type="button" class="inskill-btn" id="inskill-verify-notifications">J’ai réactivé → vérifier</button>',
      '</div>'
    ].join(''));
  }

  function renderUnsupportedScreen() {
    return renderGateShell([
      '<div class="inskill-gate-header">',
      '<div class="inskill-recall-pill">Notifications indisponibles</div>',
      '<h2 class="inskill-gate-title">Cet appareil ou ce navigateur n’est pas compatible</h2>',
      '<p class="inskill-gate-text">Les notifications web sont nécessaires pour utiliser cet outil, mais elles ne sont pas disponibles correctement sur cet appareil ou ce navigateur.</p>',
      '</div>'
    ].join(''));
  }

  function renderCheckingScreen() {
    return renderGateShell([
      '<div class="inskill-gate-header">',
      '<div class="inskill-recall-pill">Vérification</div>',
      '<h2 class="inskill-gate-title">Vérification des notifications…</h2>',
      '<p class="inskill-gate-text">Merci de patienter quelques secondes.</p>',
      '</div>'
    ].join(''));
  }

  function showActivationStep() {
    renderRoot(renderActivationStepScreen());
  }

  function renderGateScreen() {
    const permission = getPermissionState();

    if (permission === 'unsupported') {
      renderRoot(renderUnsupportedScreen());
      return;
    }

    if (permission === 'denied') {
      renderRoot(renderDeniedScreen());
      return;
    }

    if (permission === 'granted') {
      verifyGate();
      return;
    }

    renderRoot(renderDefaultIntroScreen());
  }

  function verifyGate(callback) {
    renderRoot(renderCheckingScreen());
    renderPending('vérification…');

    verifyCurrentAccess().then(function (result) {
      if (result && result.ready) {
        triggerReady();
        if (typeof callback === 'function') {
          callback(true, result);
        }
        return;
      }

      if (!result || result.state === 'unsupported') {
        renderRoot(renderUnsupportedScreen());
      } else if (result.state === 'denied') {
        renderRoot(renderDeniedScreen());
      } else if (result.state === 'default') {
        renderRoot(renderDefaultIntroScreen());
      } else {
        renderRoot(renderDeniedScreen());
        renderMessage('Les notifications n’ont pas encore pu être validées sur cet appareil.', 'warning');
      }

      if (typeof callback === 'function') {
        callback(false, result || null);
      }
    });
  }

  function activateNotificationsFromClick(onSuccess) {
    if (!isPushAvailable()) {
      renderMessage(
        InSkillRecall.labels.notificationsUnsupported || 'Ce navigateur ne prend pas correctement en charge les notifications.',
        'warning'
      );
      return;
    }

    if (!InSkillRecall.vapidPublicKey) {
      renderMessage('La configuration des notifications du site est incomplète.', 'warning');
      return;
    }

    renderPending('préparation…');

    ensureServiceWorkerReady()
      .then(function (registration) {
        if (!registration) {
          renderMessage('Le service worker ne répond pas. Rechargez la page puis réessayez.', 'warning');
          throw new Error('service_worker_not_ready');
        }

        renderPending('autorisation navigateur…');
        return requestNotificationPermission().then(function (permission) {
          if (permission !== 'granted') {
            if (permission === 'denied') {
              renderRoot(renderDeniedScreen());
            } else {
              renderMessage('L’autorisation n’a pas été accordée. Cliquez sur “Autoriser” pour continuer.', 'warning');
            }
            throw new Error('permission_not_granted');
          }

          renderPending('abonnement de l’appareil…');
          return getOrCreateSubscription(registration);
        });
      })
      .then(function (subscription) {
        if (!subscription) {
          renderMessage('Impossible d’activer les notifications sur cet appareil.', 'warning');
          throw new Error('subscription_missing');
        }

        renderPending('enregistrement sur le site…');

        saveSubscription(subscription)
          .done(function (resp) {
            if (resp && resp.success) {
              if (typeof onSuccess === 'function') {
                onSuccess(resp);
              } else {
                triggerReady();
              }
            } else {
              renderMessage('Les notifications ont été autorisées mais l’enregistrement de cet appareil a échoué.', 'warning');
            }
          })
          .fail(function () {
            renderMessage('Les notifications ont été autorisées mais l’enregistrement de cet appareil a échoué.', 'warning');
          });
      })
      .catch(function (error) {
        const msg = String(error && error.message ? error.message : error || '');

        if (
          msg === 'permission_not_granted' ||
          msg === 'subscription_missing' ||
          msg === 'service_worker_not_ready'
        ) {
          return;
        }

        renderMessage('Impossible d’activer les notifications sur cet appareil.', 'warning');
      });
  }

  function init($root, onReady) {
    setAppRoot($root);
    setReadyCallback(onReady);
    renderGateScreen();
  }

  return {
    init: init,
    isPushAvailable: isPushAvailable,
    getPermissionState: getPermissionState,
    showActivationStep: showActivationStep,
    renderGateScreen: renderGateScreen,
    verifyGate: verifyGate,
    activateNotificationsFromClick: activateNotificationsFromClick,
    autoSyncExistingSubscription: autoSyncExistingSubscription
  };
})(jQuery, window.InSkillRecallUtils, window.InSkillRecallApi);