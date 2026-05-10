const DEFAULT_ASSISTANT_ORIGIN = 'http://localhost:8000';
const FALLBACK_ASSISTANT_ORIGINS = [
    'http://localhost:8000',
    'http://127.0.0.1:8000'
];

let assistantOrigin = DEFAULT_ASSISTANT_ORIGIN;

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

            const origin = getOriginFromUrl(sender.tab.url || '');

            if (origin) {
                assistantOrigin = origin;
            }
        }

        sendResponse({
            success: true,
            message: 'Assistant tab registered.',
            assistantOrigin: assistantOrigin
        });

        return true;
    }

    if (message.type === 'PAGE_CONTEXT_UPDATE') {
        if (sender.tab && sender.tab.id) {
            const tabUrl = sender.tab.url || '';

            if (isSupportedExternalPage(tabUrl)) {
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

    if (message.type === 'GET_ASSISTANT_STATUS') {
        sendResponse({
            success: true,
            assistantTabId: assistantTabId,
            controlledTabId: controlledTabId,
            assistantOrigin: assistantOrigin,
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
        const tabUrl = sender.tab.url || '';

        if (isSupportedExternalPage(tabUrl)) {
            controlledTabId = sender.tab.id;
        }

        if (isAssistantPage(tabUrl)) {
            assistantTabId = sender.tab.id;

            const origin = getOriginFromUrl(tabUrl);

            if (origin) {
                assistantOrigin = origin;
            }
        }
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

    if (!apiResult || apiResult.success === false) {
        return {
            success: false,
            message: apiResult?.speech || apiResult?.message || 'I did not understand the command.'
        };
    }

    updateMemoryFromApiResult(apiResult, originalText, browserContext);

    if (
        apiResult.frontendAction &&
        apiResult.frontendAction.type === 'NAVIGATE' &&
        apiResult.frontendAction.url
    ) {
        const navigationResult = await executeFrontendNavigation(apiResult.frontendAction.url);

        return {
            success: navigationResult.success !== false,
            message:
                navigationResult.message ||
                apiResult.speech ||
                apiResult.message ||
                'Opening the requested page.'
        };
    }

    if (
        apiResult.intent === 'APP_NAVIGATION' &&
        apiResult.url
    ) {
        const navigationResult = await executeFrontendNavigation(apiResult.url);

        return {
            success: navigationResult.success !== false,
            message:
                navigationResult.message ||
                apiResult.speech ||
                apiResult.message ||
                'Opening the requested page.'
        };
    }

    if (
        apiResult.intent === 'BROWSER_ACTION' &&
        apiResult.extensionCommand
    ) {
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

    if (
        apiResult.intent === 'WEBSITE_ACTION' &&
        apiResult.extensionCommand
    ) {
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
        if (
            apiResult.frontendAction &&
            apiResult.frontendAction.type === 'NAVIGATE' &&
            apiResult.frontendAction.url
        ) {
            const navigationResult = await executeFrontendNavigation(apiResult.frontendAction.url);

            return {
                success: navigationResult.success !== false,
                message:
                    apiResult.speech ||
                    apiResult.message ||
                    navigationResult.message ||
                    'Opening Gmail connection.'
            };
        }

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
    const origins = getAssistantOriginsToTry();

    let lastError = null;

    for (const origin of origins) {
        try {
            const url = origin.replace(/\/$/, '') + '/api/voice/interpret';

            const response = await fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                credentials: 'include',
                body: JSON.stringify({
                    text: text,
                    language: language,
                    browserContext: browserContext
                })
            });

            const rawText = await response.text();

            let data = null;

            try {
                data = rawText ? JSON.parse(rawText) : null;
            } catch (error) {
                throw new Error(
                    'Symfony API returned non-JSON from ' +
                    url +
                    '. HTTP ' +
                    response.status +
                    '. Preview: ' +
                    rawText.slice(0, 160)
                );
            }

            if (!response.ok) {
                throw new Error(
                    data?.speech ||
                    data?.message ||
                    'Symfony API returned HTTP ' + response.status
                );
            }

            assistantOrigin = origin;

            return data;
        } catch (error) {
            lastError = error;
        }
    }

    throw lastError || new Error('Could not contact Symfony voice API.');
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

        if (action === 'SEARCH' || action === 'OPEN_URL') {
            await waitForTabLoad(tab.id);
            await ensureContentScriptIfSupported(tab.id, url);
        }

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

async function executeFrontendNavigation(url) {
    const finalUrl = makeAbsoluteAssistantUrl(url);

    if (assistantTabId) {
        try {
            const assistantTab = await chrome.tabs.get(assistantTabId);

            if (assistantTab && assistantTab.id) {
                const updatedTab = await chrome.tabs.update(assistantTab.id, {
                    url: finalUrl,
                    active: true
                });

                assistantTabId = updatedTab.id;

                if (updatedTab.windowId) {
                    await chrome.windows.update(updatedTab.windowId, {
                        focused: true
                    });
                }

                return {
                    success: true,
                    message: 'Opened the requested page.'
                };
            }
        } catch (error) {
            assistantTabId = null;
        }
    }

    const existingAssistantTab = await findAssistantTab();

    if (existingAssistantTab && existingAssistantTab.id) {
        assistantTabId = existingAssistantTab.id;

        const updatedTab = await chrome.tabs.update(existingAssistantTab.id, {
            url: finalUrl,
            active: true
        });

        if (updatedTab.windowId) {
            await chrome.windows.update(updatedTab.windowId, {
                focused: true
            });
        }

        return {
            success: true,
            message: 'Opened the requested page.'
        };
    }

    const createdTab = await chrome.tabs.create({
        url: finalUrl,
        active: true
    });

    assistantTabId = createdTab.id;

    return {
        success: true,
        message: 'Opened the requested page.'
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

    const assistantTab = await findAssistantTab();

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

async function findAssistantTab() {
    const tabs = await chrome.tabs.query({});

    const assistantTab = tabs.find(tab => {
        const url = tab.url || '';

        return isAssistantPage(url);
    });

    if (assistantTab && assistantTab.url) {
        const origin = getOriginFromUrl(assistantTab.url);

        if (origin) {
            assistantOrigin = origin;
        }
    }

    return assistantTab || null;
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
            hasAssistantTab: assistantTabId !== null,
            assistantOrigin: assistantOrigin
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

    if (
        normalized === 'OPEN' ||
        normalized === 'OPEN-URL' ||
        normalized === 'OPEN_URL' ||
        normalized === 'OPEN_SITE' ||
        normalized === 'OPEN_WEBSITE'
    ) {
        return 'OPEN_URL';
    }

    if (
        normalized === 'SEARCH_URL' ||
        normalized === 'SEARCH-URL' ||
        normalized === 'SEARCH_WEB'
    ) {
        return 'SEARCH';
    }

    if (
        normalized === 'OPEN_RESULT' ||
        normalized === 'CLICK_RESULT' ||
        normalized === 'SELECT'
    ) {
        return 'SELECT_RESULT';
    }

    if (normalized === 'BACK') {
        return 'GO_BACK';
    }

    if (normalized === 'FORWARD') {
        return 'GO_FORWARD';
    }

    if (normalized === 'PLAY') {
        return 'PLAY_VIDEO';
    }

    if (normalized === 'PAUSE') {
        return 'PAUSE_VIDEO';
    }

    if (normalized === 'READ') {
        return 'READ_PAGE';
    }

    if (normalized === 'SUMMARIZE') {
        return 'SUMMARIZE_PAGE';
    }

    return normalized;
}

function buildUrlForCommand(command, action) {
    if (action === 'OPEN_URL') {
        if (command.url) {
            return sanitizeExternalUrl(command.url);
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
        const rawQuery = String(command.query || '').trim();

        if (!rawQuery) {
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

            if (
                existingTab &&
                existingTab.id &&
                isSupportedExternalPage(existingTab.url || '')
            ) {
                const updatedTab = await chrome.tabs.update(existingTab.id, {
                    url: url,
                    active: true
                });

                controlledTabId = updatedTab.id;

                if (updatedTab.windowId) {
                    await chrome.windows.update(updatedTab.windowId, {
                        focused: true
                    });
                }

                return updatedTab;
            }
        } catch (error) {
            controlledTabId = null;
        }
    }

    const activeExternalTab = await findActiveSupportedExternalTab();

    if (activeExternalTab && activeExternalTab.id) {
        const updatedTab = await chrome.tabs.update(activeExternalTab.id, {
            url: url,
            active: true
        });

        controlledTabId = updatedTab.id;

        return updatedTab;
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

            if (
                tab &&
                tab.id &&
                isSupportedExternalPage(tab.url || '')
            ) {
                return tab;
            }
        } catch (error) {
            controlledTabId = null;
        }
    }

    const activeExternalTab = await findActiveSupportedExternalTab();

    if (activeExternalTab && activeExternalTab.id) {
        controlledTabId = activeExternalTab.id;

        return activeExternalTab;
    }

    const tabs = await chrome.tabs.query({});

    const supportedTab = tabs.find(tab => isSupportedExternalPage(tab.url || ''));

    if (supportedTab && supportedTab.id) {
        controlledTabId = supportedTab.id;

        return supportedTab;
    }

    throw new Error('No supported browser tab found. Open YouTube, Google, Facebook, or Gmail first.');
}

async function findActiveSupportedExternalTab() {
    const tabs = await chrome.tabs.query({
        active: true,
        currentWindow: true
    });

    const activeTab = tabs[0];

    if (
        activeTab &&
        activeTab.id &&
        isSupportedExternalPage(activeTab.url || '')
    ) {
        return activeTab;
    }

    return null;
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

function isAssistantPage(url) {
    const value = String(url || '').toLowerCase();

    return (
        value.includes('localhost:8000') ||
        value.includes('127.0.0.1:8000') ||
        value.includes('voice-assistant.local')
    );
}

async function ensureContentScriptIfSupported(tabId, url) {
    if (!isSupportedExternalPage(url)) {
        return;
    }

    await ensureContentScript(tabId);
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
    if (!context) {
        return null;
    }

    const website = String(context.website || '').toLowerCase();
    const url = String(context.url || '').toLowerCase();
    const source = website + ' ' + url;

    if (source.includes('youtube.com') || source.includes('youtube')) {
        return 'youtube';
    }

    if (source.includes('mail.google.com') || source.includes('gmail')) {
        return 'gmail';
    }

    if (source.includes('google.com') || source.includes('google')) {
        return 'google';
    }

    if (source.includes('facebook.com') || source.includes('facebook')) {
        return 'facebook';
    }

    return null;
}

function sanitizeExternalUrl(url) {
    const value = String(url || '').trim();

    if (!value) {
        throw new Error('URL is missing.');
    }

    let parsed;

    try {
        parsed = new URL(value);
    } catch (error) {
        throw new Error('Invalid URL.');
    }

    const hostname = parsed.hostname.toLowerCase();

    const allowedHosts = [
        'youtube.com',
        'www.youtube.com',
        'google.com',
        'www.google.com',
        'facebook.com',
        'www.facebook.com',
        'mail.google.com'
    ];

    if (!allowedHosts.includes(hostname)) {
        throw new Error('Unsupported external URL.');
    }

    return parsed.toString();
}

function makeAbsoluteAssistantUrl(url) {
    const value = String(url || '').trim();

    if (!value) {
        return assistantOrigin;
    }

    try {
        const parsed = new URL(value);

        if (!isAssistantPage(parsed.toString())) {
            return assistantOrigin;
        }

        const origin = getOriginFromUrl(parsed.toString());

        if (origin) {
            assistantOrigin = origin;
        }

        return parsed.toString();
    } catch (error) {
        if (value.startsWith('/')) {
            return assistantOrigin.replace(/\/$/, '') + value;
        }

        return assistantOrigin.replace(/\/$/, '') + '/' + value.replace(/^\//, '');
    }
}

function getOriginFromUrl(url) {
    try {
        const parsed = new URL(url);

        return parsed.origin;
    } catch (error) {
        return null;
    }
}

function getAssistantOriginsToTry() {
    const origins = [];

    if (assistantOrigin) {
        origins.push(assistantOrigin);
    }

    for (const origin of FALLBACK_ASSISTANT_ORIGINS) {
        if (!origins.includes(origin)) {
            origins.push(origin);
        }
    }

    return origins;
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