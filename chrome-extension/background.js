const SYMFONY_VOICE_API_URL = 'http://127.0.0.1:8000/api/voice/interpret';

let controlledTabId = null;
let assistantTabId = null;
let lastControlledContext = null;

let lastWebsiteMemory = null;
let lastSearchQueryMemory = null;
let lastActionMemory = null;

chrome.runtime.onMessage.addListener((message, sender, sendResponse) => {
    if (!message || !message.type) {
        return;
    }

    if (message.type === 'VOICE_LANGUAGE_CHANGED') {
        chrome.storage.local.set({
            voiceAssistantLanguage: message.language || 'en-US'
        }, function () {
            sendResponse({
                success: true,
                message: 'Voice language saved in extension.'
            });
        });

        return true;
    }

    if (message.type === 'ASSISTANT_TAB_READY') {
        if (sender.tab && sender.tab.id) {
            assistantTabId = sender.tab.id;
        }

        sendResponse({
            success: true,
            message: 'Assistant tab registered.'
        });

        return true;
    }

    if (message.type === 'PAGE_CONTEXT_UPDATE') {
        if (sender.tab && sender.tab.id) {
            const tabUrl = sender.tab.url || '';

            if (controlledTabId === sender.tab.id || isSupportedExternalPage(tabUrl)) {
                controlledTabId = sender.tab.id;
                lastControlledContext = message.context || null;

                const websiteFromContext = detectWebsiteFromContext(lastControlledContext);

                if (websiteFromContext) {
                    lastWebsiteMemory = websiteFromContext;
                }
            }
        }

        sendResponse({
            success: true
        });

        return true;
    }

    if (message.type === 'GET_CONTROLLED_CONTEXT') {
        sendResponse({
            success: true,
            context: buildMemoryContext(message.browserContext || null)
        });

        return true;
    }

    if (message.type === 'PAGE_VOICE_COMMAND') {
        handlePageVoiceCommand(message, sender)
            .then(result => sendResponse(result))
            .catch(error => sendResponse({
                success: false,
                message: error.message || 'Page voice command failed.'
            }));

        return true;
    }

    if (message.type === 'EXECUTE_BROWSER_ACTION') {
        executeBrowserAction(message.command || {})
            .then(result => sendResponse(result))
            .catch(error => sendResponse({
                success: false,
                message: error.message || 'Browser action failed.'
            }));

        return true;
    }
});

chrome.tabs.onRemoved.addListener((tabId) => {
    if (controlledTabId === tabId) {
        controlledTabId = null;
        lastControlledContext = null;
    }

    if (assistantTabId === tabId) {
        assistantTabId = null;
    }
});

async function handlePageVoiceCommand(message, sender) {
    if (sender.tab && sender.tab.id) {
        controlledTabId = sender.tab.id;
    }

    const originalText = message.text || '';
    const language = message.language || 'en-US';
    const browserContext = message.browserContext || lastControlledContext || null;
    const memoryContext = buildMemoryContext(browserContext);

    const apiResult = await interpretVoiceCommandWithSymfony(
        originalText,
        language,
        memoryContext
    );

    if (!apiResult.success) {
        return {
            success: false,
            message: apiResult.speech || apiResult.message || 'I did not understand the command.'
        };
    }

    updateMemoryFromApiResult(apiResult, originalText, browserContext);

    if (apiResult.intent === 'BROWSER_ACTION' && apiResult.extensionCommand) {
        const speechMessage =
            apiResult.speech ||
            apiResult.message ||
            'Executing browser action.';

        const actionResult = await executeBrowserAction(apiResult.extensionCommand);

        return {
            success: actionResult.success !== false,
            message: actionResult.message || speechMessage
        };
    }

    if (apiResult.intent === 'WEBSITE_ACTION' && apiResult.extensionCommand) {
        const speechMessage =
            apiResult.speech ||
            apiResult.message ||
            'Executing website action.';

        const actionResult = await executeBrowserAction(apiResult.extensionCommand);

        return {
            success: actionResult.success !== false,
            message: actionResult.message || speechMessage
        };
    }

    if (apiResult.intent === 'EMAIL_ACTION') {
        return {
            success: apiResult.success !== false,
            message: apiResult.speech || apiResult.message || 'Email action completed.'
        };
    }

    return {
        success: true,
        message: apiResult.speech || apiResult.message || 'Command completed.'
    };
}

