(function($) {
    'use strict';

    $.extend(window.aiAssistant, {
        getMessageTimestamp: window.aiAssistant.getMessageTimestamp || function() {
            return Date.now ? Date.now() : new Date().getTime();
        },

        createStoredMessage: window.aiAssistant.createStoredMessage || function(role, content, extra) {
            var message = $.extend({
                role: role,
                content: content,
                _ts: this.getMessageTimestamp()
            }, extra || {});

            if (!message._ts) {
                message._ts = this.getMessageTimestamp();
            }

            return message;
        },

        getDestructiveTools: function() {
            var config = typeof aiAssistantConfig !== 'undefined' ? aiAssistantConfig : {};
            return Array.isArray(config.destructiveTools) ? config.destructiveTools.slice() : [];
        },

        processToolCalls: function(toolCalls, provider, stopReason) {
            var self = this;
            var destructiveTools = this.getDestructiveTools();

            var needsConfirmation = [];
            var executeImmediately = [];

            // Deduplicate tool calls with identical name + arguments (local models repeat themselves)
            var seen = {};
            var duplicateIds = {};
            toolCalls = toolCalls.filter(function(tc) {
                var key = tc.name + '|' + JSON.stringify(tc.arguments);
                if (seen[key]) { duplicateIds[tc.id] = true; return false; }
                seen[key] = true;
                return true;
            });
            // Remove tool cards for dropped duplicates silently
            Object.keys(duplicateIds).forEach(function(id) {
                $('[data-tool-id="' + id + '"]').remove();
                if (self.toolCardsState) delete self.toolCardsState[id];
            });

            var pickImageSeen = false;
            var deniedPickImageResults = [];
            toolCalls = toolCalls.filter(function(tc) {
                if (tc.name !== 'pick_image') {
                    return true;
                }
                if (!pickImageSeen) {
                    pickImageSeen = true;
                    return true;
                }

                var result = self.getMultiplePickImageToolResult(tc.id, tc.arguments || {});
                deniedPickImageResults.push(result);
                self.updateToolCardDescription(tc.id, tc.name, tc.arguments);
                self.setToolCardState(tc.id, 'error', { message: result.result.error });
                return false;
            });
            if (deniedPickImageResults.length > 0) {
                this.pendingToolResults = this.pendingToolResults.concat(deniedPickImageResults);
            }

            // Build set of valid tool IDs from this batch
            var validToolIds = {};
            toolCalls.forEach(function(tc) {
                validToolIds[tc.id] = true;
            });

            // Mark any stale "generating" cards as truncated/interrupted
            // (they were incomplete when the stream ended)
            if (this.toolCardsState) {
                Object.keys(this.toolCardsState).forEach(function(toolId) {
                    if (!validToolIds[toolId] && self.toolCardsState[toolId].state === 'generating') {
                        var message = 'Interrupted';
                        if (stopReason === 'max_tokens') {
                            message = 'Truncated (max tokens)';
                        } else if (stopReason === 'end_turn') {
                            message = 'Incomplete';
                        }
                        console.warn('[AI Assistant] Tool marked as ' + message + ':', {
                            toolId: toolId,
                            toolState: self.toolCardsState[toolId],
                            stopReason: stopReason
                        });
                        self.setToolCardState(toolId, 'error', { message: message });
                    }
                });
            }

            toolCalls.forEach(function(tc) {
                tc.arguments = self.normalizeToolArguments(tc.name, tc.arguments || {});

                // Ensure card exists with proper description
                self.updateToolCardDescription(tc.id, tc.name, tc.arguments);

                if (self.autoApproveMode || self.yoloMode || destructiveTools.indexOf(tc.name) < 0 ||
                           (tc.name === 'ability' && tc.arguments && tc.arguments.action !== 'execute') ||
                           self.isAbilityAutoApproved(tc) ||
                           self.isRestApiAutoApproved(tc)) {
                    executeImmediately.push(tc);
                } else {
                    needsConfirmation.push(tc);
                }
            });

            if (needsConfirmation.length > 0) {
                needsConfirmation.forEach(function(tc) {
                    var state = self.isAbilityExecutionToolCall(tc) ? 'checking' : 'pending';
                    self.setToolCardState(tc.id, state);
                });

                this.prepareActionsForApproval(needsConfirmation.map(function(tc) {
                    return {
                        id: tc.id,
                        tool: tc.name,
                        arguments: tc.arguments,
                        description: self.getActionDescription(tc.name, tc.arguments),
                        provider: provider
                    };
                })).then(function(preflight) {
                    var autoApprovedActions = [];
                    var approvalActions = preflight.actions.filter(function(action) {
                        if (self.isActionAutoApproved(action)) {
                            autoApprovedActions.push(action);
                            return false;
                        }
                        return true;
                    });
                    var deniedResults = preflight.deniedResults;

                    if (deniedResults.length > 0) {
                        self.pendingToolResults = self.pendingToolResults.concat(deniedResults);
                    }

                    if (autoApprovedActions.length > 0) {
                        self.executeApprovedActions(autoApprovedActions, provider);
                    }

                    if (approvalActions.length > 0) {
                        approvalActions.forEach(function(action) {
                            self.setToolCardState(action.id, 'pending');
                        });
                        self.pendingActions = approvalActions;
                        if (approvalActions.length > 1) {
                            self.showPendingActionsHeader();
                        }
                        self.showToolApprovalModal();
                    }

                    if (executeImmediately.length > 0) {
                        executeImmediately.forEach(function(tc) {
                            self.setToolCardState(tc.id, 'executing');
                        });
                        self.executeTools(executeImmediately, provider);
                        return;
                    }

                    if (deniedResults.length > 0) {
                        self.handleToolResults([], provider);
                    } else if (autoApprovedActions.length === 0) {
                        self.setLoading(false);
                    }
                });
            } else if (executeImmediately.length === 0) {
                if (deniedPickImageResults.length > 0) {
                    this.handleToolResults([], provider);
                    return;
                }
                this.verifyPendingPluginRecoveryCandidate().then(function() {
                    self.setLoading(false);
                }).catch(function() {
                    self.setLoading(false);
                });
            } else {
                executeImmediately.forEach(function(tc) {
                    self.setToolCardState(tc.id, 'executing');
                });
                this.executeTools(executeImmediately, provider);
            }
        },

        getMultiplePickImageToolResult: function(toolId, args) {
            return {
                id: toolId,
                name: 'pick_image',
                input: args || {},
                result: {
                    error: 'Multiple image picker requests in one response are not supported because they ask the user to make several interactive choices at once.',
                    code: 'multiple_pick_image_calls',
                    instruction: 'Call pick_image once, wait for the selected image result, then decide whether another image is actually needed.'
                },
                success: false
            };
        },

        normalizeToolArguments: function(toolName, args) {
            if (!args || typeof args !== 'object' || Array.isArray(args)) {
                return args || {};
            }

            if (toolName === 'edit_file' && typeof args.edits === 'string') {
                var cleaned = args.edits.replace(/\s*<\/invoke>\s*$/i, '');
                if (cleaned !== args.edits) {
                    try {
                        var parsed = JSON.parse(cleaned);
                        if (
                            Array.isArray(parsed) ||
                            (parsed && typeof parsed === 'object' && !Array.isArray(parsed))
                        ) {
                            return Object.assign({}, args, { edits: parsed });
                        }
                    } catch (e) {
                        // Leave the original malformed value in place so the file endpoint rejects it.
                    }
                }
            }

            return args;
        },

        executeTools: function(toolCalls, provider) {
            var self = this;
            var promises = toolCalls.map(function(tc) {
                self.setToolCardState(tc.id, 'executing');
                var beforeExecute = self.isWordPressBackedToolCall(tc)
                    ? self.verifyPendingPluginRecoveryCandidate()
                    : Promise.resolve(null);
                return beforeExecute.then(function(recovery) {
                    if (recovery) {
                        return {
                            id: tc.id,
                            name: tc.name || tc.tool,
                            input: tc.arguments || {},
                            success: false,
                            result: {
                                error: 'WordPress failed after plugin file changes; the plugin was automatically emergency-disabled before running this tool.',
                                skipped: true,
                                recovery: recovery
                            }
                        };
                    }
                    return self.executeSingleTool(tc);
                });
            });

            Promise.all(promises).then(function(results) {
                return self.verifyPluginFileMutationResults(results);
            }).then(function(results) {
                return self.verifyActivatedPluginResults(results);
            }).then(function(results) {
                results.forEach(function(result) {
                    if (result.success) {
                        var successOptions = result.name === 'navigate'
                            ? { message: 'Suggestion shown' }
                            : { output: result.result };
                        self.setToolCardState(result.id, 'completed', successOptions);
                    } else {
                        var errorMsg = result.result?.error || 'Failed';
                        self.setToolCardState(result.id, 'error', { message: errorMsg, output: result.result });
                    }
                    if (self.notifyToolCallCallbacks) {
                        self.notifyToolCallCallbacks(result, provider);
                    }
                });
                self.handleToolResults(results, provider);
            }).catch(function(error) {
                self.setLoading(false);
                self.addMessage('error', 'Tool execution error: ' + error.message);
            });
        },

        isWordPressBackedToolCall: function(toolCall) {
            var toolName = toolCall && (toolCall.name || toolCall.tool);
            if (!toolName) {
                return false;
            }

            if (
                toolName === 'get_page_html' ||
                toolName === 'summarize_conversation' ||
                toolName === 'pick_image' ||
                this.canUseFileToolEndpoint(toolName)
            ) {
                return false;
            }

            return true;
        },

        rememberPluginRecoveryCandidateFromFileResult: function(result) {
            var candidate = this.getPluginRecoveryCandidateFromFileResult(result);
            if (!candidate) {
                return;
            }

            this.pendingPluginRecoveryCandidate = candidate;
        },

        getPluginRecoveryCandidateFromFileResult: function(result) {
            if (!result || !result.success) {
                return null;
            }

            var toolName = result.name || result.tool;
            if (['write_file', 'edit_file', 'delete_file'].indexOf(toolName) < 0) {
                return null;
            }

            var path = result.result && result.result.path;
            var candidate = this.getPluginCandidateFromPath(path);
            if (!candidate) {
                return null;
            }

            candidate.changed_path = path;
            candidate.changed_paths = [path];
            candidate.source_tool = toolName;
            candidate.recorded_at = Date.now();
            return candidate;
        },

        getPluginRecoveryCandidatesFromFileResults: function(results) {
            var self = this;
            var seen = {};
            var candidates = [];

            (results || []).forEach(function(result) {
                var candidate = self.getPluginRecoveryCandidateFromFileResult(result);
                if (!candidate) {
                    return;
                }

                var key = candidate.plugin_slug + '|' + (candidate.plugin_file || '');
                if (seen[key]) {
                    seen[key].changed_paths.push(candidate.changed_path);
                    seen[key].source_tool = candidate.source_tool;
                    seen[key].recorded_at = candidate.recorded_at;
                    return;
                }

                seen[key] = candidate;
                candidates.push(candidate);
            });

            return candidates;
        },

        verifyPluginFileMutationResults: function(results) {
            var self = this;
            var candidates = this.getPluginRecoveryCandidatesFromFileResults(results);

            if (candidates.length === 0) {
                return Promise.resolve(results);
            }

            this.pendingPluginRecoveryCandidate = candidates[candidates.length - 1];

            return this.verifyWpok().then(function(wpok) {
                if (wpok) {
                    self.pendingPluginRecoveryCandidate = null;
                    return results;
                }

                var recoveryChecks = candidates.map(function(candidate) {
                    return self.emergencyDeactivateActivatedPlugin(candidate).then(function(recovery) {
                        recovery.changed_paths = candidate.changed_paths;
                        recovery.source_tool = candidate.source_tool;
                        return {
                            candidate: candidate,
                            success: true,
                            recovery: recovery
                        };
                    }).catch(function(error) {
                        return {
                            candidate: candidate,
                            success: false,
                            error: error.message || 'Emergency disable failed'
                        };
                    });
                });

                return Promise.all(recoveryChecks).then(function(recoveries) {
                    var recoveriesBySlug = {};
                    recoveries.forEach(function(item) {
                        recoveriesBySlug[item.candidate.plugin_slug] = item;
                    });

                    self.pendingPluginRecoveryCandidate = null;

                    return results.map(function(result) {
                        var candidate = self.getPluginRecoveryCandidateFromFileResult(result);
                        if (!candidate) {
                            return result;
                        }

                        var recovery = recoveriesBySlug[candidate.plugin_slug];
                        var recovered = recovery && recovery.success;
                        var nextResult = {
                            error: recovered
                                ? 'Plugin file change broke WordPress; the affected plugin was automatically emergency-disabled.'
                                : 'Plugin file change broke WordPress, and automatic emergency disable failed: ' + ((recovery && recovery.error) || 'Unknown error'),
                            file_result: result.result,
                            changed_path: candidate.changed_path
                        };

                        if (recovered) {
                            nextResult.recovery = recovery.recovery;
                        } else if (recovery && recovery.error) {
                            nextResult.recovery_error = recovery.error;
                        }

                        return {
                            id: result.id,
                            name: result.name,
                            input: result.input,
                            success: false,
                            result: nextResult
                        };
                    });
                });
            });
        },

        getPluginCandidateFromPath: function(path) {
            path = String(path || '').replace(/\\/g, '/').replace(/^\/+/, '');
            var match = path.match(/^plugins\/([^/]+)(?:\/|$)/);
            if (!match) {
                return null;
            }

            var pluginSlug = this.extractPluginSlug(match[1], '');
            if (!pluginSlug || pluginSlug === 'ai-assistant') {
                return null;
            }

            return {
                plugin_slug: pluginSlug,
                plugin_file: ''
            };
        },

        verifyPendingPluginRecoveryCandidate: function() {
            var self = this;
            var candidate = this.pendingPluginRecoveryCandidate;
            if (!candidate) {
                return Promise.resolve(null);
            }

            return this.verifyWpok().then(function(wpok) {
                if (wpok) {
                    self.pendingPluginRecoveryCandidate = null;
                    return null;
                }

                return self.emergencyDeactivateActivatedPlugin(candidate).then(function(recovery) {
                    self.pendingPluginRecoveryCandidate = null;
                    recovery.changed_path = candidate.changed_path;
                    recovery.source_tool = candidate.source_tool;
                    return recovery;
                }).catch(function(error) {
                    throw error;
                });
            });
        },

        verifyActivatedPluginResults: function(results) {
            var self = this;
            var checks = results.map(function(result) {
                var candidate = self.getActivatedPluginCandidate(result);
                if (!candidate) {
                    return Promise.resolve(result);
                }

                return self.verifyWpok().then(function(wpok) {
                    if (wpok) {
                        return result;
                    }

                    return self.emergencyDeactivateActivatedPlugin(candidate).then(function(recovery) {
                        return {
                            id: result.id,
                            name: result.name,
                            input: result.input,
                            success: false,
                            result: {
                                error: 'Plugin activation broke WordPress and the plugin was automatically emergency-disabled.',
                                activation_result: result.result,
                                recovery: recovery
                            }
                        };
                    }).catch(function(error) {
                        return {
                            id: result.id,
                            name: result.name,
                            input: result.input,
                            success: false,
                            result: {
                                error: 'Plugin activation broke WordPress, and automatic emergency disable failed: ' + error.message,
                                activation_result: result.result
                            }
                        };
                    });
                });
            });

            return Promise.all(checks);
        },

        getActivatedPluginCandidate: function(toolResult) {
            if (!toolResult || !toolResult.success) {
                return null;
            }

            var toolName = toolResult.name || toolResult.tool;
            var input = toolResult.input || {};
            var result = toolResult.result || {};

            if (toolName === 'install_plugin' && input.activate) {
                var active = result.active === true ||
                    result.status === 'activated' ||
                    result.status === 'installed_and_activated';
                if (!active) {
                    return null;
                }

                return {
                    plugin_slug: this.extractPluginSlug(input.slug || '', result.plugin_file || ''),
                    plugin_file: result.plugin_file || ''
                };
            }

            if (toolName === 'ability' && input.action === 'execute' && input.ability === 'ai/create-wp-app') {
                var abilityResult = result.result || result;
                if (!abilityResult || abilityResult.activated !== true) {
                    return null;
                }

                return {
                    plugin_slug: abilityResult.plugin_slug || '',
                    plugin_file: ''
                };
            }

            return null;
        },

        extractPluginSlug: function(slug, pluginFile) {
            slug = String(slug || '').trim();
            if (slug && /^[a-z0-9][a-z0-9-]*$/i.test(slug)) {
                return slug.toLowerCase();
            }

            pluginFile = String(pluginFile || '').replace(/\\/g, '/').replace(/^\/+/, '');
            if (!pluginFile) {
                return '';
            }

            if (pluginFile.indexOf('/') !== -1) {
                return pluginFile.split('/')[0].toLowerCase();
            }

            return pluginFile.replace(/\.php$/i, '').toLowerCase();
        },

        verifyWpok: function() {
            if (!window.aiAssistantConfig || !aiAssistantConfig.ajaxUrl || !aiAssistantConfig.nonce) {
                return Promise.resolve(true);
            }

            return new Promise(function(resolve) {
                $.ajax({
                    url: aiAssistantConfig.ajaxUrl,
                    type: 'POST',
                    dataType: 'json',
                    global: false,
                    timeout: 5000,
                    data: {
                        action: 'ai_assistant_wpok',
                        _wpnonce: aiAssistantConfig.nonce
                    },
                    success: function(response) {
                        resolve(!!(response && response.success));
                    },
                    error: function() {
                        resolve(false);
                    }
                });
            });
        },

        emergencyDeactivateActivatedPlugin: function(candidate) {
            if (!candidate || !candidate.plugin_slug || candidate.plugin_slug === 'ai-assistant') {
                return Promise.reject(new Error('No valid plugin candidate to deactivate'));
            }

            if (!window.aiAssistantConfig || !aiAssistantConfig.fileToolsUrl || !aiAssistantConfig.fileToolsToken) {
                return Promise.reject(new Error('File recovery endpoint is not configured'));
            }

            return fetch(aiAssistantConfig.fileToolsUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    token: aiAssistantConfig.fileToolsToken,
                    tool: 'emergency_deactivate_plugin',
                    arguments: {
                        plugin_slug: candidate.plugin_slug,
                        plugin_file: candidate.plugin_file || '',
                        reason: 'Emergency deactivate after plugin failed wpok probe'
                    },
                    conversation_id: this.conversationId || 0
                })
            }).then(function(response) {
                return response.text().then(function(text) {
                    var payload;
                    try {
                        payload = JSON.parse(text);
                    } catch (e) {
                        throw new Error('File recovery endpoint returned non-JSON response');
                    }

                    if (!payload || !payload.success) {
                        var message = payload && payload.data && (payload.data.message || payload.data.error);
                        throw new Error(message || 'Emergency deactivate failed');
                    }

                    return payload.data || {};
                });
            });
        },

        isAbilityToolName: function(toolName) {
            return ['ability', 'list_abilities', 'get_ability', 'execute_ability'].indexOf(toolName) >= 0;
        },

        getClientAbilitiesConfig: function() {
            return (window.aiAssistantConfig && window.aiAssistantConfig.clientAbilities) || {};
        },

        getScriptModuleImporter: function() {
            if (this.scriptModuleImporter !== undefined) {
                return this.scriptModuleImporter;
            }

            try {
                this.scriptModuleImporter = new Function('specifier', 'return import(specifier);');
            } catch (e) {
                this.scriptModuleImporter = null;
            }

            return this.scriptModuleImporter;
        },

        getClientAbilitiesApi: function() {
            var self = this;
            var config = this.getClientAbilitiesConfig();
            if (!config.enabled || this.clientAbilitiesUnavailable) {
                return Promise.resolve(null);
            }

            if (this.clientAbilitiesPromise) {
                return this.clientAbilitiesPromise;
            }

            var importModule = this.getScriptModuleImporter();
            if (!importModule) {
                // WP 6.9 COMPAT: Classic browsers without module import support use the PHP executor.
                this.clientAbilitiesUnavailable = true;
                return Promise.resolve(null);
            }

            this.clientAbilitiesPromise = importModule('@wordpress/core-abilities')
                .then(function(coreAbilities) {
                    if (coreAbilities && typeof coreAbilities.initialize === 'function') {
                        return coreAbilities.initialize();
                    }
                    if (coreAbilities && coreAbilities.ready && typeof coreAbilities.ready.then === 'function') {
                        return coreAbilities.ready;
                    }
                    return null;
                })
                .then(function() {
                    return importModule('@wordpress/abilities');
                })
                .then(function(abilities) {
                    if (
                        !abilities ||
                        typeof abilities.getAbilities !== 'function' ||
                        typeof abilities.getAbility !== 'function' ||
                        typeof abilities.executeAbility !== 'function'
                    ) {
                        throw new Error('Client Abilities API is incomplete');
                    }
                    return abilities;
                })
                .catch(function(error) {
                    // WP 6.9 COMPAT: Missing Core client ability modules fall back to admin-ajax.
                    self.clientAbilitiesUnavailable = true;
                    console.warn('[AI Assistant] Client Abilities API unavailable; using server fallback.', error);
                    return null;
                });

            return this.clientAbilitiesPromise;
        },

        executeAbilityTool: function(toolCall) {
            var self = this;
            var toolName = toolCall.name || toolCall.tool;
            var args = toolCall.arguments || {};
            // WP 6.9 COMPAT: Keep true while the PHP registry can contain abilities
            // that are not present in the client-side registry.
            var fallbackToServer = this.getClientAbilitiesConfig().fallbackToServer !== false;

            return this.getClientAbilitiesApi().then(function(api) {
                if (!api) {
                    // WP 6.9 COMPAT: No client ability package, so run the existing PHP path.
                    return self.executeServerToolAjax(toolCall);
                }

                return self.executeClientAbilityTool(toolCall, api).catch(function(error) {
                    if (fallbackToServer && error && error.aiAssistantFallbackToServer) {
                        // WP 6.9 COMPAT: Ability exists only in PHP/non-REST registry.
                        return self.executeServerToolAjax(toolCall);
                    }

                    return {
                        id: toolCall.id,
                        name: toolName,
                        input: args,
                        result: {
                            error: self.getErrorMessage(error),
                            code: error && error.code ? error.code : 'client_ability_failed'
                        },
                        success: false
                    };
                });
            });
        },

        executeClientAbilityTool: function(toolCall, api) {
            var toolName = toolCall.name || toolCall.tool;
            var args = toolCall.arguments || {};
            var action = this.getNormalizedAbilityAction(toolName, args);
            var abilityId = this.getNormalizedAbilityId(toolName, args);
            var input = this.getNormalizedAbilityInput(toolName, args);

            if (action === 'list') {
                return Promise.resolve(this.createClientAbilityToolResult(
                    toolCall,
                    this.getClientAbilityList(api, args.category || '')
                ));
            }

            if (!abilityId) {
                return Promise.resolve({
                    id: toolCall.id,
                    name: toolName,
                    input: args,
                    result: { error: 'Ability ID is required', code: 'ability_missing' },
                    success: false
                });
            }

            var ability = api.getAbility(abilityId);
            if (!ability) {
                var missing = new Error('Ability not found in client registry: ' + abilityId);
                missing.code = 'ability_not_found';
                // WP 6.9 COMPAT: Let the caller try the PHP ability executor.
                missing.aiAssistantFallbackToServer = true;
                return Promise.reject(missing);
            }

            if (action === 'get') {
                return Promise.resolve(this.createClientAbilityToolResult(
                    toolCall,
                    this.normalizeClientAbilityDetails(ability)
                ));
            }

            if (action !== 'execute') {
                return Promise.resolve({
                    id: toolCall.id,
                    name: toolName,
                    input: args,
                    result: { error: 'Unknown ability action: ' + action, code: 'unknown_ability_action' },
                    success: false
                });
            }

            var self = this;
            return api.executeAbility(abilityId, input).then(function(result) {
                return self.createClientAbilityToolResult(toolCall, {
                    ability: abilityId,
                    success: true,
                    result: result
                });
            });
        },

        createClientAbilityToolResult: function(toolCall, result) {
            return {
                id: toolCall.id,
                name: toolCall.name || toolCall.tool,
                input: toolCall.arguments || {},
                result: result,
                success: true
            };
        },

        getClientAbilityList: function(api, category) {
            var abilities = api.getAbilities() || [];
            if (!Array.isArray(abilities) && typeof abilities === 'object') {
                abilities = Object.keys(abilities).map(function(key) {
                    return abilities[key];
                });
            }

            abilities = Array.isArray(abilities) ? abilities.filter(Boolean) : [];
            if (category) {
                abilities = this.filterClientAbilitiesByCategory(abilities, category);
            }

            return {
                abilities: abilities.map(this.normalizeClientAbilitySummary.bind(this)),
                count: abilities.length,
                filter: category || null
            };
        },

        filterClientAbilitiesByCategory: function(abilities, category) {
            var exact = abilities.filter(function(ability) {
                return (ability.category || '') === category;
            });
            if (exact.length > 0) {
                return exact;
            }

            var query = String(category || '').toLowerCase();
            var byCategory = abilities.filter(function(ability) {
                var abilityCategory = String(ability.category || '').toLowerCase();
                return abilityCategory &&
                    (abilityCategory.indexOf(query) >= 0 || query.indexOf(abilityCategory) >= 0);
            });
            if (byCategory.length > 0) {
                return byCategory;
            }

            return abilities.filter(function(ability) {
                var id = String(ability.name || ability.id || '').toLowerCase();
                var label = String(ability.label || ability.name || '').toLowerCase();
                return id.indexOf(query) >= 0 || label.indexOf(query) >= 0;
            });
        },

        getClientAbilityAnnotations: function(ability) {
            var meta = ability && ability.meta ? ability.meta : {};
            return meta.annotations || ability.annotations || {};
        },

        normalizeClientAbilitySummary: function(ability) {
            ability = ability || {};
            var annotations = this.getClientAbilityAnnotations(ability);
            var id = ability.name || ability.id || '';
            return {
                id: id,
                name: ability.label || id,
                description: ability.description || '',
                category: ability.category || 'uncategorized',
                readonly: !!annotations.readonly,
                destructive: !!annotations.destructive
            };
        },

        normalizeClientAbilityDetails: function(ability) {
            var summary = this.normalizeClientAbilitySummary(ability);
            var annotations = this.getClientAbilityAnnotations(ability);
            return {
                id: summary.id,
                name: summary.name,
                description: summary.description,
                category: summary.category,
                input_schema: ability.input_schema || {},
                output_schema: ability.output_schema || {},
                annotations: {
                    readonly: !!annotations.readonly,
                    destructive: !!annotations.destructive
                },
                instructions: annotations.instructions || ability.instructions || ''
            };
        },

        getNormalizedAbilityAction: function(toolName, args) {
            if (toolName === 'list_abilities') return 'list';
            if (toolName === 'get_ability') return 'get';
            if (toolName === 'execute_ability') return 'execute';
            return args.action || 'list';
        },

        getNormalizedAbilityId: function(toolName, args) {
            return args.ability || '';
        },

        getNormalizedAbilityInput: function(toolName, args) {
            if (toolName === 'execute_ability') {
                return args.arguments || {};
            }
            return args.arguments || {};
        },

        getErrorMessage: function(error) {
            if (!error) return 'Unknown error';
            return error.message || String(error);
        },

        executeServerToolAjax: function(toolCall) {
            var self = this;
            var toolName = toolCall.name || toolCall.tool;

            return new Promise(function(resolve) {
                $.ajax({
                    url: aiAssistantConfig.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'ai_assistant_execute_tool',
                        _wpnonce: aiAssistantConfig.nonce,
                        tool: toolName,
                        arguments: JSON.stringify(toolCall.arguments),
                        conversation_id: self.conversationId || 0
                    },
                    success: function(response) {
                        var errorMessage = '';
                        if (!response.success) {
                            errorMessage = response.data?.message || response.data?.error || 'Unknown error';
                            if (!errorMessage && typeof response.data === 'string') {
                                errorMessage = response.data;
                            }
                            if (!errorMessage) {
                                errorMessage = 'Tool execution failed (no error message provided)';
                                console.warn('[AI Assistant] No error message in response:', response);
                            }
                        }

                        resolve({
                            id: toolCall.id,
                            name: toolName,
                            input: toolCall.arguments,
                            result: response.success ? response.data : { error: errorMessage },
                            success: response.success
                        });
                    },
                    error: function(xhr, status, errorThrown) {
                        var errorMessage = 'AJAX error: ';

                        if (xhr.responseJSON && xhr.responseJSON.data) {
                            errorMessage += xhr.responseJSON.data.message || JSON.stringify(xhr.responseJSON.data);
                        } else if (xhr.responseText) {
                            errorMessage += xhr.responseText.substring(0, 500);
                        } else if (errorThrown) {
                            errorMessage += errorThrown;
                        } else {
                            errorMessage += 'status=' + status + ', HTTP ' + xhr.status;
                        }

                        console.error('[AI Assistant] Tool execution failed:', {
                            tool: toolName,
                            status: status,
                            error: errorThrown,
                            httpStatus: xhr.status,
                            responseText: xhr.responseText,
                            responseJSON: xhr.responseJSON
                        });

                        resolve({
                            id: toolCall.id,
                            name: toolName,
                            input: toolCall.arguments,
                            result: { error: errorMessage },
                            success: false
                        });
                    }
                });
            });
        },

        executeSingleTool: function(toolCall) {
            var toolName = toolCall.name || toolCall.tool;

            if (toolName === 'rest_api') {
                return this.executeRestApi(toolCall);
            }

            if (this.isAbilityToolName(toolName)) {
                return this.executeAbilityTool(toolCall);
            }

            if (toolName === 'get_page_html') {
                return this.executeGetPageHtml(toolCall);
            }

            if (toolName === 'summarize_conversation') {
                return this.executeSummarizeConversation(toolCall);
            }

            if (toolName === 'pick_image') {
                return this.executePickImage(toolCall);
            }

            if (this.canUseFileToolEndpoint(toolName)) {
                return this.executeFileToolEndpoint(toolCall);
            }

            return this.executeServerToolAjax(toolCall);
        },

        isAbilityExecutionToolCall: function(toolCall) {
            var toolName = toolCall.name || toolCall.tool;
            var args = toolCall.arguments || {};
            return (
                (toolName === 'ability' && args.action === 'execute') ||
                toolName === 'execute_ability'
            );
        },

        getToolCallName: function(toolCall) {
            return (toolCall && (toolCall.name || toolCall.tool)) || '';
        },

        getRestApiAutoApprovalPattern: function(args) {
            args = args || {};
            var method = (args.method || 'GET').toUpperCase();
            if (method === 'GET' || method === 'OPTIONS') {
                return '';
            }
            return method + ' ' + (args.path || '/');
        },

        getAutoApprovalKey: function(toolCall) {
            var toolName = this.getToolCallName(toolCall);
            var args = (toolCall && toolCall.arguments) || {};

            if (
                ((toolName === 'ability' && args.action === 'execute') || toolName === 'execute_ability') &&
                args.ability
            ) {
                return 'ability:' + args.ability;
            }

            if (toolName === 'rest_api') {
                var restApiPattern = this.getRestApiAutoApprovalPattern(args);
                if (restApiPattern) {
                    return 'rest_api:' + restApiPattern;
                }
            }

            return '';
        },

        isActionAutoApproved: function(action) {
            var toolName = this.getToolCallName(action);
            var args = (action && action.arguments) || {};
            return this.isAbilityAutoApproved({ name: toolName, arguments: args }) ||
                this.isRestApiAutoApproved({ name: toolName, arguments: args });
        },

        prepareActionsForApproval: function(actions) {
            var self = this;
            var checks = actions.map(function(action) {
                if (!self.isAbilityExecutionToolCall({
                    name: action.tool,
                    arguments: action.arguments
                })) {
                    return Promise.resolve({ action: action });
                }

                var actionArgs = action.arguments || {};
                var abilityId = actionArgs.ability;
                return self.fetchAbilityDetailsForApproval(abilityId).then(function(result) {
                    if (result.success) {
                        action.abilityDetails = result.details;
                        if (self.toolCardsState && self.toolCardsState[action.id]) {
                            self.toolCardsState[action.id].abilityDetails = result.details;
                        }
                        return { action: action };
                    }

                    var message = result.message || ('Ability not found: ' + abilityId);
                    self.setToolCardState(action.id, 'error', { message: message });
                    return {
                        deniedResult: {
                            id: action.id,
                            name: action.tool,
                            input: action.arguments,
                            result: {
                                error: message,
                                code: result.code || 'ability_preflight_failed',
                                instruction: 'Call ability:list and choose an existing ability before requesting execution.'
                            },
                            success: false
                        }
                    };
                });
            });

            return Promise.all(checks).then(function(results) {
                var approvalActions = [];
                var deniedResults = [];

                results.forEach(function(result) {
                    if (result.action) {
                        approvalActions.push(result.action);
                    } else if (result.deniedResult) {
                        deniedResults.push(result.deniedResult);
                    }
                });

                return {
                    actions: approvalActions,
                    deniedResults: deniedResults
                };
            });
        },

        fetchAbilityDetailsForApproval: function(abilityId) {
            return new Promise(function(resolve) {
                if (!abilityId) {
                    resolve({
                        success: false,
                        code: 'ability_missing',
                        message: 'Ability ID is required'
                    });
                    return;
                }

                $.ajax({
                    url: aiAssistantConfig.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'ai_assistant_get_ability_details',
                        _wpnonce: aiAssistantConfig.nonce,
                        ability: abilityId
                    },
                    success: function(response) {
                        if (response && response.success) {
                            resolve({
                                success: true,
                                details: response.data || {}
                            });
                            return;
                        }

                        var data = response && response.data;
                        resolve({
                            success: false,
                            code: data && data.code ? data.code : 'ability_preflight_failed',
                            message: data && data.message ? data.message : ('Ability not found: ' + abilityId)
                        });
                    },
                    error: function(xhr) {
                        var data = xhr.responseJSON && xhr.responseJSON.data;
                        var message = data && data.message ? data.message : ('Ability not found: ' + abilityId);
                        resolve({
                            success: false,
                            code: data && data.code ? data.code : 'ability_preflight_failed',
                            message: message
                        });
                    }
                });
            });
        },

        executePickImage: function(toolCall) {
            var self = this;
            var args = toolCall.arguments || {};

            return new Promise(function(resolve) {
                if (!self.renderImagePicker) {
                    resolve({
                        id: toolCall.id,
                        name: 'pick_image',
                        input: args,
                        result: { error: 'Image picker unavailable' },
                        success: false
                    });
                    return;
                }

                self.renderImagePicker(toolCall.id, args, function(selection, success) {
                    resolve({
                        id: toolCall.id,
                        name: 'pick_image',
                        input: args,
                        result: selection,
                        success: success !== false
                    });
                });
            });
        },

        canUseFileToolEndpoint: function(toolName) {
            var config = typeof aiAssistantConfig !== 'undefined' ? aiAssistantConfig : {};
            var fileTools = Array.isArray(config.fileEndpointTools) ? config.fileEndpointTools : [];

            return !!(
                config &&
                config.fileToolsUrl &&
                config.fileToolsToken &&
                fileTools.indexOf(toolName) >= 0
            );
        },

        executeFileToolEndpoint: function(toolCall) {
            var self = this;
            var toolName = toolCall.name || toolCall.tool;
            var args = toolCall.arguments || {};
            var mutatingFileTools = ['write_file', 'edit_file', 'delete_file'];

            return fetch(aiAssistantConfig.fileToolsUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    token: aiAssistantConfig.fileToolsToken,
                    tool: toolName,
                    arguments: args,
                    conversation_id: this.conversationId || 0
                })
            }).then(function(response) {
                return response.text().then(function(text) {
                    var payload = null;
                    try {
                        payload = JSON.parse(text);
                    } catch (e) {
                        return {
                            id: toolCall.id,
                            name: toolName,
                            input: args,
                            result: {
                                error: 'File tool endpoint failed: HTTP ' + response.status + ' non-JSON response: ' + text.substring(0, 500)
                            },
                            success: false
                        };
                    }

                    if (payload && payload.success) {
                        var result = payload.data || {};
                        if (result && typeof result === 'object') {
                            result.transport = 'direct_file_endpoint';
                        }
                        if (mutatingFileTools.indexOf(toolName) >= 0 && self.checkWordPressRecovery) {
                            self.checkWordPressRecovery();
                        }
                        return {
                            id: toolCall.id,
                            name: toolName,
                            input: args,
                            result: result,
                            success: true
                        };
                    }

                    var message = payload && payload.data && (payload.data.message || payload.data.error);
                    if (!message) {
                        message = 'HTTP ' + response.status;
                    }

                    return {
                        id: toolCall.id,
                        name: toolName,
                        input: args,
                        result: {
                            error: 'File tool endpoint failed: ' + message
                        },
                        success: false
                    };
                });
            }).catch(function(error) {
                return {
                    id: toolCall.id,
                    name: toolName,
                    input: args,
                    result: {
                        error: 'File tool endpoint failed: ' + error.message
                    },
                    success: false
                };
            });
        },

        executeGetPageHtml: function(toolCall) {
            var args = toolCall.arguments || {};
            var selector = args.selector || 'body';
            var maxLength = args.max_length || 5000;

            var isAiAssistantElement = function(el) {
                if (!el) return false;
                if (el.id && el.id.indexOf('ai-assistant') === 0) return true;
                if (el.id === 'ai-conversation-modal') return true;
                if (el.className && typeof el.className === 'string' && el.className.indexOf('ai-assistant') >= 0) return true;
                return false;
            };

            var removeAiAssistantElements = function(container) {
                var aiElements = container.querySelectorAll('[id^="ai-assistant"], [class*="ai-assistant"], #ai-conversation-modal');
                aiElements.forEach(function(el) { el.remove(); });
            };

            return new Promise(function(resolve) {
                try {
                    var elements = document.querySelectorAll(selector);
                    var results = [];
                    var totalLength = 0;
                    var skippedCount = 0;

                    if (elements.length === 0) {
                        resolve({
                            id: toolCall.id,
                            name: 'get_page_html',
                            input: args,
                            result: {
                                error: 'No elements found matching selector: ' + selector,
                                selector: selector,
                                url: window.location.href
                            },
                            success: false
                        });
                        return;
                    }

                    elements.forEach(function(el, index) {
                        if (totalLength >= maxLength * 3) return;

                        if (isAiAssistantElement(el)) {
                            skippedCount++;
                            return;
                        }

                        var html;
                        if (el.tagName === 'BODY' || el.tagName === 'HTML' || el.id === 'wpwrap' || el.id === 'wpcontent') {
                            var clone = el.cloneNode(true);
                            removeAiAssistantElements(clone);
                            html = clone.outerHTML;
                        } else {
                            html = el.outerHTML;
                        }

                        if (html.length > maxLength) {
                            html = html.substring(0, maxLength) + '\n... (truncated, ' + (html.length - maxLength) + ' more chars)';
                        }
                        totalLength += html.length;

                        results.push({
                            index: index,
                            tagName: el.tagName.toLowerCase(),
                            id: el.id || null,
                            className: el.className || null,
                            html: html
                        });
                    });

                    resolve({
                        id: toolCall.id,
                        name: 'get_page_html',
                        input: args,
                        result: {
                            selector: selector,
                            url: window.location.href,
                            title: document.title,
                            matchCount: elements.length - skippedCount,
                            elements: results
                        },
                        success: true
                    });
                } catch (e) {
                    resolve({
                        id: toolCall.id,
                        name: 'get_page_html',
                        input: args,
                        result: {
                            error: 'Invalid selector or error: ' + e.message,
                            selector: selector
                        },
                        success: false
                    });
                }
            });
        },

        executeRestApi: function(toolCall) {
            var self = this;
            var args = toolCall.arguments || {};
            var method = (args.method || 'GET').toUpperCase();
            var path = args.path || '/';

            var params = args.params || null;
            var body = args.body || null;

            var baseUrl = (aiAssistantConfig.restApiUrl || '').replace(/\/$/, '');
            var url = baseUrl + path;
            if (params && Object.keys(params).length > 0) {
                url += '?' + new URLSearchParams(params).toString();
            }

            var fetchOptions = {
                method: method,
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': aiAssistantConfig.restApiNonce
                }
            };

            if (body && method !== 'GET' && method !== 'HEAD') {
                fetchOptions.body = JSON.stringify(body);
            }

            return fetch(url, fetchOptions).then(function(response) {
                var status = response.status;
                return response.json().then(function(data) {
                    if (!response.ok) {
                        return {
                            id: toolCall.id,
                            name: 'rest_api',
                            input: args,
                            result: { error: data.message || 'Request failed', status: status, data: data },
                            success: false
                        };
                    }

                    // Simplify root discovery response — full route definitions are too large
                    if ((method === 'GET' || method === 'OPTIONS') && path === '/') {
                        data = {
                            namespaces: data.namespaces || [],
                            routes: Object.keys(data.routes || {})
                        };
                    } else {
                        data = self.enrichRestApiResult(data, method, path);
                    }

                    return {
                        id: toolCall.id,
                        name: 'rest_api',
                        input: args,
                        result: data,
                        success: true
                    };
                });
            }).catch(function(error) {
                return {
                    id: toolCall.id,
                    name: 'rest_api',
                    input: args,
                    result: { error: error.message },
                    success: false
                };
            });
        },

        enrichRestApiResult: function(data, method, path) {
            if (!data || typeof data !== 'object' || Array.isArray(data)) {
                return data;
            }

            var upperMethod = (method || 'GET').toUpperCase();
            if (['POST', 'PUT', 'PATCH'].indexOf(upperMethod) < 0 || !data.id) {
                return data;
            }

            var routeMatch = String(path || '').match(/^\/wp\/v2\/(posts|pages|media)(?:\/\d+)?(?:\/.*)?$/);
            if (!routeMatch) {
                return data;
            }

            var config = typeof aiAssistantConfig !== 'undefined' ? aiAssistantConfig : {};
            var adminBase = (config.adminUrl || '').replace(/\/$/, '');
            if (!adminBase && config.ajaxUrl) {
                adminBase = String(config.ajaxUrl).replace(/\/admin-ajax\.php(?:\?.*)?$/, '');
            }

            if (adminBase && !data.edit_url) {
                data.edit_url = adminBase + '/post.php?post=' + encodeURIComponent(data.id) + '&action=edit';
            }

            if (data.link && !data.view_url) {
                data.view_url = data.link;
            }

            return data;
        },

        executeSummarizeConversation: function(toolCall) {
            var self = this;
            var args = toolCall.arguments || {};
            var targetConversationId = args.conversation_id || this.conversationId;

            return new Promise(function(resolve) {
                if (!targetConversationId || targetConversationId <= 0) {
                    resolve({
                        id: toolCall.id,
                        name: 'summarize_conversation',
                        input: args,
                        result: { error: 'No conversation to summarize. Save the conversation first.' },
                        success: false
                    });
                    return;
                }

                $.ajax({
                    url: aiAssistantConfig.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'ai_assistant_get_conversation_for_summary',
                        _wpnonce: aiAssistantConfig.nonce,
                        conversation_id: targetConversationId
                    },
                    success: function(response) {
                        if (!response.success) {
                            resolve({
                                id: toolCall.id,
                                name: 'summarize_conversation',
                                input: args,
                                result: { error: response.data?.message || 'Failed to load conversation' },
                                success: false
                            });
                            return;
                        }

                        var convData = response.data;

                        if (convData.existing_summary) {
                            resolve({
                                id: toolCall.id,
                                name: 'summarize_conversation',
                                input: args,
                                result: {
                                    conversation_id: targetConversationId,
                                    title: convData.title,
                                    summary: convData.existing_summary,
                                    message: 'Existing summary retrieved'
                                },
                                success: true
                            });
                            return;
                        }

                        self.generateConversationSummary(convData).then(function(summary) {
                            $.ajax({
                                url: aiAssistantConfig.ajaxUrl,
                                type: 'POST',
                                data: {
                                    action: 'ai_assistant_save_summary',
                                    _wpnonce: aiAssistantConfig.nonce,
                                    conversation_id: targetConversationId,
                                    summary: summary
                                },
                                success: function() {
                                    resolve({
                                        id: toolCall.id,
                                        name: 'summarize_conversation',
                                        input: args,
                                        result: {
                                            conversation_id: targetConversationId,
                                            title: convData.title,
                                            summary: summary,
                                            message: 'Summary generated and saved'
                                        },
                                        success: true
                                    });
                                },
                                error: function() {
                                    resolve({
                                        id: toolCall.id,
                                        name: 'summarize_conversation',
                                        input: args,
                                        result: {
                                            conversation_id: targetConversationId,
                                            summary: summary,
                                            message: 'Summary generated but failed to save'
                                        },
                                        success: true
                                    });
                                }
                            });
                        }).catch(function(error) {
                            resolve({
                                id: toolCall.id,
                                name: 'summarize_conversation',
                                input: args,
                                result: { error: 'Failed to generate summary: ' + error.message },
                                success: false
                            });
                        });
                    },
                    error: function() {
                        resolve({
                            id: toolCall.id,
                            name: 'summarize_conversation',
                            input: args,
                            result: { error: 'Failed to load conversation data' },
                            success: false
                        });
                    }
                });
            });
        },

        isAbilityAutoApproved: function(toolCall) {
            var args = toolCall.arguments || {};
            if (toolCall.name === 'ability' && args.action !== 'execute') return false;
            if (toolCall.name !== 'ability' && toolCall.name !== 'execute_ability') return false;
            if (!args.ability) return false;
            if (this.isAbilityReadonly(toolCall)) return true;
            var autoApproved = (window.aiAssistantConfig && window.aiAssistantConfig.autoApprovedAbilities) || [];
            if (autoApproved === '*') return true;
            if (!Array.isArray(autoApproved)) return false;
            return autoApproved.indexOf(args.ability) >= 0;
        },

        isAbilityReadonly: function(toolCall) {
            var args = toolCall.arguments || {};
            if (toolCall.name === 'ability' && args.action !== 'execute') return false;
            if (toolCall.name !== 'ability' && toolCall.name !== 'execute_ability') return false;
            if (!args.ability) return false;
            var enabled = (window.aiAssistantConfig && window.aiAssistantConfig.enabledTools) || [];
            if (enabled.indexOf('execute_ability') < 0) return false;
            var readonly = (window.aiAssistantConfig && window.aiAssistantConfig.readonlyAbilities) || [];
            return readonly.indexOf(args.ability) >= 0;
        },

        saveAutoApprovedAbility: function(abilityId) {
            var autoApproved = (window.aiAssistantConfig && window.aiAssistantConfig.autoApprovedAbilities) || [];
            if (autoApproved === '*') return;
            if (!Array.isArray(autoApproved)) autoApproved = [];
            if (autoApproved.indexOf(abilityId) < 0) {
                autoApproved.push(abilityId);
                window.aiAssistantConfig.autoApprovedAbilities = autoApproved;
            }
            $.post(aiAssistantConfig.ajaxUrl, {
                action: 'ai_assistant_toggle_auto_approve_ability',
                _wpnonce: aiAssistantConfig.nonce,
                ability: abilityId,
                approved: 1
            });
        },

        isRestApiAutoApproved: function(toolCall) {
            if (toolCall.name !== 'rest_api') return false;
            var args = toolCall.arguments || {};
            var method = (args.method || 'GET').toUpperCase();
            if (method === 'GET' || method === 'OPTIONS') return true;
            var pattern = this.getRestApiAutoApprovalPattern(args);
            var autoApproved = (window.aiAssistantConfig && window.aiAssistantConfig.autoApprovedRestApis) || [];
            return autoApproved.indexOf(pattern) >= 0;
        },

        saveAutoApprovedRestApi: function(pattern) {
            var autoApproved = (window.aiAssistantConfig && window.aiAssistantConfig.autoApprovedRestApis) || [];
            if (autoApproved.indexOf(pattern) < 0) {
                autoApproved.push(pattern);
                window.aiAssistantConfig.autoApprovedRestApis = autoApproved;
            }
            $.post(aiAssistantConfig.ajaxUrl, {
                action: 'ai_assistant_toggle_auto_approve_rest_api',
                _wpnonce: aiAssistantConfig.nonce,
                pattern: pattern,
                approved: 1
            });
        },

        // Accumulate tool results until all tools are resolved
        pendingToolResults: [],
        currentProvider: null,
        streamComplete: false,
        executingToolCount: 0,
        pendingToolChecks: 0,
        processedToolIds: {},

        // Process a single tool immediately when it finishes streaming
        processToolCallImmediate: function(toolId, toolName, toolArgs, provider) {
            var self = this;
            var destructiveTools = this.getDestructiveTools();

            toolArgs = this.normalizeToolArguments(toolName, toolArgs || {});

            this.currentProvider = provider;
            this.processedToolIds[toolId] = true;

            // Update card description
            this.updateToolCardDescription(toolId, toolName, toolArgs);

            if (toolName === 'pick_image') {
                if (this.pickImageToolCallInCurrentResponse && this.pickImageToolCallInCurrentResponse !== toolId) {
                    var pickImageResult = this.getMultiplePickImageToolResult(toolId, toolArgs);
                    this.setToolCardState(toolId, 'error', { message: pickImageResult.result.error });
                    this.pendingToolResults.push(pickImageResult);
                    this.checkAllToolsResolved();
                    return;
                }
                this.pickImageToolCallInCurrentResponse = toolId;
            }

            // Determine if needs confirmation
            var needsConfirm = !this.autoApproveMode && !this.yoloMode && destructiveTools.indexOf(toolName) >= 0 &&
                 !(toolName === 'ability' && toolArgs && toolArgs.action !== 'execute') &&
                 !this.isAbilityAutoApproved({ name: toolName, arguments: toolArgs }) &&
                 !this.isRestApiAutoApproved({ name: toolName, arguments: toolArgs });

            if (needsConfirm) {
                var action = {
                    id: toolId,
                    tool: toolName,
                    arguments: toolArgs,
                    description: this.getActionDescription(toolName, toolArgs),
                    provider: provider
                };

                if (this.isAbilityExecutionToolCall({ name: toolName, arguments: toolArgs })) {
                    this.setToolCardState(toolId, 'checking');
                    this.pendingToolChecks++;
                    this.prepareActionsForApproval([action]).then(function(preflight) {
                        self.pendingToolChecks = Math.max(0, (self.pendingToolChecks || 0) - 1);
                        if (preflight.deniedResults.length > 0) {
                            self.pendingToolResults = self.pendingToolResults.concat(preflight.deniedResults);
                            self.checkAllToolsResolved();
                            return;
                        }

                        if (preflight.actions.length === 0) {
                            self.setLoading(false);
                            self.checkAllToolsResolved();
                            return;
                        }

                        var approvedAction = preflight.actions[0];
                        if (self.isActionAutoApproved(approvedAction)) {
                            self.executeApprovedActions([approvedAction], provider);
                            return;
                        }

                        self.setToolCardState(toolId, 'pending');
                        self.pendingActions.push(approvedAction);
                        if (self.pendingActions.length > 1) {
                            self.showPendingActionsHeader();
                        }
                        self.showToolApprovalModal();
                        self.setLoading(false);
                    }).catch(function(error) {
                        self.pendingToolChecks = Math.max(0, (self.pendingToolChecks || 0) - 1);
                        self.setToolCardState(toolId, 'error', { message: error.message || 'Ability check failed' });
                        self.pendingToolResults.push({
                            id: toolId,
                            name: toolName,
                            input: toolArgs,
                            result: {
                                error: error.message || 'Ability check failed',
                                code: 'ability_preflight_failed'
                            },
                            success: false
                        });
                        self.checkAllToolsResolved();
                    });
                    return;
                }

                this.setToolCardState(toolId, 'pending');
                this.pendingActions.push(action);
                if (this.pendingActions.length > 1) {
                    this.showPendingActionsHeader();
                }
                this.showToolApprovalModal();
                this.setLoading(false);
            } else {
                this.setToolCardState(toolId, 'executing');
                this.executingToolCount++;
                this.executeSingleTool({ id: toolId, name: toolName, arguments: toolArgs }).then(function(result) {
                    return self.verifyPluginFileMutationResults([result]);
                }).then(function(results) {
                    var result = results[0];
                    self.executingToolCount--;
                    if (result.success) {
                        var successOptions = result.name === 'navigate'
                            ? { message: 'Suggestion shown' }
                            : { output: result.result };
                        self.setToolCardState(result.id, 'completed', successOptions);
                    } else {
                        self.setToolCardState(result.id, 'error', { message: result.result?.error || 'Failed', output: result.result });
                    }
                    if (self.notifyToolCallCallbacks) {
                        self.notifyToolCallCallbacks(result, provider);
                    }
                    self.pendingToolResults.push(result);
                    self.checkAllToolsResolved();
                }).catch(function(error) {
                    self.executingToolCount--;
                    self.setToolCardState(toolId, 'error', { message: error.message || 'Tool failed' });
                    self.pendingToolResults.push({
                        id: toolId,
                        name: toolName,
                        input: toolArgs,
                        result: { error: error.message || 'Tool failed' },
                        success: false
                    });
                    self.checkAllToolsResolved();
                });
            }
        },

        checkAllToolsResolved: function() {
            if (!this.streamComplete) return;
            if ((this.pendingToolChecks || 0) > 0) return;
            if (this.executingToolCount > 0) return;
            if (this.pendingActions.length > 0) return;

            // All tools resolved, send results to LLM
            this.handleToolResults([], this.currentProvider);
        },

        getNavigationSuggestionContent: function(result) {
            var text = result && (result.link_text || result.label);
            text = String(text || 'Open this page');
            text = text
                .replace(/[\r\n\t]+/g, ' ')
                .replace(/[\[\]\(\)]+/g, ' ')
                .replace(/\s+/g, ' ')
                .trim();
            if (text.length > 80) {
                text = text.substring(0, 77).trim() + '...';
            }

            var markdownUrl = String((result && result.url) || '').replace(/\)/g, '%29');
            return 'You can open [' + (text || 'Open this page') + '](' + markdownUrl + ').';
        },

        getConfiguredToolRoundLimit: function(name, fallback) {
            var limits = (typeof aiAssistantConfig !== 'undefined' && aiAssistantConfig.toolRoundLimits) || {};
            var value = limits[name];
            var parsed = parseInt(value, 10);
            return parsed > 0 ? parsed : fallback;
        },

        isCodingToolRound: function(results) {
            var codingTools = [
                'read_file',
                'write_file',
                'edit_file',
                'delete_file',
                'find',
                'list_directory',
                'search_files',
                'search_content'
            ];

            return (results || []).some(function(result) {
                var toolName = result && (result.name || result.tool);
                if (codingTools.indexOf(toolName) >= 0) {
                    return true;
                }

                var input = result && result.input;
                var ability = input && (input.ability || (input.arguments && input.arguments.ability));
                return (toolName === 'ability' || toolName === 'execute_ability') && ability === 'ai/create-wp-app';
            });
        },

        getMaxToolCallRounds: function() {
            if (this.usesCodingToolWorkflow) {
                return this.getConfiguredToolRoundLimit('coding', 50);
            }

            return this.getConfiguredToolRoundLimit('default', 25);
        },

        getMaxConsecutiveFailedToolRounds: function() {
            return this.getConfiguredToolRoundLimit('consecutiveFailures', 3);
        },

        updateToolRoundProgress: function(results) {
            if (this.isCodingToolRound(results)) {
                this.usesCodingToolWorkflow = true;
            }

            var hasSuccess = (results || []).some(function(result) {
                return !!(result && result.success);
            });

            if (hasSuccess) {
                this.consecutiveFailedToolRounds = 0;
            } else if ((results || []).length > 0) {
                this.consecutiveFailedToolRounds = (this.consecutiveFailedToolRounds || 0) + 1;
            }
        },

        buildToolRoundLimitMessage: function(reason, limit) {
            if (reason === 'failed_rounds') {
                return 'Tool execution has failed for ' + limit + ' consecutive rounds. Stop calling tools and explain what is blocking completion, what changed successfully, and what remains to be done.';
            }

            return 'You have reached the current tool call round limit of ' + limit + ' rounds. Stop calling tools and explain what you accomplished, what remains to be done, and any blockers or failed tool results.';
        },

        maybeStopForToolRoundLimit: function(results) {
            this.updateToolRoundProgress(results);
            this.toolCallRounds++;

            var failedLimit = this.getMaxConsecutiveFailedToolRounds();
            if (failedLimit > 0 && (this.consecutiveFailedToolRounds || 0) >= failedLimit) {
                this.toolCallRounds = 0;
                this.consecutiveFailedToolRounds = 0;
                this.usesCodingToolWorkflow = false;
                this.messages.push(this.createStoredMessage('user', this.buildToolRoundLimitMessage('failed_rounds', failedLimit)));
                return true;
            }

            var roundLimit = this.getMaxToolCallRounds();
            if (this.toolCallRounds >= roundLimit) {
                this.toolCallRounds = 0;
                this.consecutiveFailedToolRounds = 0;
                this.usesCodingToolWorkflow = false;
                this.messages.push(this.createStoredMessage('user', this.buildToolRoundLimitMessage('rounds', roundLimit)));
                return true;
            }

            return false;
        },

        handleToolResults: function(results, provider) {
            var self = this;

            // Store provider for when we finally call the LLM
            this.currentProvider = provider;

            // Accumulate results
            this.pendingToolResults = this.pendingToolResults.concat(results);

            this.deduplicateFileReads(results);

            // Wait for stream to complete (assistant message must be in history first)
            if (!this.streamComplete) {
                this.setLoading(false);
                return;
            }

            // Wait for async approval preflights before deciding whether the user needs to approve.
            if ((this.pendingToolChecks || 0) > 0) {
                this.setLoading(false);
                return;
            }

            // Wait for all tools to finish executing
            if (this.executingToolCount > 0) {
                return;
            }

            // Wait for user to approve/deny pending actions
            if (this.pendingActions && this.pendingActions.length > 0) {
                this.setLoading(false);
                return;
            }

            // All tools resolved - now send all results to the LLM
            var allResults = this.pendingToolResults;
            this.pendingToolResults = [];

            var navigateResult = allResults.find(function(r) {
                return r.name === 'navigate' && r.success && r.result && r.result.url;
            });

            if (provider === 'anthropic') {
                var toolResults = allResults.map(function(r) {
                    return {
                        type: 'tool_result',
                        tool_use_id: r.id,
                        content: JSON.stringify(r.result)
                    };
                });
                this.messages.push(this.createStoredMessage('user', toolResults));
            } else {
                allResults.forEach(function(r) {
                    self.messages.push(self.createStoredMessage('tool', JSON.stringify(r.result), {
                        tool_call_id: r.id
                    }));
                });
            }

            var sentQueuedMessages = this.flushQueuedMessages
                ? this.flushQueuedMessages(provider, {
                    appendToLastToolResultMessage: provider === 'anthropic'
                })
                : false;

            this.updateTokenCount();

            if (navigateResult && !sentQueuedMessages) {
                var suggestionContent = this.getNavigationSuggestionContent(navigateResult.result);
                var suggestionMessage = this.createStoredMessage('assistant', suggestionContent);
                this.messages.push(suggestionMessage);
                this.addMessage('assistant', suggestionContent, null, { timestamp: suggestionMessage._ts });
                this.updateTokenCount();
                this.setLoading(false);
                this.autoSaveConversation();
                return;
            }

            this.autoSaveConversation();

            if (sentQueuedMessages) {
                this.toolCallRounds = 0;
                this.consecutiveFailedToolRounds = 0;
                this.usesCodingToolWorkflow = false;
                this.callLLM();
                return;
            }

            if (this.maybeStopForToolRoundLimit(allResults)) {
                this.callLLM();
                return;
            }

            this.callLLM();
        },

        confirmAction: function(actionId, confirmed) {
            var action = this.pendingActions.find(function(a) {
                return a.id === actionId;
            });

            if (!action) return;

            this.removePendingActions([action]);

            if (confirmed) {
                this.executeApprovedActions([action], action.provider);
            } else {
                this.skipActions([action], action.provider);
            }
        },

        confirmAllActions: function(confirmed) {
            var actions = this.pendingActions.slice();
            if (actions.length === 0) {
                this.showToolApprovalModal();
                return;
            }

            this.removePendingActions(actions);

            if (confirmed) {
                this.executeApprovedActions(actions, actions[0].provider);
            } else {
                this.skipActions(actions, actions[0].provider);
            }
        },

        confirmMatchingAutoApprovedActions: function(actionId) {
            var self = this;
            var clickedAction = (this.pendingActions || []).find(function(action) {
                return action.id === actionId;
            });

            if (!clickedAction) return;

            var autoApprovalKey = this.getAutoApprovalKey(clickedAction);
            var actions = autoApprovalKey
                ? this.pendingActions.filter(function(action) {
                    return self.getAutoApprovalKey(action) === autoApprovalKey;
                })
                : [clickedAction];

            this.removePendingActions(actions);
            this.executeApprovedActions(actions, clickedAction.provider);
        },

        removePendingActions: function(actions) {
            var ids = {};
            actions.forEach(function(action) {
                ids[action.id] = true;
            });

            this.pendingActions = (this.pendingActions || []).filter(function(action) {
                return !ids[action.id];
            });

            this.refreshPendingActionsUI();
        },

        refreshPendingActionsUI: function() {
            if ((this.pendingActions || []).length > 1) {
                this.showPendingActionsHeader();
            } else {
                $('#ai-assistant-pending-actions-header').remove();
            }
            this.showToolApprovalModal();
        },

        executeApprovedActions: function(actions, provider) {
            var self = this;
            if (!actions || actions.length === 0) return;

            var resultProvider = provider || actions[0].provider || this.currentProvider;
            var providersById = {};

            actions.forEach(function(action) {
                providersById[action.id] = action.provider || resultProvider;
                self.setToolCardState(action.id, 'executing');
            });

            this.executingToolCount = (this.executingToolCount || 0) + actions.length;

            var promises = actions.map(function(action) {
                return self.executeSingleTool(action).catch(function(error) {
                    return {
                        id: action.id,
                        name: action.tool || action.name,
                        input: action.arguments,
                        result: { error: error.message || 'Tool failed' },
                        success: false
                    };
                });
            });

            Promise.all(promises).then(function(results) {
                return self.verifyPluginFileMutationResults(results);
            }).then(function(results) {
                return self.verifyActivatedPluginResults(results);
            }).then(function(results) {
                self.executingToolCount = Math.max(0, (self.executingToolCount || 0) - actions.length);
                results.forEach(function(result) {
                    if (result.success) {
                        self.setToolCardState(result.id, 'completed', { output: result.result });
                    } else {
                        var errorMsg = result.result?.error || 'Failed';
                        self.setToolCardState(result.id, 'error', { message: errorMsg, output: result.result });
                    }
                    if (self.notifyToolCallCallbacks) {
                        self.notifyToolCallCallbacks(result, providersById[result.id] || resultProvider);
                    }
                });
                self.handleToolResults(results, resultProvider);
            }).catch(function(error) {
                self.executingToolCount = Math.max(0, (self.executingToolCount || 0) - actions.length);
                var failedResults = actions.map(function(action) {
                    self.setToolCardState(action.id, 'error', { message: error.message || 'Tool failed' });
                    return {
                        id: action.id,
                        name: action.tool || action.name,
                        input: action.arguments,
                        result: { error: error.message || 'Tool failed' },
                        success: false
                    };
                });
                self.handleToolResults(failedResults, resultProvider);
            });
        },

        skipActions: function(actions, provider) {
            var self = this;
            if (!actions || actions.length === 0) return;

            var resultProvider = provider || actions[0].provider || this.currentProvider;
            var skippedResults = actions.map(function(action) {
                self.setToolCardState(action.id, 'skipped');
                return {
                    id: action.id,
                    name: action.tool || action.name,
                    input: action.arguments,
                    result: { skipped: true, message: 'User declined to execute this action' },
                    success: false
                };
            });
            this.handleToolResults(skippedResults, resultProvider);
        },

        showToolApprovalModal: function() {
            if (typeof $ !== 'function') {
                return;
            }

            var self = this;
            var actions = this.pendingActions || [];
            var $overlay = $('#ai-tool-approval-overlay');

            if (actions.length === 0) {
                $overlay.remove();
                return;
            }

            if ($overlay.length === 0) {
                $overlay = $('<div id="ai-tool-approval-overlay" class="ai-tool-approval-overlay" role="dialog" aria-modal="true"></div>');
                var $dialog = $('<div class="ai-tool-approval-dialog" tabindex="-1"></div>');
                var $header = $('<div class="ai-tool-approval-header"></div>');
                var $heading = $('<div class="ai-tool-approval-heading"></div>');
                var $title = $('<strong></strong>');
                var $subtitle = $('<span></span>');
                var $close = $('<button type="button" class="ai-tool-approval-close" aria-label="Keep approval pending">&times;</button>');
                var $body = $('<div class="ai-tool-approval-body"></div>');

                $heading.append($title, $subtitle);
                $header.append($heading, $close);
                $dialog.append($header, $body);
                $overlay.append($dialog);
                $('body').append($overlay);
            }

            var count = actions.length;
            var $dialog = $overlay.find('.ai-tool-approval-dialog');
            var $body = $overlay.find('.ai-tool-approval-body').empty();
            var $title = $overlay.find('.ai-tool-approval-heading strong');
            var $subtitle = $overlay.find('.ai-tool-approval-heading span');

            $title.text(count === 1 ? 'Approval required' : count + ' approvals required');
            $subtitle.text('Review tool requests before the assistant changes the site.');

            actions.forEach(function(action) {
                var $item = $('<div class="ai-tool-approval-item"></div>').attr('data-tool-id', action.id);
                var $summary = $('<div class="ai-tool-approval-summary"></div>');
                var $meta = $('<div class="ai-tool-approval-meta"></div>');
                var $tool = $('<span class="ai-tool-approval-tool"></span>').text(action.tool || 'tool');
                var $desc = $('<span class="ai-tool-approval-desc"></span>').text(action.description || self.getActionDescription(action.tool, action.arguments || {}));
                var $actions = $('<div class="ai-tool-approval-actions"></div>');
                var hasAbilityDetails = !!action.abilityDetails;

                $meta.append($tool, $desc);
                if (hasAbilityDetails) {
                    $desc.append(
                        $('<button type="button" class="ai-ability-info-toggle" aria-label="Show ability details" aria-expanded="false">What\'s this?</button>')
                            .attr('data-tool-id', action.id)
                    );
                }
                $summary.append($meta);

                var args = action.arguments || {};
                var isAbilityExecute = action.tool === 'ability' &&
                    args.action === 'execute' &&
                    args.ability;
                var isRestApiWrite = action.tool === 'rest_api' &&
                    (args.method || 'GET').toUpperCase() !== 'GET';
                var restApiPattern = isRestApiWrite
                    ? (args.method || 'POST').toUpperCase() + ' ' + (args.path || '/')
                    : '';

                $actions.append(
                    $('<button type="button" class="ai-tool-skip ai-skip-btn">Skip</button>').attr('data-tool-id', action.id),
                    $('<button type="button" class="ai-tool-approve ai-approve-btn">Approve</button>').attr('data-tool-id', action.id)
                );

                if (isAbilityExecute) {
                    $actions.append(
                        $('<button type="button" class="ai-tool-approve-always ai-always-approve-btn">Always approve</button>')
                            .attr('data-tool-id', action.id)
                            .attr('data-ability', args.ability)
                    );
                }

                if (isRestApiWrite) {
                    $actions.append(
                        $('<button type="button" class="ai-tool-approve-always ai-always-approve-btn">Always approve</button>')
                            .attr('data-tool-id', action.id)
                            .attr('data-rest-api', restApiPattern)
                    );
                }

                $summary.append($actions);
                $item.append($summary);

                if (hasAbilityDetails) {
                    $item.append(
                        $('<div class="ai-ability-approval-slot" hidden></div>').attr('data-tool-id', action.id)
                    );
                }

                var preview = self.getActionContentPreview(action.tool, args);
                if (preview) {
                    var contentStr = typeof preview.content === 'string' ? preview.content : String(preview.content || '');
                    contentStr = contentStr.trim();
                    var lineCount = (contentStr.match(/\n/g) || []).length + 1;
                    var autoExpand = lineCount <= 5;
                    var $preview = $('<div class="ai-action-preview' + (autoExpand ? ' expanded' : '') + '"></div>')
                        .attr('data-language', preview.language || '')
                        .attr('data-is-edit', preview.isEdit ? '1' : '0');
                    var previewLabel = preview.isEdit ? 'Show changes' : 'Show content';
                    var $toggle = $('<button type="button" class="ai-action-preview-toggle"></button>');
                    $toggle.append(
                        $('<span class="ai-action-preview-icon" aria-hidden="true">&gt;</span>'),
                        document.createTextNode(previewLabel + ' (' + lineCount + ' line' + (lineCount !== 1 ? 's' : '') + ')')
                    );
                    var $content = $('<div class="ai-action-preview-content"><pre class="ai-code-preview"></pre></div>');
                    $preview.append($toggle, $content);
                    $item.append($preview);

                    if (typeof self.highlightCode === 'function') {
                        self.highlightCode($content.find('pre')[0], contentStr, preview.language, preview.isEdit);
                    } else {
                        $content.find('pre').text(contentStr);
                    }
                } else if (args && Object.keys(args).length > 0) {
                    var argsJson = JSON.stringify(args, null, 2);
                    if (argsJson && argsJson !== 'null') {
                        var $details = $('<details class="ai-tool-approval-params"><summary>Parameters</summary><pre></pre></details>');
                        $details.find('pre').text(argsJson);
                        $item.append($details);
                    }
                }

                $body.append($item);
            });

            $overlay.show();
            $dialog.trigger('focus');
        },

        toggleAbilityApprovalDetails: function($button) {
            var toolId = $button.attr('data-tool-id');
            if (!toolId) return;

            var $scope = $button.closest('.ai-tool-approval-item, .ai-tool-card');
            if (!$scope.length) return;

            var $slot = $scope.children('.ai-ability-approval-slot');
            if (!$slot.length) {
                $slot = $('<div class="ai-ability-approval-slot" hidden></div>').attr('data-tool-id', toolId);
                var $actions = $scope.children('.ai-tool-card-actions');
                if ($actions.length) {
                    $slot.insertBefore($actions);
                } else {
                    $scope.append($slot);
                }
            }

            var expanded = $button.attr('aria-expanded') === 'true';
            if (expanded) {
                $button.attr('aria-expanded', 'false');
                $slot.attr('hidden', true).empty();
                return;
            }

            var source = this.pendingActions.find(function(action) {
                return action.id === toolId;
            });
            if (!source && this.toolCardsState && this.toolCardsState[toolId]) {
                source = this.toolCardsState[toolId];
            }
            if (!source || !source.abilityDetails) return;

            var usedArguments = this.getAbilityUsedArguments(source.name || source.tool, source.arguments || {});
            $scope.find('.ai-ability-info-toggle[aria-expanded="true"]').attr('aria-expanded', 'false');
            $button.attr('aria-expanded', 'true');
            $slot.empty().append(this.renderAbilityApprovalDetails(source.abilityDetails, usedArguments));
            $slot.removeAttr('hidden');
        },

        getAbilityUsedArguments: function(toolName, args) {
            args = args || {};
            var values = args.arguments || {};

            if (typeof values === 'string') {
                try {
                    values = JSON.parse(values);
                } catch (e) {
                    values = {};
                }
            }

            return values && typeof values === 'object' && !Array.isArray(values) ? values : {};
        },

        renderAbilityApprovalDetails: function(details, usedArguments) {
            details = details || {};
            usedArguments = usedArguments || {};
            var parametersByName = {};
            (Array.isArray(details.parameters) ? details.parameters : []).forEach(function(parameter) {
                if (parameter && parameter.name) {
                    parametersByName[parameter.name] = parameter;
                }
            });
            var usedNames = Object.keys(usedArguments);
            var $details = $('<div class="ai-ability-approval-details"></div>');
            var $body = $('<div class="ai-ability-approval-body"></div>');
            var $header = $('<div class="ai-ability-info-header"></div>');

            $header.append($('<code></code>').text(details.id || ''));
            if (details.readonly) {
                $header.append($('<span class="ai-ability-badge ai-ability-badge-readonly">Read-only</span>'));
            } else if (details.destructive) {
                $header.append($('<span class="ai-ability-badge ai-ability-badge-destructive">Destructive</span>'));
            }
            if (details.approved) {
                $header.append($('<span class="ai-ability-badge ai-ability-badge-approved">Always approved</span>'));
            }
            $body.append($header);

            if (details.label && details.label !== details.id) {
                $body.append($('<div class="ai-ability-info-label"></div>').text(details.label));
            }

            $body.append(
                $('<p class="description ai-ability-info-description"></p>')
                    .text(details.description || 'No description provided.')
            );

            $body.append($('<div class="ai-ability-params-heading">Parameters</div>'));
            if (!usedNames.length) {
                $body.append($('<p class="description ai-ability-no-params">No parameters used.</p>'));
            } else {
                var $list = $('<div class="ai-ability-param-list"></div>');
                usedNames.forEach(function(name) {
                    var parameter = parametersByName[name] || { name: name, type: 'any' };
                    var value = usedArguments[name];
                    var valueText = typeof value === 'string' ? value : JSON.stringify(value, null, 2);
                    if (valueText === undefined) {
                        valueText = String(value);
                    }
                    var $param = $('<div class="ai-ability-param"></div>');
                    var $head = $('<div class="ai-ability-param-head"></div>');
                    $head.append($('<code></code>').text(name));
                    $head.append($('<span class="ai-ability-param-type"></span>').text(parameter.type || 'any'));
                    if (parameter.required) {
                        $head.append($('<span class="ai-ability-param-required">Required</span>'));
                    }
                    $param.append($head);
                    if (parameter.description) {
                        $param.append($('<div class="description ai-ability-param-description"></div>').text(parameter.description));
                    }
                    $param.append($('<pre class="ai-ability-param-value"></pre>').text(valueText));
                    $list.append($param);
                });
                $body.append($list);
            }

            $details.append($body);
            return $details;
        },

        hideToolApprovalModal: function() {
            if (typeof $ !== 'function') {
                return;
            }

            $('#ai-tool-approval-overlay').hide();
        },

        describeSql: function(sql) {
            var s = sql.trim().replace(/\s+/g, ' ');
            var verb = (s.match(/^(\w+)/) || [])[1] || 'Query';
            verb = verb.toUpperCase();
            var table;
            if (verb === 'SELECT' || verb === 'DELETE') {
                table = (s.match(/\bFROM\s+([\w,\s`"]+?)(?:\s+WHERE|\s+JOIN|\s+LIMIT|\s+ORDER|\s+GROUP|$)/i) || [])[1];
                if (table) table = table.replace(/[`"]/g, '').trim();
            } else if (verb === 'UPDATE') {
                table = (s.match(/^UPDATE\s+([\w`"]+)/i) || [])[1];
                if (table) table = table.replace(/[`"]/g, '').trim();
            } else if (verb === 'INSERT') {
                table = (s.match(/\bINTO\s+([\w`"]+)/i) || [])[1];
                if (table) table = table.replace(/[`"]/g, '').trim();
            } else if (verb === 'DESCRIBE' || verb === 'DESC' || verb === 'SHOW') {
                return s.length > 40 ? s.substring(0, 40) + '...' : s;
            }
            return table ? verb + ' from ' + table : verb;
        },

        getActionDescription: function(toolName, args) {
            switch (toolName) {
                case 'read_file':
                    return 'Read: ' + (args.path || 'unknown');
                case 'write_file':
                    return 'Write: ' + (args.path || 'unknown');
                case 'edit_file':
                    var editCount = args.edits ? args.edits.length : 0;
                    return 'Edit: ' + (args.path || 'unknown') + ' (' + editCount + ' change' + (editCount !== 1 ? 's' : '') + ')';
                case 'delete_file':
                    return 'Delete: ' + (args.path || 'unknown');
                case 'find':
                    if (args.text) {
                        return 'Search for: "' + args.text.substring(0, 30) + (args.text.length > 30 ? '...' : '') + '"';
                    }
                    if (args.glob) {
                        return 'Search files: ' + args.glob;
                    }
                    return 'List: ' + (args.path || 'wp-content');
                // Legacy tool names (backward compat with saved conversations)
                case 'list_directory':
                    return 'List: ' + (args.path || 'wp-content');
                case 'search_files':
                    return 'Search files: ' + (args.pattern || 'unknown');
                case 'search_content':
                    return 'Search for: "' + (args.needle || '').substring(0, 30) + (args.needle && args.needle.length > 30 ? '...' : '') + '"';
                case 'db_query':
                    return this.describeSql(args.sql || '');
                case 'install_plugin':
                    return 'Install plugin: ' + (args.slug || 'unknown') + (args.activate ? ' (+ activate)' : '');
                case 'run_php':
                    return 'Run PHP code';
                case 'rest_api':
                    if (!args.method && !args.path) {
                        return 'REST API request';
                    }
                    return (args.method || 'REST').toUpperCase() + ' ' + (args.path || '(pending path)') +
                           (args.params ? '?' + new URLSearchParams(args.params).toString() : '');
                case 'navigate':
                    return 'Suggest link: ' + (args.link_text || args.url || 'unknown');
                case 'get_page_html':
                    return 'Get page HTML: ' + (args.selector || 'body');
                case 'environment_info':
                    return 'Get environment info';
                case 'ability':
                    if (args.action === 'list') {
                        return 'List abilities' + (args.category ? ' (' + args.category + ')' : '');
                    }
                    if (args.action === 'get') {
                        return args.ability ? 'Get ability: ' + args.ability : 'Get ability…';
                    }
                    if (args.action === 'execute') {
                        var abilityHint = '';
                        if (args.arguments) {
                            abilityHint = args.arguments.username || args.arguments.name ||
                                          args.arguments.query || args.arguments.group_slug || '';
                        }
                        return args.ability
                            ? 'Execute: ' + args.ability + (abilityHint ? ' (' + abilityHint + ')' : '')
                            : 'Execute ability…';
                    }
                    return 'Ability…';
                // Legacy ability tool names
                case 'list_abilities':
                    return 'List abilities' + (args.category ? ' (' + args.category + ')' : '');
                case 'get_ability':
                    return 'Get ability: ' + (args.ability || 'unknown');
                case 'execute_ability':
                    var abilityName = args.ability || 'unknown';
                    var abilityInput = args.arguments || {};
                    var legacyHint = abilityInput.username || abilityInput.name ||
                                      abilityInput.query || abilityInput.group_slug || '';
                    return 'Execute: ' + abilityName + (legacyHint ? ' (' + legacyHint + ')' : '');
                case 'skill':
                    if (args.action === 'list') {
                        return 'List skills' + (args.category ? ' (' + args.category + ')' : '');
                    }
                    return 'Get skill: ' + (args.skill || 'unknown');
                default:
                    return toolName;
            }
        },

        showPendingActionsHeader: function() {
            var $container = this.getToolCardsContainer();
            var $header = $('#ai-assistant-pending-actions-header');

            if ($header.length === 0) {
                $header = $('<div id="ai-assistant-pending-actions-header">' +
                    '<span>' + (aiAssistantConfig.strings?.bulkConfirmTitle || 'Approve Actions') + '</span>' +
                    '<div class="ai-pending-bulk-actions">' +
                    '<button id="ai-confirm-all" class="button button-primary button-small ai-approve-btn">' +
                    (aiAssistantConfig.strings?.approveAll || 'Approve All') + '</button>' +
                    '<button id="ai-skip-all" class="button button-small ai-skip-btn">' +
                    (aiAssistantConfig.strings?.skipAll || 'Skip All') + '</button>' +
                    '</div></div>');
                $container.prepend($header);
            }
            this.scrollToBottom();
        },

        showPendingActions: function(actions) {
            // Legacy function - now uses tool cards instead
            var self = this;

            if (actions.length === 0) {
                return;
            }

            // Ensure tool cards exist for these actions
            actions.forEach(function(action) {
                if (!self.toolCardsState || !self.toolCardsState[action.id]) {
                    self.showToolProgress(action.tool, 0, action.id);
                    self.updateToolCardDescription(action.id, action.tool, action.arguments);
                }
                self.setToolCardState(action.id, 'pending');
            });

            if (actions.length > 1) {
                this.showPendingActionsHeader();
            }
        },

        getLanguageFromPath: function(path) {
            if (!path) return null;
            var ext = path.split('.').pop().toLowerCase();
            var modeMap = {
                'php': 'php',
                'js': 'javascript',
                'jsx': 'jsx',
                'ts': 'javascript',
                'tsx': 'jsx',
                'css': 'css',
                'scss': 'css',
                'less': 'css',
                'html': 'htmlmixed',
                'htm': 'htmlmixed',
                'xml': 'xml',
                'json': 'json',
                'md': 'markdown',
                'sql': 'sql',
                'sh': 'shell',
                'bash': 'shell',
                'yml': 'yaml',
                'yaml': 'yaml'
            };
            return modeMap[ext] || null;
        },

        getActionContentPreview: function(toolName, args) {
            var self = this;
            var content = null;
            var isEdit = false;
            var language = null;

            switch (toolName) {
                case 'write_file':
                case 'append_file':
                    if (args.content) {
                        content = typeof args.content === 'string' ? args.content : JSON.stringify(args.content, null, 2);
                        language = this.getLanguageFromPath(args.path);
                    }
                    break;
                case 'edit_file':
                    isEdit = true;
                    language = this.getLanguageFromPath(args.path);
                    if (args.edits && Array.isArray(args.edits)) {
                        var diffLines = [];
                        args.edits.forEach(function(edit, i) {
                            if (i > 0) diffLines.push('');
                            diffLines.push('--- Edit ' + (i + 1) + ' ---');

                            var search = typeof edit.search === 'string' ? edit.search : String(edit.search || '');
                            var replace = typeof edit.replace === 'string' ? edit.replace : String(edit.replace || '');
                            var diff = self.generateSmartDiff(search, replace);
                            diffLines = diffLines.concat(diff);
                        });
                        content = diffLines.join('\n');
                    }
                    break;
                case 'run_php':
                    if (args.code) {
                        content = typeof args.code === 'string' ? args.code : JSON.stringify(args.code, null, 2);
                        language = 'php';
                    }
                    break;
                case 'execute_ability':
                    if (args.arguments && Object.keys(args.arguments).length > 0) {
                        content = JSON.stringify(args.arguments, null, 2);
                        language = 'json';
                    }
                    break;
                case 'ability':
                    if (args.action === 'execute' && args.arguments && Object.keys(args.arguments).length > 0) {
                        content = JSON.stringify(args.arguments, null, 2);
                        language = 'json';
                    }
                    break;
            }

            if (!content) return null;

            return { content: content, isEdit: isEdit, language: language };
        },

        generateSmartDiff: function(search, replace) {
            var searchLines = search.split('\n');
            var replaceLines = replace.split('\n');
            var result = [];

            var prefixCount = 0;
            while (prefixCount < searchLines.length &&
                   prefixCount < replaceLines.length &&
                   searchLines[prefixCount] === replaceLines[prefixCount]) {
                prefixCount++;
            }

            var suffixCount = 0;
            while (suffixCount < (searchLines.length - prefixCount) &&
                   suffixCount < (replaceLines.length - prefixCount) &&
                   searchLines[searchLines.length - 1 - suffixCount] === replaceLines[replaceLines.length - 1 - suffixCount]) {
                suffixCount++;
            }

            var contextBefore = Math.min(prefixCount, 2);
            var prefixStart = prefixCount - contextBefore;

            if (prefixStart > 0) {
                result.push('  ... (' + prefixStart + ' unchanged lines)');
            }

            for (var i = prefixStart; i < prefixCount; i++) {
                result.push('  ' + searchLines[i]);
            }

            var searchMiddleEnd = searchLines.length - suffixCount;
            for (var i = prefixCount; i < searchMiddleEnd; i++) {
                result.push('- ' + searchLines[i]);
            }

            var replaceMiddleEnd = replaceLines.length - suffixCount;
            for (var i = prefixCount; i < replaceMiddleEnd; i++) {
                result.push('+ ' + replaceLines[i]);
            }

            var contextAfter = Math.min(suffixCount, 2);
            var suffixStart = searchLines.length - suffixCount;

            for (var i = suffixStart; i < suffixStart + contextAfter; i++) {
                result.push('  ' + searchLines[i]);
            }

            if (suffixCount > contextAfter) {
                result.push('  ... (' + (suffixCount - contextAfter) + ' unchanged lines)');
            }

            return result;
        }
    });

})(jQuery);
