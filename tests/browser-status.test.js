const { it } = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');

function load(fetch, previous) {
    const window = { wpAiProviderBrowserStatus: previous };
    vm.runInNewContext(fs.readFileSync(require('node:path').join(__dirname, '../assets/js/browser-status.js'), 'utf8'), {
        window, fetch, AbortController, setTimeout, clearTimeout
    });
    return window.wpAiProviderBrowserStatus;
}
const provider = { id: 'lmstudio', type: 'server', endpoint: 'http://localhost:1234/' };
it('discovers directory provider models directly in the browser', async () => {
    const registry = load(async url => {
        assert.equal(url, 'http://localhost:1234/v1/models');
        return { ok: true, json: async () => ({ data: [{ id: 'local-model' }] }) };
    });
    const status = await registry.check(provider);
    assert.equal(status.providerId, 'lmstudio');
    assert.equal(status.status, 'ready');
    assert.equal(status.models[0].id, 'local-model');
});
it('preserves an existing provider checker', async () => {
    const registry = load(() => { throw Error('Unexpected fallback'); }, {
        check: async () => ({ status: 'ready', reachable: true })
    });
    assert.equal((await registry.check(provider)).status, 'ready');
});
it('reports unreachable servers and empty model lists', async () => {
    assert.equal((await load(async () => { throw Error('Failed to fetch'); }).check(provider)).status, 'unreachable');
    assert.equal((await load(async () => ({ ok: true, json: async () => ({ data: [] }) })).check(provider)).status, 'no_models');
});
it('lists unloaded LM Studio models, excluding embeddings', async () => {
    const status = await load(async () => ({ ok: true, json: async () => ({ models: [
        { type: 'llm', key: 'loaded', loaded_instances: [{ id: 'instance' }] },
        { type: 'llm', key: 'downloaded', loaded_instances: [] },
        { type: 'embedding', key: 'embedding' }
    ] }) })).check({ ...provider, localKind: 'lmstudio' });
    assert.equal(status.models.length, 2);
    assert.equal(status.loadedModelIds.join(','), 'loaded');
    assert.equal(status.loadSupported, true);
});
it('loads the selection after unloading other LLM instances', async () => {
    const calls = [];
    const registry = load(async (url, options) => {
        calls.push([url, options.body && JSON.parse(options.body)]);
        return { ok: true, json: async () => url.endsWith('/load') ? { status: 'loaded' } : { models: [
            { type: 'llm', key: 'old', loaded_instances: [{ id: 'old-instance' }] },
            { type: 'embedding', loaded_instances: [{ id: 'keep' }] }
        ] } };
    });
    await registry.loadModel({ ...provider, localKind: 'lmstudio' }, 'new');
    assert.equal(calls.length, 3);
    assert.equal(calls[1][1].instance_id, 'old-instance');
    assert.equal(calls[2][1].model, 'new');
});
it('discovers Ollama using its native model list', async () => {
    const status = await load(async url => {
        assert.ok(url.endsWith('/api/tags'));
        return { ok: true, json: async () => ({ models: [{ name: 'llama' }] }) };
    }).check({ ...provider, localKind: 'ollama' });
    assert.equal(status.models[0].id, 'llama');
});
