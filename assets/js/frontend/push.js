window.InSkillRecallPush = (function ($, Utils, Api) {
  let swRegistration = null;
  let pushReadyPromise = null;

  function isPushAvailable() {
    return (
      'serviceWorker' in navigator &&
      'PushManager' in window &&
      'Notification' in window
    );
  }

  function renderNotificationStatus(html) {
    const $box = $('#inskill-notification-box');
    if ($box.length) {
      $box.find('.inskill-notification-status').html(html);
    }
  }

  function renderNotificationHelp(reason, details) {
    let message = InSkillRecall.labels.notificationsDenied || 'Les notifications ne sont pas activées.';

    if (reason === 'unsupported') {
      message = InSkillRecall.labels.notificationsUnsupported || 'Ce navigateur ne prend pas correctement en charge les notifications.';
    } else if (reason === 'save_failed') {
      message = 'Les notifications ont été autorisées mais l’enregistrement de cet appareil a échoué.';
    } else if (reason === 'subscribe_failed') {
      message = 'Impossible d’activer les notifications sur cet appareil.';
    } else if (reason === 'sw_timeout') {
      message = 'Le service worker ne répond pas. Rechargez la page puis réessayez.';
    }

    if (details) {
      Utils.log('Notification help:', reason, details);
    }

    renderNotificationStatus(
      '<div class="inskill-notification-message inskill-notification-warning">' +
        Utils.esc(message) +
      '</div>'
    );
  }

  function renderNotificationPending(stepLabel) {
    const message = stepLabel ? ('Activation en cours… ' + stepLabel) : 'Activation en cours…';

    renderNotificationStatus(
      '<div class="inskill-notification-message inskill-notification-warning">' +
        Utils.esc(message) +
      '</div>'
    );
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

  function activateNotificationsFromClick() {
    if (!isPushAvailable()) {
      renderNotificationHelp('unsupported');
      return;
    }

    if (!InSkillRecall.vapidPublicKey) {
      renderNotificationHelp('unsupported', 'missing_vapid_public_key');
      return;
    }

    renderNotificationPending('préparation…');

    ensureServiceWorkerReady()
      .then(function (registration) {
        if (!registration) {
          renderNotificationHelp('sw_timeout');
          throw new Error('service_worker_not_ready');
        }

        renderNotificationPending('autorisation navigateur…');
        return requestNotificationPermission().then(function (permission) {
          if (permission !== 'granted') {
            renderNotificationHelp(permission);
            throw new Error('permission_not_granted');
          }

          renderNotificationPending('abonnement de l’appareil…');
          return getOrCreateSubscription(registration);
        });
      })
      .then(function (subscription) {
        if (!subscription) {
          renderNotificationHelp('subscribe_failed', 'no_subscription_object');
          throw new Error('subscription_missing');
        }

        renderNotificationPending('enregistrement sur le site…');

        saveSubscription(subscription)
          .done(function (resp) {
            if (resp && resp.success) {
              $('#inskill-notification-box').remove();
            } else {
              renderNotificationHelp('save_failed', resp);
            }
          })
          .fail(function (err) {
            renderNotificationHelp('save_failed', err);
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

        renderNotificationHelp('subscribe_failed', error);
      });
  }

  function renderNotificationBox() {
    if (!isPushAvailable()) {
      return '';
    }

    if (Notification.permission === 'granted') {
      return '';
    }

    let statusHtml = '';
    let actionsHtml = '';

    if (Notification.permission === 'denied') {
      statusHtml =
        '<div class="inskill-notification-message inskill-notification-warning">' +
          Utils.esc(InSkillRecall.labels.notificationsDenied || 'Les notifications sont bloquées sur cet appareil.') +
        '</div>';
    } else {
      statusHtml =
        '<p class="inskill-notification-text">' +
          Utils.esc(InSkillRecall.labels.notificationsPrompt || 'Activez les notifications pour être alerté quand vos questions sont disponibles.') +
        '</p>';

      actionsHtml =
        '<button type="button" class="inskill-btn inskill-btn-secondary" id="inskill-enable-notifications">' +
          Utils.esc(InSkillRecall.labels.enableNotifications || 'Activer les notifications') +
        '</button>';
    }

    return (
      '<div id="inskill-notification-box" class="inskill-notification-box">' +
        '<div class="inskill-notification-status">' + statusHtml + '</div>' +
        '<div class="inskill-notification-actions inskill-actions" style="margin-top:12px;">' + actionsHtml + '</div>' +
      '</div>'
    );
  }

  return {
    isPushAvailable: isPushAvailable,
    renderNotificationBox: renderNotificationBox,
    activateNotificationsFromClick: activateNotificationsFromClick,
    autoSyncExistingSubscription: autoSyncExistingSubscription
  };
})(jQuery, window.InSkillRecallUtils, window.InSkillRecallApi);