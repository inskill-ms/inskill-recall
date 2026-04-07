window.InSkillRecallUtils = (function () {
  function esc(str) {
    return String(str || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function log() {
    try {
      console.log.apply(console, ['[InSkill Recall]'].concat([].slice.call(arguments)));
    } catch (e) {}
  }

  function withTimeout(promise, ms, label) {
    return new Promise(function (resolve, reject) {
      let settled = false;

      const timer = setTimeout(function () {
        if (settled) return;
        settled = true;
        reject(new Error(label || 'timeout'));
      }, ms);

      Promise.resolve(promise)
        .then(function (value) {
          if (settled) return;
          settled = true;
          clearTimeout(timer);
          resolve(value);
        })
        .catch(function (error) {
          if (settled) return;
          settled = true;
          clearTimeout(timer);
          reject(error);
        });
    });
  }

  function urlBase64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const rawData = window.atob(base64);
    const outputArray = new Uint8Array(rawData.length);

    for (let i = 0; i < rawData.length; ++i) {
      outputArray[i] = rawData.charCodeAt(i);
    }

    return outputArray;
  }

  function truncate(str, maxLength) {
    const value = String(str || '');
    const max = Number(maxLength || 120);

    if (value.length <= max) {
      return value;
    }

    return value.substring(0, max - 1) + '…';
  }

  function toOccurrenceStatusLabel(status) {
    switch (String(status || '')) {
      case 'answered_correct':
        return 'Bonne réponse';
      case 'answered_incorrect':
        return 'Mauvaise réponse';
      case 'unanswered':
        return 'Non répondu';
      default:
        return 'À faire';
    }
  }

  function toOccurrenceStatusIcon(status) {
    switch (String(status || '')) {
      case 'answered_correct':
        return '✔️';
      case 'answered_incorrect':
        return '❌';
      case 'unanswered':
        return '⏳';
      default:
        return '•';
    }
  }

  return {
    esc: esc,
    log: log,
    withTimeout: withTimeout,
    urlBase64ToUint8Array: urlBase64ToUint8Array,
    truncate: truncate,
    toOccurrenceStatusLabel: toOccurrenceStatusLabel,
    toOccurrenceStatusIcon: toOccurrenceStatusIcon
  };
})();