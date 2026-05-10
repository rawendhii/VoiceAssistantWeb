console.log('[Voice Assistant Extension] content-app.js loaded on:', window.location.href);

function hasChromeRuntime() {
    return (
        typeof chrome !== 'undefined' &&
        chrome.runtime &&
        chrome.runtime.id
    );
}

function postToAssistantPage(type, payload = {}) {
    window.postMessage({
        source: 'VOICE_ASSISTANT_EXTENSION',
        type: type,
        ...payload
    }, '*');
}

function getLocalBrowserContext() {
    return {
        url: window.location.href,
        title: document.title,
        website: window.location.hostname,
        visibleText: document.body && document.body.innerText
            ? document.body.innerText.replace(/\s+/g, ' ').trim().slice(0, 3000)
            : ''
    };
}

function sendExtensionReadyMessage() {
    console.log('[Voice Assistant Extension] Sending EXTENSION_READY');

    postToAssistantPage('EXTENSION_READY', {
        context: getLocalBrowserContext()
    });
}

function sendBrowserContextToPage(context = null) {
    postToAssistantPage('BROWSER_CONTEXT', {
        context: context || getLocalBrowserContext()
    });
}

function notifyAssistantTabReady() {
    if (!hasChromeRuntime()) {
        console.warn('[Voice Assistant Extension] Chrome runtime unavailable.');
        return;
    }

    try {
        chrome.runtime.sendMessage({
            type: 'ASSISTANT_TAB_READY',
            context: getLocalBrowserContext()
        }, function (response) {
            if (chrome.runtime.lastError) {
                console.warn(
                    '[Voice Assistant Extension] Assistant tab registration failed:',
                    chrome.runtime.lastError.message
                );
                return;
            }

            console.log('[Voice Assistant Extension] Assistant tab registered:', response);
        });
    } catch (error) {
        console.warn('[Voice Assistant Extension] Could not notify assistant tab:', error);
    }
}

function requestControlledBrowserContext() {
    if (!hasChromeRuntime()) {
        sendBrowserContextToPage();
        return;
    }

    try {
        chrome.runtime.sendMessage({
            type: 'GET_CONTROLLED_CONTEXT',
            browserContext: getLocalBrowserContext()
        }, function (response) {
            if (chrome.runtime.lastError) {
                console.warn(
                    '[Voice Assistant Extension] Could not get controlled context:',
                    chrome.runtime.lastError.message
                );

                sendBrowserContextToPage();
                return;
            }

            if (response && response.context) {
                sendBrowserContextToPage(response.context);
                return;
            }

            sendBrowserContextToPage();
        });
    } catch (error) {
        console.warn('[Voice Assistant Extension] Controlled context request failed:', error);
        sendBrowserContextToPage();
    }
}

function bootAssistantBridge() {
    notifyAssistantTabReady();
    sendExtensionReadyMessage();
    requestControlledBrowserContext();
}

bootAssistantBridge();

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
        bootAssistantBridge();
    });
}

setTimeout(bootAssistantBridge, 300);
setTimeout(bootAssistantBridge, 1200);

window.addEventListener('message', function (event) {
    if (event.source !== window) {
        return;
    }

    if (!event.data || event.data.source !== 'VOICE_ASSISTANT_WEB') {
        return;
    }

    console.log('[Voice Assistant Extension] Message from web page:', event.data);

    if (event.data.type === 'PING_EXTENSION') {
        bootAssistantBridge();
        return;
    }

    if (event.data.type === 'VOICE_LANGUAGE_CHANGED') {
        if (!hasChromeRuntime()) {
            return;
        }

        chrome.runtime.sendMessage({
            type: 'VOICE_LANGUAGE_CHANGED',
            language: event.data.language || 'en-US'
        }, function () {
            if (chrome.runtime.lastError) {
                console.warn(
                    '[Voice Assistant Extension] Could not save voice language:',
                    chrome.runtime.lastError.message
                );
            }
        });

        return;
    }

    if (event.data.type === 'EXECUTE_BROWSER_ACTION') {
        if (!hasChromeRuntime()) {
            postToAssistantPage('BROWSER_ACTION_RESULT', {
                result: {
                    success: false,
                    message: 'Chrome extension runtime is not available. Reload the extension and refresh this page.'
                }
            });

            return;
        }

        const command = event.data.command || {};

        console.log('[Voice Assistant Extension] Executing command:', command);

        chrome.runtime.sendMessage({
            type: 'EXECUTE_BROWSER_ACTION',
            command: command
        }, function (response) {
            if (chrome.runtime.lastError) {
                postToAssistantPage('BROWSER_ACTION_RESULT', {
                    result: {
                        success: false,
                        message: 'Chrome extension error: ' + chrome.runtime.lastError.message
                    }
                });

                return;
            }

            postToAssistantPage('BROWSER_ACTION_RESULT', {
                result: response || {
                    success: true,
                    message: 'Command sent to browser.'
                }
            });
        });
    }
});