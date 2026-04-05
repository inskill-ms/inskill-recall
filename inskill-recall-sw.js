self.addEventListener('install', function () {
  self.skipWaiting();
});

self.addEventListener('activate', function (event) {
  event.waitUntil(self.clients.claim());
});

self.addEventListener('push', function (event) {
  let data = {
    title: 'InSkill Recall',
    body: 'Vos questions sont disponibles.',
    url: '/',
    icon: '',
    badge: '',
    tag: 'inskill-recall'
  };

  if (event.data) {
    try {
      const incoming = event.data.json();
      data = Object.assign(data, incoming || {});
    } catch (e) {
      try {
        data.body = event.data.text();
      } catch (err) {
      }
    }
  }

  const options = {
    body: data.body || '',
    icon: data.icon || '',
    badge: data.badge || '',
    data: {
      url: data.url || '/'
    },
    tag: data.tag || 'inskill-recall',
    renotify: true
  };

  event.waitUntil(
    self.registration.showNotification(data.title || 'InSkill Recall', options)
  );
});

self.addEventListener('notificationclick', function (event) {
  event.notification.close();

  const targetUrl = event.notification.data && event.notification.data.url
    ? event.notification.data.url
    : '/';

  event.waitUntil(
    self.clients.matchAll({
      type: 'window',
      includeUncontrolled: true
    }).then(function (clientList) {
      for (let i = 0; i < clientList.length; i++) {
        const client = clientList[i];

        if ('focus' in client) {
          try {
            client.navigate(targetUrl);
          } catch (e) {
          }
          return client.focus();
        }
      }

      if (self.clients.openWindow) {
        return self.clients.openWindow(targetUrl);
      }
    })
  );
});