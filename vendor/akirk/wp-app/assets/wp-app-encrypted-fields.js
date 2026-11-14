(function (root) {
	'use strict';

	function WpAppEncryptedFields(options) {
		this.options = options || {};
		this.ajaxUrl = this.options.ajaxUrl || '';
		this.actionPrefix = this.options.actionPrefix || '';
		this.nonce = this.options.nonce || '';
		this.manifest = this.options.manifest || {};
		this.runtime = null;
		this.settings = null;
		this.verifierValue = 'wp-app-encrypted-fields-verifier';
		this.passwordErrorMessage = 'That password did not unlock these encrypted fields.';
	}

	WpAppEncryptedFields.fromGlobal = function (key) {
		var configs = root.WpAppEncryptedFieldsConfigs || {};

		if (key && configs[key]) {
			return new WpAppEncryptedFields(configs[key]);
		}

		return new WpAppEncryptedFields(root.WpAppEncryptedFieldsConfig || {});
	};

	WpAppEncryptedFields.prototype.unlock = async function () {
		if (this.runtime) {
			return this.runtime;
		}

		if (!this.settings) {
			this.settings = await this.request('settings', {});
		}

		var isSetup = !this.settings.verifier && !this.settings.hasEncryptedData;
		var password = await this.getPassword(isSetup);

		this.runtime = await root.WpAppCrypto.createSession({
			password: password,
			salt: this.settings.salt,
			iterations: this.settings.iterations
		});

		if (this.settings.verifier) {
			await this.verifyPassword();
		} else if (isSetup) {
			await this.createVerifier();
		}

		return this.runtime;
	};

	WpAppEncryptedFields.prototype.lock = function () {
		this.runtime = null;
	};

	WpAppEncryptedFields.prototype.cpt = function (name) {
		return new WpAppEncryptedFieldsCpt(this, name);
	};

	WpAppEncryptedFields.prototype.getPassword = async function (isSetup) {
		if (typeof this.options.passwordProvider === 'function') {
			return this.options.passwordProvider(isSetup, this);
		}

		return this.showPasswordDialog(isSetup);
	};

	WpAppEncryptedFields.prototype.showPasswordDialog = function (isSetup) {
		var appName = this.manifest.app && this.manifest.app.name ? this.manifest.app.name : 'this app';
		this.injectUnlockStyles();

		return new Promise(function (resolve, reject) {
			var overlay = document.createElement('div');
			var dialog = document.createElement('form');
			var title = document.createElement('h2');
			var description = document.createElement('p');
			var password = document.createElement('input');
			var confirm = document.createElement('input');
			var error = document.createElement('p');
			var actions = document.createElement('div');
			var submit = document.createElement('button');
			var cancel = document.createElement('button');

			overlay.className = 'wp-app-encrypted-fields-unlock';
			dialog.className = 'wp-app-encrypted-fields-unlock__dialog';
			title.textContent = isSetup ? 'Create encryption password' : 'Unlock encrypted fields';
			description.textContent = isSetup
				? 'Choose a password for encrypted fields in ' + appName + '. This is not your WordPress password. WordPress cannot recover it.'
				: 'Enter the encryption password for ' + appName + '. This is not your WordPress password.';
			password.type = 'password';
			password.autocomplete = isSetup ? 'new-password' : 'current-password';
			password.placeholder = 'Encryption password';
			password.required = true;
			confirm.type = 'password';
			confirm.autocomplete = 'new-password';
			confirm.placeholder = 'Confirm encryption password';
			confirm.required = isSetup;
				error.className = 'wp-app-encrypted-fields-unlock__error';
				actions.className = 'wp-app-encrypted-fields-unlock__actions';
				submit.type = 'submit';
				submit.className = 'wp-app-encrypted-fields-unlock__button wp-app-encrypted-fields-unlock__button--primary';
				submit.textContent = isSetup ? 'Create and unlock' : 'Unlock';
				cancel.type = 'button';
				cancel.className = 'wp-app-encrypted-fields-unlock__button wp-app-encrypted-fields-unlock__button--secondary';
				cancel.textContent = 'Cancel';

			dialog.appendChild(title);
			dialog.appendChild(description);
			dialog.appendChild(password);
			if (isSetup) {
				dialog.appendChild(confirm);
			}
			dialog.appendChild(error);
			actions.appendChild(cancel);
			actions.appendChild(submit);
			dialog.appendChild(actions);
			overlay.appendChild(dialog);
			document.body.appendChild(overlay);
			password.focus();

			function cleanup() {
				if (overlay.parentNode) {
					overlay.parentNode.removeChild(overlay);
				}
			}

			cancel.addEventListener('click', function () {
				cleanup();
				reject(new Error('Unlock cancelled.'));
			});

			dialog.addEventListener('submit', function (event) {
				event.preventDefault();
				error.textContent = '';

				if (isSetup && password.value !== confirm.value) {
					error.textContent = 'Passwords do not match.';
					return;
				}

				if (!password.value) {
					error.textContent = 'Enter an encryption password.';
					return;
				}

				cleanup();
				resolve(password.value);
			});
		});
	};

	WpAppEncryptedFields.prototype.injectUnlockStyles = function () {
		if (document.getElementById('wp-app-encrypted-fields-unlock-styles')) {
			return;
		}

		var style = document.createElement('style');
		style.id = 'wp-app-encrypted-fields-unlock-styles';
		style.textContent = [
			'.wp-app-encrypted-fields-unlock{position:fixed;inset:0;z-index:100000;display:grid;place-items:center;background:rgba(0,0,0,.45);padding:20px}',
			'.wp-app-encrypted-fields-unlock__dialog{box-sizing:border-box;width:min(100%,420px);display:grid;gap:14px;margin:0;padding:24px;background:var(--wp-app-color-surface,#fff);color:var(--wp-app-color-text,#1d2327);border:1px solid var(--wp-app-color-border,#dcdcde);border-radius:8px;box-shadow:0 20px 60px rgba(0,0,0,.28);font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}',
			'.wp-app-encrypted-fields-unlock__dialog h2{margin:0;font-size:22px;line-height:1.2}',
			'.wp-app-encrypted-fields-unlock__dialog p{margin:0;color:var(--wp-app-color-muted,#646970);line-height:1.45}',
				'.wp-app-encrypted-fields-unlock__dialog input{box-sizing:border-box;width:100%;padding:10px 12px;border:1px solid var(--wp-app-color-border,#dcdcde);border-radius:6px;background:var(--wp-app-color-surface,#fff);color:var(--wp-app-color-text,#1d2327);font:inherit}',
				'.wp-app-encrypted-fields-unlock__error{min-height:20px;color:#b32d2e!important}',
				'.wp-app-encrypted-fields-unlock__actions{display:flex;justify-content:flex-end;gap:10px}'
			].join('');
		document.head.appendChild(style);
	};

	WpAppEncryptedFields.prototype.getVerifierAad = function () {
		return {
			app: this.manifest.app && this.manifest.app.slug ? this.manifest.app.slug : this.actionPrefix,
			field: '__verifier',
			version: 1
		};
	};

	WpAppEncryptedFields.prototype.createVerifier = async function () {
		var verifier = await this.runtime.encrypt(this.verifierValue, {
			type: 'verifier',
			aad: this.getVerifierAad(),
			minBytes: 256,
			bucketBytes: 256
		});

		await this.postJson('save_verifier', {
			verifier: verifier
		});

		this.settings.verifier = verifier;
	};

	WpAppEncryptedFields.prototype.ensureVerifier = async function () {
		if (!this.runtime || !this.settings || this.settings.verifier) {
			return;
		}

		await this.createVerifier();
	};

	WpAppEncryptedFields.prototype.decryptValue = async function (envelope, options) {
		try {
			return await this.runtime.decrypt(envelope, options);
		} catch (error) {
			this.runtime = null;
			throw new Error(this.passwordErrorMessage);
		}
	};

	WpAppEncryptedFields.prototype.verifyPassword = async function () {
		var value = await this.decryptValue(this.settings.verifier, {
			aad: this.getVerifierAad()
		});

		if (value !== this.verifierValue) {
			this.runtime = null;
			throw new Error(this.passwordErrorMessage);
		}
	};

	WpAppEncryptedFields.prototype.request = async function (action, payload) {
		var body = Object.assign({}, payload || {}, {
			action: this.actionPrefix + '_' + action,
			nonce: this.nonce
		});

		var response = await fetch(this.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
			},
			body: new URLSearchParams(body)
		});
		var json = await response.json();

		if (!response.ok || !json.success) {
			throw new Error(json && json.data && json.data.message ? json.data.message : 'Encrypted fields request failed.');
		}

		return json.data;
	};

	WpAppEncryptedFields.prototype.postJson = async function (action, payload) {
		var response = await fetch(this.ajaxUrl + '?action=' + encodeURIComponent(this.actionPrefix + '_' + action) + '&nonce=' + encodeURIComponent(this.nonce), {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json'
			},
			body: JSON.stringify(payload || {})
		});
		var json = await response.json();

		if (!response.ok || !json.success) {
			throw new Error(json && json.data && json.data.message ? json.data.message : 'Encrypted fields request failed.');
		}

		return json.data;
	};

	WpAppEncryptedFields.prototype.getCptDefinition = function (cpt) {
		var cpts = this.manifest.cpts || {};

		if (!cpts[cpt]) {
			throw new Error('Unknown encrypted fields post type: ' + cpt);
		}

		return cpts[cpt];
	};

	WpAppEncryptedFields.prototype.getCptNames = function () {
		return Object.keys(this.manifest.cpts || {});
	};

	WpAppEncryptedFields.prototype.getEncryptedFields = function (cpt) {
		return this.getCptDefinition(cpt).encryptedFields || {};
	};

	WpAppEncryptedFields.prototype.getTaxonomies = function (cpt) {
		return this.getCptDefinition(cpt).taxonomies || [];
	};

	WpAppEncryptedFields.prototype.getAdditionalData = function (cpt, field) {
		return {
			app: this.manifest.app && this.manifest.app.slug ? this.manifest.app.slug : this.actionPrefix,
			cpt: cpt,
			field: field,
			version: 1
		};
	};

	WpAppEncryptedFields.prototype.getPaddingOptions = function (fieldDefinition) {
		return {
			type: fieldDefinition.type || 'text',
			minBytes: fieldDefinition.minBytes || 512,
			bucketBytes: fieldDefinition.bucketBytes || fieldDefinition.minBytes || 512
		};
	};

	WpAppEncryptedFields.prototype.encryptRecord = async function (cpt, record) {
		await this.unlock();

		var encrypted = {};
		var post = {};
		var taxonomies = {};
		var fields = this.getEncryptedFields(cpt);
		var taxonomyNames = this.getTaxonomies(cpt);

		Object.keys(record || {}).forEach(function (key) {
			if (key === 'id' || fields[key]) {
				return;
			}

			if (taxonomyNames.indexOf(key) !== -1) {
				taxonomies[key] = record[key];
				return;
			}

			if (key.indexOf('post_') === 0) {
				post[key] = record[key];
			}
		});

		for (var field in fields) {
			if (!Object.prototype.hasOwnProperty.call(fields, field) || !Object.prototype.hasOwnProperty.call(record, field)) {
				continue;
			}

			var options = this.getPaddingOptions(fields[field]);
			options.aad = this.getAdditionalData(cpt, field);
			encrypted[field] = await this.runtime.encrypt(record[field], options);
		}

		return {
			id: record && record.id ? record.id : 0,
			cpt: cpt,
			post: post,
			taxonomies: taxonomies,
			encrypted: encrypted
		};
	};

	WpAppEncryptedFields.prototype.decryptRecord = async function (record) {
		await this.unlock();

		var cpt = record.cpt;
		var decrypted = {
			id: record.id,
			cpt: cpt
		};
		var taxonomies = record.taxonomies || {};
		var post = record.post || {};
		var fields = this.getEncryptedFields(cpt);

		Object.keys(post).forEach(function (key) {
			decrypted[key] = post[key];
		});

		Object.keys(taxonomies).forEach(function (key) {
			decrypted[key] = Array.isArray(taxonomies[key]) && taxonomies[key].length === 1 ? taxonomies[key][0] : taxonomies[key];
		});

		for (var field in fields) {
			if (!Object.prototype.hasOwnProperty.call(fields, field)) {
				continue;
			}

			decrypted[field] = record.encrypted && record.encrypted[field]
				? await this.decryptValue(record.encrypted[field], { aad: this.getAdditionalData(cpt, field) })
				: '';
		}

		await this.ensureVerifier();

		return decrypted;
	};

	function WpAppEncryptedFieldsCpt(client, cpt) {
		this.client = client;
		this.cptName = cpt;
	}

	WpAppEncryptedFieldsCpt.prototype.all = function (args) {
		return new WpAppEncryptedFieldsQuery(this, 'list', args || {});
	};

	WpAppEncryptedFieldsCpt.prototype.get = function (id) {
		return new WpAppEncryptedFieldsQuery(this, 'get', { id: id });
	};

	WpAppEncryptedFieldsCpt.prototype.save = async function (record) {
		var payload = await this.client.encryptRecord(this.cptName, record || {});
		var response = await this.client.postJson('save', payload);

		return this.client.decryptRecord(response.record);
	};

	WpAppEncryptedFieldsCpt.prototype.set = async function (id, field, value) {
		var record = { id: id };
		record[field] = value;

		return this.save(record);
	};

	WpAppEncryptedFieldsCpt.prototype.delete = function (id) {
		return this.client.postJson('delete', {
			cpt: this.cptName,
			id: id
		});
	};

	function WpAppEncryptedFieldsQuery(cptClient, action, args) {
		this.cptClient = cptClient;
		this.action = action;
		this.args = args || {};
	}

	WpAppEncryptedFieldsQuery.prototype.decrypt = async function () {
		var payload = Object.assign({}, this.args, {
			cpt: this.cptClient.cptName
		});
		var response = await this.cptClient.client.request(this.action, payload);

		if (response.records) {
			var records = [];
			for (var i = 0; i < response.records.length; i++) {
				records.push(await this.cptClient.client.decryptRecord(response.records[i]));
			}
			return new WpAppDecryptedRecordSet(records);
		}

		return this.cptClient.client.decryptRecord(response.record);
	};

	function WpAppDecryptedRecordSet(records) {
		this.records = records || [];
	}

	WpAppDecryptedRecordSet.prototype.where = function (field, value) {
		return new WpAppDecryptedRecordSet(this.records.filter(function (record) {
			return record[field] === value;
		}));
	};

	WpAppDecryptedRecordSet.prototype.whereContains = function (field, value) {
		return new WpAppDecryptedRecordSet(this.records.filter(function (record) {
			var fieldValue = record[field];

			if (Array.isArray(fieldValue)) {
				return fieldValue.indexOf(value) !== -1;
			}

			return String(fieldValue || '').indexOf(value) !== -1;
		}));
	};

	WpAppDecryptedRecordSet.prototype.sortBy = function (field) {
		return new WpAppDecryptedRecordSet(this.records.slice().sort(function (a, b) {
			return String(a[field] || '').localeCompare(String(b[field] || ''));
		}));
	};

	WpAppDecryptedRecordSet.prototype.toArray = function () {
		return this.records.slice();
	};

	root.WpAppEncryptedFields = WpAppEncryptedFields;
	root.WpAppDecryptedRecordSet = WpAppDecryptedRecordSet;
})(typeof window !== 'undefined' ? window : globalThis);