async function interpretVoiceCommandWithSymfony(text, language, browserContext) {
    const response = await fetch(SYMFONY_VOICE_API_URL, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        credentials: 'include',
        body: JSON.stringify({
            text: text,
            language: language,
            browserContext: browserContext
        })
    });

    if (!response.ok) {
        throw new Error('Symfony API returned HTTP ' + response.status);
    }

    return await response.json();
}

async function executeBrowserAction(command) {
    const action = normalizeAction(command.action);

    if (!action) {
        throw new Error('No browser action provided.');
    }

    if (
        action === 'SWITCH_TO_ASSISTANT' ||
        action === 'FOCUS_ASSISTANT' ||
        action === 'RETURN_TO_ASSISTANT'
    ) {
        return await switchToAssistantTab();
    }

    if (action === 'OPEN_URL' || action === 'SEARCH') {
        const url = buildUrlForCommand(command, action);
        const tab = await openOrReuseControlledTab(url);

        if (command.website) {
            lastWebsiteMemory = String(command.website).toLowerCase();
        }

        if (command.query) {
            lastSearchQueryMemory = command.query;
        }

        lastActionMemory = action;

        if (command.nextAction && command.nextAction.action) {
            await waitForTabLoad(tab.id);
            await sleep(1800);

            const nextCommand = {
                ...command.nextAction,
                website: command.website || lastWebsiteMemory,
                language: command.language || 'en-US'
            };

            const nextResult = await executeBrowserAction(nextCommand);

            return {
                success: nextResult.success !== false,
                message: nextResult.message || 'Search and follow-up action completed.',
                tabId: tab.id
            };
        }

        return {
            success: true,
            message: action === 'SEARCH' ? 'Search page opened.' : 'Website opened.',
            tabId: tab.id
        };
    }

    const targetTab = await getControlledTab();

    await ensureContentScript(targetTab.id);

    lastActionMemory = action;

    const response = await chrome.tabs.sendMessage(targetTab.id, {
        type: 'RUN_PAGE_ACTION',
        command: {
            ...command,
            action: action,
            language: command.language || 'en-US'
        }
    });

    return response || {
        success: true,
        message: 'Browser action executed.'
    };
}

async function switchToAssistantTab() {
    if (assistantTabId) {
        try {
            const assistantTab = await chrome.tabs.get(assistantTabId);

            if (assistantTab && assistantTab.id) {
                await chrome.tabs.update(assistantTab.id, {
                    active: true
                });

                if (assistantTab.windowId) {
                    await chrome.windows.update(assistantTab.windowId, {
                        focused: true
                    });
                }

                return {
                    success: true,
                    message: 'Returned to the voice assistant.'
                };
            }
        } catch (error) {
            assistantTabId = null;
        }
    }

    const tabs = await chrome.tabs.query({});

    const assistantTab = tabs.find(tab => {
        const url = tab.url || '';

        return (
            url.includes('127.0.0.1:8000') ||
            url.includes('localhost:8000') ||
            url.includes('voice-assistant.local')
        );
    });

    if (assistantTab && assistantTab.id) {
        assistantTabId = assistantTab.id;

        await chrome.tabs.update(assistantTab.id, {
            active: true
        });

        if (assistantTab.windowId) {
            await chrome.windows.update(assistantTab.windowId, {
                focused: true
            });
        }

        return {
            success: true,
            message: 'Returned to the voice assistant.'
        };
    }

    return {
        success: false,
        message: 'I could not find the voice assistant tab. Please open the Symfony assistant page first.'
    };
}

function buildMemoryContext(browserContext) {
    const websiteFromContext = detectWebsiteFromContext(browserContext);

    return {
        ...(browserContext || {}),
        memory: {
            lastWebsite: lastWebsiteMemory,
            currentWebsite: websiteFromContext,
            activeWebsite: websiteFromContext || lastWebsiteMemory,
            lastSearchQuery: lastSearchQueryMemory,
            lastAction: lastActionMemory,
            hasAssistantTab: assistantTabId !== null
        }
    };
}

function updateMemoryFromApiResult(apiResult, text, browserContext) {
    const websiteFromContext = detectWebsiteFromContext(browserContext);
    const websiteFromResult = apiResult.website || apiResult.extensionCommand?.website || null;

    if (websiteFromResult) {
        lastWebsiteMemory = String(websiteFromResult).toLowerCase();
    } else if (websiteFromContext) {
        lastWebsiteMemory = websiteFromContext;
    }

    const query = apiResult.query || apiResult.extensionCommand?.query || null;

    if (query) {
        lastSearchQueryMemory = query;
    }

    const action = apiResult.action || apiResult.extensionCommand?.action || null;

    if (action) {
        lastActionMemory = String(action).toUpperCase();
    }
}

