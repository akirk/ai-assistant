/* Generic durable storage, scoped to an application and account. */
(function () {
    'use strict';
    window.WpAppStorage = function (config) {
        if (!config.scope || !config.userId) throw new Error('Local storage requires scope and userId.');
        var dbName = config.databaseName || ('wp-app-data:' + config.scope + ':' + config.userId);
        var dbPromise;
        function openDB() {
            if (!dbPromise) dbPromise = new Promise(function (resolve, reject) {
                if (!window.indexedDB) return reject(new Error('Local storage unavailable. Keep your text here and save online.'));
                var request = indexedDB.open(dbName, 2);
                request.onupgradeneeded = function () {
                    ['entries', 'drafts', 'meta', 'records'].forEach(function (name) { if (!request.result.objectStoreNames.contains(name)) request.result.createObjectStore(name, {keyPath: 'id'}); });
                };
                request.onsuccess = function () { request.result.onversionchange = function () { request.result.close(); dbPromise = null; }; resolve(request.result); };
                request.onerror = function () { reject(request.error); };
                request.onblocked = function () { reject(new Error('Local storage is blocked. Close other app tabs and retry.')); };
            });
            return dbPromise;
        }
        function transaction(stores, mode, work) {
            return openDB().then(function (db) {
                return new Promise(function (resolve, reject) {
                    var tx = db.transaction(stores, mode);
                    var value;
                    tx.oncomplete = function () { resolve(value); };
                    tx.onerror = tx.onabort = function () { reject(tx.error || new Error('Local save failed. Your text has not been cleared.')); };
                    try { work(tx, function (result) { value = result; }); }
                    catch (error) { tx.abort(); reject(error); }
                });
            });
        }
        function rows(store) {
            return transaction([store], 'readonly', function (tx, done) {
                tx.objectStore(store).getAll().onsuccess = function (event) { done(event.target.result); };
            });
        }
        return {rows: rows, transaction: transaction, close: async function () { if (dbPromise) { try { (await dbPromise).close(); } catch (_) {} dbPromise = null; } }};
    };
})();

/* Generic named collections using app/account-scoped durable storage. */
(function () {
    'use strict';
    window.WpAppLocalData = function (storage) {
        if (!storage.transaction) storage = new window.WpAppStorage(storage);
        function key(collection, id) { return JSON.stringify([collection, String(id)]); }
        function write(tx, collection, value) { return tx.objectStore('records').put({id: key(collection, value.id), collection: collection, value: value}); }
        return {
            write: write,
            close: function () { if (storage.close) return storage.close(); },
            all: async function (collection) { return (await storage.rows('records')).filter(row => row.collection === collection).map(row => row.value); },
            get: async function (collection, id) { var row = (await storage.rows('records')).find(row => row.id === key(collection, id)); return row && row.value; },
            put: function (collection, value) { return storage.transaction(['records'], 'readwrite', function (tx) { tx.objectStore('records').put({id: key(collection, value.id), collection: collection, value: value}); }); },
            remove: function (collection, id) { return storage.transaction(['records'], 'readwrite', function (tx) { tx.objectStore('records').delete(key(collection, id)); }); },
            replace: function (collection, values) {
                return storage.transaction(['records'], 'readwrite', function (tx) {
                    var store = tx.objectStore('records');
                    store.openCursor().onsuccess = function (event) {
                        var cursor = event.target.result;
                        if (cursor) { if (cursor.value.collection === collection) cursor.delete(); cursor.continue(); }
                        else values.forEach(value => store.put({id: key(collection, value.id), collection: collection, value: value}));
                    };
                });
            }
        };
    };
})();
