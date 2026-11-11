/* Browser-side discovery and model management for local AI servers. */
(function(window) {
    'use strict';
    var previous = window.wpAiProviderBrowserStatus;

    async function request(provider, path, body, timeoutMs) {
        var controller = new AbortController();
        var timeout = setTimeout(function() { controller.abort(); }, timeoutMs || 10000);
        try {
            var response = await fetch(provider.endpoint.replace(/\/+$/, '') + path, {
                signal: controller.signal,
                method: body ? 'POST' : 'GET',
                headers: body ? { 'Content-Type': 'application/json' } : {},
                body: body ? JSON.stringify(body) : undefined
            });
            if (!response.ok) throw new Error('Request failed (HTTP ' + response.status + '). Check server access and CORS settings.');
            return await response.json();
        } finally {
            clearTimeout(timeout);
        }
    }

    window.wpAiProviderBrowserStatus = {
        check: async function(provider) {
            if (!provider.localKind && previous && typeof previous.check === 'function') {
                var result = await previous.check(provider);
                if (result && result.status !== 'unsupported') return result;
            }
            var status = { providerId: provider.id, reachable: false, status: 'unsupported', models: [], loadedModels: [], loadedModelIds: [] };
            if (provider.type !== 'server' || !provider.endpoint) return status;
            try {
                var data;
                if (provider.localKind === 'lmstudio') {
                    try {
                        data = await request(provider, '/api/v1/models');
                        if (!Array.isArray(data.models)) throw new Error('Invalid model list.');
                        status.models = data.models.filter(function(model) { return model.type === 'llm'; }).map(function(model) {
                            var instances = model.loaded_instances || [];
                            if (instances.length) {
                                status.loadedModels.push(model.display_name || model.key);
                                status.loadedModelIds.push(model.key);
                            }
                            return { id: model.key, name: model.display_name || model.key, capabilities: ['text_generation'] };
                        });
                        status.loadSupported = true;
                    } catch (error) {
                        // Older LM Studio releases still support inference and model listing.
                        data = await request(provider, '/v1/models');
                        if (!Array.isArray(data.data)) throw new Error('Invalid model list.');
                        status.models = data.data.map(function(model) { return { id: model.id, name: model.id }; });
                    }
                } else if (provider.localKind === 'ollama') {
                    data = await request(provider, '/api/tags');
                    if (!Array.isArray(data.models)) throw new Error('Invalid model list.');
                    status.models = data.models.map(function(model) { return { id: model.name, name: model.name }; });
                } else {
                    data = await request(provider, '/v1/models');
                    if (!Array.isArray(data.data)) throw new Error('Invalid model discovery response.');
                    status.models = data.data.map(function(model) { return { id: model.id, name: model.id }; });
                }
                status.models = status.models.filter(function(model) { return typeof model.id === 'string' && model.id; });
                status.reachable = true;
                status.status = status.models.length ? 'ready' : 'no_models';
            } catch (error) {
                status.status = 'unreachable';
                status.error = error.message;
            }
            return status;
        },
        loadModel: async function(provider, model) {
            if (provider.localKind !== 'lmstudio' || !model) throw new Error('Model loading is unavailable.');
            var data = await request(provider, '/api/v1/models');
            // Explicit switching releases other LLM instances before loading the selection.
            for (var entry of data.models || []) {
                if (entry.type !== 'llm' || entry.key === model) continue;
                for (var instance of entry.loaded_instances || []) {
                    await request(provider, '/api/v1/models/unload', { instance_id: instance.id }, 30000);
                }
            }
            var result = await request(provider, '/api/v1/models/load', { model: model }, 300000);
            if (result.status !== 'loaded') throw new Error('Server did not confirm model loading.');
            return result;
        }
    };
})(window);
