(function (root, factory) {
	'use strict';

	if (typeof module === 'object' && module.exports) {
		module.exports = factory();
	} else {
		root.WpAppCrypto = factory();
	}
})(typeof self !== 'undefined' ? self : this, function () {
	'use strict';

	var encoder = new TextEncoder();
	var decoder = new TextDecoder();
	var defaultIterations = 250000;
	var defaultMinBytes = 512;

	function getCrypto() {
		var cryptoObject = typeof globalThis !== 'undefined' ? globalThis.crypto : null;

		if (!cryptoObject || !cryptoObject.subtle || !cryptoObject.getRandomValues) {
			throw new Error('WpAppCrypto requires the Web Crypto API.');
		}

		return cryptoObject;
	}

	function bytesToBase64(bytes) {
		var binary = '';
		var chunkSize = 0x8000;

		for (var i = 0; i < bytes.length; i += chunkSize) {
			binary += String.fromCharCode.apply(null, bytes.subarray(i, i + chunkSize));
		}

		if (typeof btoa === 'function') {
			return btoa(binary);
		}

		return Buffer.from(binary, 'binary').toString('base64');
	}

	function base64ToBytes(value) {
		var binary;

		if (typeof atob === 'function') {
			binary = atob(value);
		} else {
			binary = Buffer.from(value, 'base64').toString('binary');
		}

		var bytes = new Uint8Array(binary.length);
		for (var i = 0; i < binary.length; i++) {
			bytes[i] = binary.charCodeAt(i);
		}

		return bytes;
	}

	function randomBytes(length) {
		var bytes = new Uint8Array(length);
		getCrypto().getRandomValues(bytes);
		return bytes;
	}

	function normalizeString(value) {
		return typeof value === 'string' ? value : JSON.stringify(value);
	}

	function stableStringify(value) {
		if (value === null || typeof value !== 'object') {
			return JSON.stringify(value);
		}

		if (Array.isArray(value)) {
			return '[' + value.map(stableStringify).join(',') + ']';
		}

		return '{' + Object.keys(value).sort().map(function (key) {
			return JSON.stringify(key) + ':' + stableStringify(value[key]);
		}).join(',') + '}';
	}

	function getAdditionalData(aad) {
		if (aad === undefined || aad === null || aad === '') {
			return undefined;
		}

		return encoder.encode(typeof aad === 'string' ? aad : stableStringify(aad));
	}

	function targetPlaintextBytes(value, options) {
		var minBytes = Number(options.minBytes || defaultMinBytes);
		var bucketBytes = Number(options.bucketBytes || 0);
		var valueBytes = encoder.encode(normalizeString(value)).length;
		var target = Math.max(minBytes, valueBytes);

		if (bucketBytes > 0) {
			target = Math.ceil(target / bucketBytes) * bucketBytes;
		}

		return target;
	}

	function makePaddedPayload(value, options) {
		var targetBytes = targetPlaintextBytes(value, options);
		var prepadBytes = 0;
		var postpadBytes = 0;
		var payload;
		var encodedLength = 0;

		for (var attempt = 0; attempt < 12; attempt++) {
			payload = {
				type: options.type || 'text',
				value: value,
				prepad: bytesToBase64(randomBytes(prepadBytes)),
				postpad: bytesToBase64(randomBytes(postpadBytes))
			};
			encodedLength = encoder.encode(JSON.stringify(payload)).length;

			if (encodedLength >= targetBytes) {
				return payload;
			}

			var remaining = targetBytes - encodedLength;
			var extraRandomBytes = Math.max(1, Math.ceil(remaining * 0.75));
			var split = Math.floor(Math.random() * (extraRandomBytes + 1));
			prepadBytes += split;
			postpadBytes += extraRandomBytes - split;
		}

		return payload;
	}

	async function deriveKey(password, salt, options) {
		var cryptoObject = getCrypto();
		var iterations = Number(options.iterations || defaultIterations);
		var baseKey = await cryptoObject.subtle.importKey(
			'raw',
			encoder.encode(password),
			'PBKDF2',
			false,
			['deriveKey']
		);

		return cryptoObject.subtle.deriveKey(
			{
				name: 'PBKDF2',
				hash: 'SHA-256',
				salt: salt,
				iterations: iterations
			},
			baseKey,
			{
				name: 'AES-GCM',
				length: 256
			},
			false,
			['encrypt', 'decrypt']
		);
	}

	async function createSession(options) {
		options = options || {};

		if (!options.password) {
			throw new Error('A password is required to create a WpAppCrypto session.');
		}

		var salt = options.salt ? base64ToBytes(options.salt) : randomBytes(16);
		var iterations = Number(options.iterations || defaultIterations);
		var key = await deriveKey(options.password, salt, { iterations: iterations });

		return {
			salt: bytesToBase64(salt),
			iterations: iterations,
			encrypt: async function (value, encryptOptions) {
				encryptOptions = encryptOptions || {};

				var iv = randomBytes(12);
				var payload = makePaddedPayload(value, encryptOptions);
				var aad = encryptOptions.aad || options.aad || null;
				var ciphertext = await getCrypto().subtle.encrypt(
					{
						name: 'AES-GCM',
						iv: iv,
						additionalData: getAdditionalData(aad)
					},
					key,
					encoder.encode(JSON.stringify(payload))
				);

				return {
					v: 1,
					alg: 'AES-GCM',
					kdf: 'PBKDF2-SHA-256',
					iterations: iterations,
					salt: bytesToBase64(salt),
					iv: bytesToBase64(iv),
					aad: aad ? stableStringify(aad) : '',
					ciphertext: bytesToBase64(new Uint8Array(ciphertext))
				};
			},
			decrypt: async function (envelope, decryptOptions) {
				decryptOptions = decryptOptions || {};

				if (!envelope || envelope.v !== 1 || envelope.alg !== 'AES-GCM') {
					throw new Error('Unsupported encrypted envelope.');
				}

				var aad = decryptOptions.aad;
				if (aad === undefined && envelope.aad) {
					aad = envelope.aad;
				}

				var plaintext = await getCrypto().subtle.decrypt(
					{
						name: 'AES-GCM',
						iv: base64ToBytes(envelope.iv),
						additionalData: getAdditionalData(aad)
					},
					key,
					base64ToBytes(envelope.ciphertext)
				);
				var payload = JSON.parse(decoder.decode(plaintext));

				return payload.value;
			}
		};
	}

	function createRuntime(options) {
		options = options || {};
		var sessionPromise = null;
		var session = null;

		function getPassword() {
			if (typeof options.passwordProvider === 'function') {
				return Promise.resolve(options.passwordProvider());
			}

			var message = options.prompt || 'Enter the encryption password for this app.';
			return Promise.resolve(globalThis.prompt(message));
		}

		return {
			unlock: async function () {
				if (sessionPromise) {
					return sessionPromise;
				}

				sessionPromise = getPassword().then(function (password) {
					if (!password) {
						throw new Error('Unlock cancelled.');
					}

					return createSession({
						password: password,
						salt: options.salt,
						iterations: options.iterations,
						aad: options.aad
					});
				}).then(function (createdSession) {
					session = createdSession;
					if (typeof options.onUnlock === 'function') {
						options.onUnlock(createdSession);
					}
					return createdSession;
				}).catch(function (error) {
					sessionPromise = null;
					throw error;
				});

				return sessionPromise;
			},
			getSession: function () {
				return session;
			},
			encrypt: async function (value, encryptOptions) {
				var activeSession = session || await this.unlock();
				return activeSession.encrypt(value, encryptOptions);
			},
			decrypt: async function (envelope, decryptOptions) {
				var activeSession = session || await this.unlock();
				return activeSession.decrypt(envelope, decryptOptions);
			},
			lock: function () {
				session = null;
				sessionPromise = null;
			}
		};
	}

	return {
		createSession: createSession,
		createRuntime: createRuntime,
		_base64ToBytes: base64ToBytes,
		_bytesToBase64: bytesToBase64,
		_stableStringify: stableStringify
	};
});
