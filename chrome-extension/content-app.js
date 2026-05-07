function notifyAssistantTabReady() {
    try {
        chrome.runtime.sendMessage({
            type: 'ASSISTANT_TAB_READY',
            context: {
                url: location.href,
                title: document.title,
                website: location.hostname
            }
        });
    } catch (error) {
        console.warn('Could not notify assistant tab:', error);
    }
}

notifyAssistantTabReady();

window.addEventListener('message', function (event) {
    if (event.source !== window) {
        return;
    }

    if (!event.data || event.data.source !== 'VOICE_ASSISTANT_WEB') {
        return;
    }

    if (event.data.type === 'PING_EXTENSION') {
        notifyAssistantTabReady();

        window.postMessage({
            source: 'VOICE_ASSISTANT_EXTENSION',
            type: 'EXTENSION_READY'
        }, window.location.origin);

        chrome.runtime.sendMessage({
            type: 'GET_CONTROLLED_CONTEXT'
        }, function (response) {
            if (chrome.runtime.lastError) {
                return;
            }

            if (response && response.context) {
                window.postMessage({
                    source: 'VOICE_ASSISTANT_EXTENSION',
                    type: 'BROWSER_CONTEXT',
                    context: response.context
                }, window.location.origin);
            }
        });

        return;
    }

    if (event.data.type === 'VOICE_LANGUAGE_CHANGED') {
        chrome.runtime.sendMessage({
            type: 'VOICE_LANGUAGE_CHANGED',
            language: event.data.language || 'en-US'
        }, function () {
            if (chrome.runtime.lastError) {
                console.warn('Could not save voice language in extension:', chrome.runtime.lastError.message);
            }
        });

        return;
    }

    if (event.data.type === 'EXECUTE_BROWSER_ACTION') {
        chrome.runtime.sendMessage({
            type: 'EXECUTE_BROWSER_ACTION',
            command: event.data.command
        }, function (response) {
            if (chrome.runtime.lastError) {
                window.postMessage({
                    source: 'VOICE_ASSISTANT_EXTENSION',
                    type: 'BROWSER_ACTION_RESULT',
                    result: {
                        success: false,
                        message: 'Chrome extension error: ' + chrome.runtime.lastError.message
                    }
                }, window.location.origin);

                return;
            }

            window.postMessage({
                source: 'VOICE_ASSISTANT_EXTENSION',
                type: 'BROWSER_ACTION_RESULT',
                result: response || {
                    success: true,
                    message: 'Command sent to browser.'
                }
            }, window.location.origin);
        });

        return;
    }
});