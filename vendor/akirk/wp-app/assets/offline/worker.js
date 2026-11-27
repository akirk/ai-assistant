(function(config) {
const cacheName = config.cacheName;
const cachePrefix = config.cachePrefix;
const precache = config.precache || [];
const cacheablePaths = config.cacheablePaths || [];
const cacheableSearchParams = config.cacheableSearchParams || [];

// Only explicitly opted-in apps can cache authenticated page snapshots.
const pageMessage = "wp-app-offline-pages";
const metadataPrefix = new URL("wp-app-cached-page/", config.scopeUrl).href;
function inApp(url) {
    const scope = new URL(config.scopeUrl);
    return url.origin === scope.origin && url.pathname.indexOf(scope.pathname) === 0;
}
function metadataUrl(url) { return metadataPrefix + encodeURIComponent(url); }
function validPage(response) {
    return response && response.ok && !response.redirected &&
        (response.headers.get("Content-Type") || "").includes("text/html") &&
        response.headers.get("X-Wp-App-Offline") === config.cacheIdentity;
}
async function listPages(cache) {
    const pages = [];
    for (const request of await cache.keys()) {
        if (!request.url.startsWith(metadataPrefix)) continue;
        try {
            const record = await (await cache.match(request)).json();
            const page = await cache.match(record.url);
            if (!validPage(page)) continue;
            const assets = await Promise.all(record.assets.map(url => cache.match(url)));
            if (assets.every(Boolean)) pages.push({url: record.url, title: record.title, cachedAt: record.cachedAt});
        } catch (_) { /* Evicted or malformed records are not available pages. */ }
    }
    return pages.sort((a, b) => a.title.localeCompare(b.title));
}
async function pageRequest(data) {
    if (!config.visitedPages || !config.cacheIdentity || data.identity !== config.cacheIdentity) throw new Error("Offline page account changed. Reload online.");
    const cache = await caches.open(cacheName);
    if (data.action === "remember") {
        const url = new URL(data.url);
        if (!inApp(url)) throw new Error("Page is outside this app.");
        const assets = [...new Set(data.assets || [])].map(value => new URL(value));
        if (assets.some(asset => asset.origin !== location.origin)) throw new Error("A required asset cannot be cached.");
        // Keep the last complete snapshot until its replacement is fully cached.
        const response = await fetch(url.href, {credentials: "same-origin", cache: "no-store"});
        if (!validPage(response)) throw new Error("Page could not be cached for this account.");
        await Promise.all(assets.map(async asset => {
            const response = await fetch(asset.href, {credentials: "same-origin", cache: "no-store"});
            if (!cacheResponseAllowed(response) || (response.headers.get("Content-Type") || "").includes("text/html")) throw new Error("A required asset could not be cached.");
            await cache.put(asset.href, response);
        }));
        await cache.put(url.href, response);
        await cache.put(metadataUrl(url.href), new Response(JSON.stringify({
            url: url.href, title: String(data.title || url.pathname), assets: assets.map(asset => asset.href), cachedAt: new Date().toISOString()
        }), {headers: {"Content-Type": "application/json"}}));
    }
    return listPages(cache);
}
self.addEventListener("message", function(event) {
    if (!event.data || event.data.type !== pageMessage) return;
    const port = event.ports && event.ports[0];
    if (!port) return;
    event.waitUntil(pageRequest(event.data).then(pages => port.postMessage({pages: pages})).catch(error => port.postMessage({error: error.message})));
});

function uniqueUrls(items) {
	const seen = {};
	return items.filter(function(url) {
		if (seen[url.href]) {
			return false;
		}
		seen[url.href] = true;
		return true;
	});
}

function isCacheableRequest(url) {
	if (config.cachePolicy === "shell") {
		return precache.some(function(item) { return new URL(item, location.origin).href === url.href; });
	}
	if (url.origin !== location.origin) {
		return false;
	}

	if (cacheablePaths.some(function(path) {
		return path && url.pathname.indexOf(path) === 0;
	})) {
		return true;
	}

	return cacheableSearchParams.some(function(param) {
		return param && url.search.indexOf(param) !== -1;
	});
}

function cacheResponseAllowed(response) {
    return response.ok && !response.redirected && (!config.visitedPages ||
        !(response.headers.get("Content-Type") || "").includes("text/html") || validPage(response));
}

function sendMessage(source, message) {
	if (source && source.postMessage) {
		source.postMessage(message);
	}
}

self.addEventListener("install", function(event) {
	event.waitUntil(
		caches.open(cacheName).then(function(cache) {
			return Promise.all(precache.map(async function(url) {
				const response = await fetch(url, {credentials: "same-origin", cache: "no-store"});
				if (!cacheResponseAllowed(response)) throw new Error("Precache response unavailable or redirected");
				await cache.put(url, response);
			}));
		}).then(function() {
			return self.skipWaiting();
		})
	);
});

self.addEventListener("activate", function(event) {
	event.waitUntil(
		caches.keys().then(function(keys) {
			return Promise.all(keys.map(function(key) {
				if (key !== cacheName && key.indexOf(cachePrefix) === 0) {
					return caches.delete(key);
				}
				return Promise.resolve();
			}));
		}).then(function() {
			return self.clients.claim();
		})
	);
});

self.addEventListener("fetch", function(event) {
	const request = event.request;
	const requestUrl = new URL(request.url);

	if (request.method !== "GET" || (!config.visitedPages && !isCacheableRequest(requestUrl) && !(config.cachePolicy === "shell" && request.mode === "navigate"))) {
		return;
	}

	event.respondWith(
		fetch(request).then(async function(response) {
			const copy = response.clone();
			if (isCacheableRequest(requestUrl) && cacheResponseAllowed(response)) {
				try {
					const cache = await caches.open(cacheName);
					await cache.put(request, copy);
				} catch (_) { /* Storage failures must not discard a successful network response. */ }
			}
			return response;
		}).catch(function() {
			return caches.open(cacheName).then(async function(cache) {
				if (config.cachePolicy === "shell") {
					let cached = await cache.match(request);
                    if (config.visitedPages && request.mode === "navigate" && !isCacheableRequest(requestUrl)) {
                        const pages = await listPages(cache);
                        if (!pages.some(page => page.url === requestUrl.href)) cached = null;
                    }
                    return cached || (request.mode === "navigate" ? await cache.match(config.offlineUrl) : null) || Response.error();
				}
				return caches.match(request);
			}).then(function(response) {
				if (response) {
					return response;
				}

				if (request.mode === "navigate") {
					return caches.match(requestUrl.href).then(function(urlResponse) {
						if (urlResponse) {
							return urlResponse;
						}

						return caches.match(config.offlineUrl).then(function(offlineResponse) {
							return offlineResponse || caches.match(config.scopeUrl);
						});
					});
				}

				return caches.match(config.offlineUrl).then(function(offlineResponse) {
					return offlineResponse || caches.match(config.scopeUrl);
				});
			});
		})
	);
});

self.addEventListener("message", function(event) {
	if (!event.data) {
		return;
	}

	if (event.data.type === config.versionMessageType) {
		sendMessage(event.source, {
			type: config.versionMessageType,
			version: cacheName
		});
		return;
	}

	if (event.data.type !== config.cacheMessageType) {
		return;
	}

	let urls = Array.isArray(event.data.urls) ? event.data.urls : [event.data.url];
	let requiredUrls = Array.isArray(event.data.requiredUrls) ? event.data.requiredUrls : urls;
	const requiredUrlsAllowed = requiredUrls.filter(Boolean).every(function(url) { return isCacheableRequest(new URL(url, location.origin)); });

	urls = uniqueUrls(urls.filter(Boolean).map(function(url) {
		return new URL(url, location.origin);
	}).filter(isCacheableRequest));
	requiredUrls = uniqueUrls(requiredUrls.filter(Boolean).map(function(url) {
		return new URL(url, location.origin);
	}).filter(isCacheableRequest));

	function sendCacheStatus(ok, cachedCount, totalCount, cachedUrls) {
		sendMessage(event.source, {
			type: config.cacheStatusMessageType,
			version: cacheName,
			ok: !!ok,
			cachedCount: cachedCount || 0,
			totalCount: totalCount || 0,
			cachedUrls: cachedUrls || []
		});
	}

	if (!requiredUrls.length || !requiredUrlsAllowed) {
		sendCacheStatus(false);
		return;
	}

	event.waitUntil(caches.open(cacheName).then(function(cache) {
		return Promise.all(urls.map(function(url) {
			return fetch(url.href, {
				credentials: "same-origin",
				cache: "reload"
			}).then(function(response) {
				if (cacheResponseAllowed(response)) {
					return cache.put(url.href, response);
				}
			}).catch(function() {});
		})).then(function() {
			const cachedUrlResults = Promise.all(urls.map(function(url) {
				return cache.match(url.href).then(function(cached) {
					return {
						href: url.href,
						cached: !!cached
					};
				});
			}));
			const cachedUrlStatus = cachedUrlResults.then(function(results) {
				const cachedUrls = [];
				const cachedCount = results.reduce(function(total, result) {
					if (result.cached) {
						cachedUrls.push(result.href);
						return total + 1;
					}
					return total;
				}, 0);
				return {
					count: cachedCount,
					urls: cachedUrls
				};
			});
			const requiredReady = Promise.all(requiredUrls.map(function(url) {
				return cache.match(url.href).then(function(cached) {
					if (cached) {
						return;
					}
					return Promise.reject(new Error("Not cached"));
				});
			}));
			return Promise.all([cachedUrlStatus, requiredReady]).then(function(results) {
				sendCacheStatus(true, results[0].count, urls.length, results[0].urls);
			}, function() {
				return cachedUrlStatus.then(function(status) {
					sendCacheStatus(false, status.count, urls.length, status.urls);
				}, function() {
					sendCacheStatus(false, 0, urls.length, []);
				});
			});
		});
	}).catch(function() {
		sendCacheStatus(false, 0, urls.length, []);
	}));
});

self.addEventListener("sync", function(event) {
	if (event.tag === config.syncTag && self.clients) {
		event.waitUntil(self.clients.matchAll().then(function(clients) {
			clients.forEach(function(client) {
				client.postMessage({ type: config.syncMessageType });
			});
		}));
	}
});
})(self.wpAppWorker);