function normalizeAction(action) {
    if (!action) {
        return null;
    }

    const normalized = String(action).trim().toUpperCase();

    if (normalized === 'OPEN' || normalized === 'OPEN-URL' || normalized === 'OPEN_URL') {
        return 'OPEN_URL';
    }

    if (normalized === 'SEARCH_URL' || normalized === 'SEARCH-URL') {
        return 'SEARCH';
    }

    return normalized;
}

function buildUrlForCommand(command, action) {
    if (action === 'OPEN_URL') {
        if (command.url) {
            return command.url;
        }

        const website = String(command.website || lastWebsiteMemory || '').toLowerCase();

        const urls = {
            youtube: 'https://www.youtube.com',
            google: 'https://www.google.com',
            facebook: 'https://www.facebook.com',
            gmail: 'https://mail.google.com'
        };

        if (!urls[website]) {
            throw new Error('Unsupported website.');
        }

        lastWebsiteMemory = website;

        return urls[website];
    }

    if (action === 'SEARCH') {
        const rawQuery = command.query || '';

        if (!rawQuery.trim()) {
            throw new Error('Search query is missing.');
        }

        const query = encodeURIComponent(rawQuery);
        const website = String(command.website || lastWebsiteMemory || '').toLowerCase();

        const urls = {
            youtube: `https://www.youtube.com/results?search_query=${query}`,
            google: `https://www.google.com/search?q=${query}`,
            facebook: `https://www.facebook.com/search/top?q=${query}`
        };

        if (!urls[website]) {
            throw new Error('Unsupported website search.');
        }

        lastWebsiteMemory = website;
        lastSearchQueryMemory = rawQuery;

        return urls[website];
    }

    throw new Error('Cannot build URL for action: ' + action);
}

async function openOrReuseControlledTab(url) {
    if (controlledTabId) {
        try {
            const existingTab = await chrome.tabs.get(controlledTabId);

            if (existingTab && existingTab.id) {
                const updatedTab = await chrome.tabs.update(existingTab.id, {
                    url: url,
                    active: true
                });

                controlledTabId = updatedTab.id;

                return updatedTab;
            }
        } catch (error) {
            controlledTabId = null;
        }
    }

    const createdTab = await chrome.tabs.create({
        url: url,
        active: true
    });

    controlledTabId = createdTab.id;

    return createdTab;
}

async function getControlledTab() {
    if (controlledTabId) {
        try {
            const tab = await chrome.tabs.get(controlledTabId);

            if (tab && tab.id) {
                return tab;
            }
        } catch (error) {
            controlledTabId = null;
        }
    }

    const tabs = await chrome.tabs.query({
        active: true,
        currentWindow: true
    });

    const activeTab = tabs[0];

    if (!activeTab || !activeTab.id) {
        throw new Error('No active or controlled browser tab found.');
    }

    controlledTabId = activeTab.id;

    return activeTab;
}

function isSupportedExternalPage(url) {
    const value = String(url || '').toLowerCase();

    return (
        value.includes('youtube.com') ||
        value.includes('google.com') ||
        value.includes('facebook.com') ||
        value.includes('mail.google.com')
    );
}

async function ensureContentScript(tabId) {
    try {
        await chrome.tabs.sendMessage(tabId, {
            type: 'PING_PAGE_SCRIPT'
        });
    } catch (error) {
        await chrome.scripting.executeScript({
            target: {
                tabId: tabId
            },
            files: ['content-page.js']
        });
    }
}

function detectWebsiteFromContext(context) {
    if (!context || !context.website) {
        return null;
    }

    const website = String(context.website).toLowerCase();

    if (website.includes('youtube.com')) {
        return 'youtube';
    }

    if (website.includes('google.com')) {
        return 'google';
    }

    if (website.includes('facebook.com')) {
        return 'facebook';
    }

    if (website.includes('mail.google.com')) {
        return 'gmail';
    }

    return null;
}

function sleep(milliseconds) {
    return new Promise(resolve => setTimeout(resolve, milliseconds));
}

function waitForTabLoad(tabId) {
    return new Promise(resolve => {
        const timeout = setTimeout(() => {
            chrome.tabs.onUpdated.removeListener(listener);
            resolve();
        }, 8000);

        function listener(updatedTabId, changeInfo) {
            if (updatedTabId === tabId && changeInfo.status === 'complete') {
                clearTimeout(timeout);
                chrome.tabs.onUpdated.removeListener(listener);
                resolve();
            }
        }

        chrome.tabs.onUpdated.addListener(listener);
    });
}