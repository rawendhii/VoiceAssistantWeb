chrome.runtime.onMessage.addListener((message, sender, sendResponse) => {
    if (!message || !message.type) {
        return;
    }

    if (message.type === 'PING_PAGE_SCRIPT') {
        sendResponse({
            success: true,
            message: 'Content page script is ready.'
        });

        return true;
    }

    if (message.type === 'RUN_PAGE_ACTION') {
        runPageAction(message.command || {})
            .then(result => sendResponse(result))
            .catch(error => sendResponse({
                success: false,
                message: error.message
            }));

        return true;
    }
});

async function runPageAction(command) {
    const action = String(command.action || '').toUpperCase();
    const language = command.language || getCurrentExtensionVoiceLanguage();

    switch (action) {
        case 'SELECT_RESULT':
            return await selectResult(command.resultPosition);

        case 'READ_PAGE':
            return readPage(language);

        case 'SUMMARIZE_PAGE':
            return summarizePage(language);

        case 'SCROLL_DOWN':
            window.scrollBy({ top: window.innerHeight * 0.8, behavior: 'smooth' });
            return { success: true, message: getActionMessage('SCROLL_DOWN', language) };

        case 'SCROLL_UP':
            window.scrollBy({ top: -window.innerHeight * 0.8, behavior: 'smooth' });
            return { success: true, message: getActionMessage('SCROLL_UP', language) };

        case 'GO_BACK':
            history.back();
            return { success: true, message: getActionMessage('GO_BACK', language) };

        case 'GO_FORWARD':
            history.forward();
            return { success: true, message: getActionMessage('GO_FORWARD', language) };

        case 'PLAY_VIDEO':
            return controlVideo('play', language);

        case 'PAUSE_VIDEO':
            return controlVideo('pause', language);

        case 'MUTE':
            return controlVideo('mute', language);

        case 'UNMUTE':
            return controlVideo('unmute', language);

        case 'VOLUME_UP':
            return changeVolume(0.1, language);

        case 'VOLUME_DOWN':
            return changeVolume(-0.1, language);

       case 'CLICK':
    if (location.hostname.includes('youtube.com')) {
        return await clickYouTubeVideoByTitle(command.target, language);
    }

    return clickElement(command.target, language);
        case 'TYPE':
            return typeIntoFocusedOrBestInput(command.query || command.target || '', language);

        default:
            return {
                success: false,
                message: 'Unsupported browser action: ' + action
            };
    }
}

async function selectResult(position) {
    const index = Number(position) - 1;

    if (index < 0) {
        return {
            success: false,
            message: 'Invalid result position.'
        };
    }

    const results = await waitForResults();
    const selected = results[index];

    if (!selected) {
        return {
            success: false,
            message: 'Result not found. Try a search command first.'
        };
    }

    selected.scrollIntoView({ behavior: 'smooth', block: 'center' });
    await sleep(300);

    selected.focus();
    selected.click();

    return {
        success: true,
        message: `Opened result number ${position}.`
    };
}

async function waitForResults() {
    for (let attempt = 0; attempt < 14; attempt++) {
        const results = getPageResults();

        if (results.length > 0) {
            return results;
        }

        await sleep(500);
    }

    return [];
}

function getPageResults() {
    let results = [];

    if (location.hostname.includes('youtube.com')) {
        results = Array.from(document.querySelectorAll(
            'ytd-video-renderer a#video-title, ytd-video-renderer a#thumbnail, ytd-rich-item-renderer a#video-title-link, ytd-rich-item-renderer a#thumbnail, a#video-title'
        ))
            .filter(isVisible)
            .filter(link => {
                const href = link.href || '';
                return href.includes('/watch');
            });
    } else if (location.hostname.includes('google.com')) {
        results = Array.from(document.querySelectorAll('a'))
            .filter(a => {
                const href = a.href || '';
                const text = a.innerText || '';

                return href.startsWith('http') && text.trim().length > 20 && isVisible(a);
            });
    } else {
        results = Array.from(document.querySelectorAll('a'))
            .filter(a => a.innerText.trim().length > 0 && isVisible(a));
    }

    return removeDuplicateLinks(results);
}

function readPage(language) {
    const title = document.title || '';

    const headings = Array.from(document.querySelectorAll('h1, h2'))
        .map(h => h.innerText.trim())
        .filter(Boolean)
        .slice(0, 5);

    const mainText = getMainText();

    const textToRead = [
        title ? `Page title: ${title}.` : '',
        headings.length ? `Main sections: ${headings.join(', ')}.` : '',
        mainText ? `Content: ${mainText}` : ''
    ].join(' ').trim();

    speak(textToRead || getFallbackMessage('NO_READABLE_TEXT', language), language);

    return {
        success: true,
        message: getFallbackMessage('PAGE_READ', language),
        text: textToRead
    };
}

function summarizePage(language) {
    const title = document.title || '';
    const text = getMainText();

    const sentences = text
        .split(/[.!?؟]+/)
        .map(sentence => sentence.trim())
        .filter(sentence => sentence.length > 40)
        .slice(0, 4);

    const summary = [
        title ? `Summary of ${title}.` : 'Page summary.',
        sentences.join('. ')
    ].join(' ').trim();

    speak(summary || getFallbackMessage('NO_SUMMARY', language), language);

    return {
        success: true,
        message: getFallbackMessage('PAGE_SUMMARY', language),
        text: summary
    };
}

function getMainText() {
    const candidates = [
        document.querySelector('main'),
        document.querySelector('article'),
        document.querySelector('[role="main"]'),
        document.body
    ].filter(Boolean);

    const text = candidates[0].innerText || '';

    return text
        .replace(/\s+/g, ' ')
        .trim()
        .slice(0, 1600);
}

function speak(text, language) {
    if (!('speechSynthesis' in window)) {
        return;
    }

    window.speechSynthesis.cancel();

    const utterance = new SpeechSynthesisUtterance(text);
    utterance.lang = language || getCurrentExtensionVoiceLanguage();
    utterance.rate = 0.95;
    utterance.pitch = 1;
    utterance.volume = 1;

    window.speechSynthesis.speak(utterance);
}

function controlVideo(action, language) {
    const video = document.querySelector('video');

    if (!video) {
        return {
            success: false,
            message: getFallbackMessage('NO_VIDEO', language)
        };
    }

    if (action === 'play') {
        video.play();
    }

    if (action === 'pause') {
        video.pause();
    }

    if (action === 'mute') {
        video.muted = true;
    }

    if (action === 'unmute') {
        video.muted = false;
    }

    return {
        success: true,
        message: getVideoActionMessage(action, language)
    };
}

function changeVolume(delta, language) {
    const video = document.querySelector('video');

    if (!video) {
        return {
            success: false,
            message: getFallbackMessage('NO_VIDEO', language)
        };
    }

    video.volume = Math.max(0, Math.min(1, video.volume + delta));

    return {
        success: true,
        message: getVolumeMessage(Math.round(video.volume * 100), language)
    };
}

function clickElement(targetText, language) {
    if (!targetText) {
        return {
            success: false,
            message: getFallbackMessage('NO_TARGET', language)
        };
    }

    const normalizedTarget = normalize(targetText);

    const clickableElements = Array.from(document.querySelectorAll(
        'button, a, input[type="button"], input[type="submit"], [role="button"], [aria-label], [title]'
    )).filter(isVisible);

    let bestElement = null;
    let bestScore = 0;

    for (const element of clickableElements) {
        const text = normalize(
            element.innerText ||
            element.value ||
            element.getAttribute('aria-label') ||
            element.getAttribute('title') ||
            ''
        );

        if (!text) {
            continue;
        }

        let score = 0;

        if (text === normalizedTarget) {
            score = 100;
        } else if (text.includes(normalizedTarget) || normalizedTarget.includes(text)) {
            score = 80;
        } else {
            score = similarity(text, normalizedTarget);
        }

        if (score > bestScore) {
            bestScore = score;
            bestElement = element;
        }
    }

    if (!bestElement || bestScore < 45) {
        return {
            success: false,
            message: getFallbackMessage('NO_CLICK_MATCH', language)
        };
    }

    bestElement.scrollIntoView({ behavior: 'smooth', block: 'center' });
    bestElement.focus();
    bestElement.click();

    return {
        success: true,
        message: getFallbackMessage('ELEMENT_CLICKED', language)
    };
}

function typeIntoFocusedOrBestInput(text, language) {
    if (!text) {
        return {
            success: false,
            message: getFallbackMessage('NO_TEXT', language)
        };
    }

    let input = document.activeElement;

    if (!input || !isEditableInput(input)) {
        input = document.querySelector('input[type="text"], input[type="search"], textarea, [contenteditable="true"]');
    }

    if (!input) {
        return {
            success: false,
            message: getFallbackMessage('NO_INPUT', language)
        };
    }

    input.focus();

    if (input.isContentEditable) {
        input.innerText = text;
        input.dispatchEvent(new Event('input', { bubbles: true }));
    } else {
        input.value = text;
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
    }

    return {
        success: true,
        message: getFallbackMessage('TEXT_INSERTED', language)
    };
}

function isEditableInput(element) {
    if (!element) {
        return false;
    }

    const tag = element.tagName ? element.tagName.toLowerCase() : '';

    return (
        tag === 'textarea' ||
        element.isContentEditable ||
        (tag === 'input' && ['text', 'search', 'email', 'password', 'url', 'tel'].includes(element.type))
    );
}

function normalize(text) {
    return String(text)
        .toLowerCase()
        .replace(/[^\p{L}\p{N}\s]/gu, ' ')
        .replace(/\s+/g, ' ')
        .trim();
}

function similarity(a, b) {
    const wordsA = new Set(a.split(' ').filter(Boolean));
    const wordsB = new Set(b.split(' ').filter(Boolean));

    if (wordsA.size === 0 || wordsB.size === 0) {
        return 0;
    }

    let matches = 0;

    for (const word of wordsA) {
        if (wordsB.has(word)) {
            matches++;
        }
    }

    return Math.round((matches / Math.max(wordsA.size, wordsB.size)) * 100);
}

function isVisible(element) {
    if (!element) {
        return false;
    }

    const rect = element.getBoundingClientRect();
    const style = window.getComputedStyle(element);

    return (
        rect.width > 0 &&
        rect.height > 0 &&
        style.visibility !== 'hidden' &&
        style.display !== 'none'
    );
}

function removeDuplicateLinks(links) {
    const seen = new Set();
    const unique = [];

    for (const link of links) {
        const key = link.href || link.innerText || link.getAttribute('aria-label') || '';

        if (!key || seen.has(key)) {
            continue;
        }

        seen.add(key);
        unique.push(link);
    }

    return unique;
}

function sleep(milliseconds) {
    return new Promise(resolve => setTimeout(resolve, milliseconds));
}

function collectBrowserContext() {
    return {
        url: location.href,
        title: document.title,
        website: location.hostname,
        visibleText: document.body && document.body.innerText
            ? document.body.innerText.replace(/\s+/g, ' ').trim().slice(0, 2000)
            : ''
    };
}

let voiceAssistantContextInterval = null;

safeSendContextToBackground();

voiceAssistantContextInterval = setInterval(function () {
    safeSendContextToBackground();
}, 3000);

function safeSendContextToBackground() {
    try {
        if (
            typeof chrome === 'undefined' ||
            !chrome.runtime ||
            !chrome.runtime.id
        ) {
            stopContextInterval();
            return;
        }

        chrome.runtime.sendMessage({
            type: 'PAGE_CONTEXT_UPDATE',
            context: collectBrowserContext()
        }, function () {
            if (chrome.runtime.lastError) {
                console.warn('Could not send page context:', chrome.runtime.lastError.message);

                if (
                    chrome.runtime.lastError.message &&
                    chrome.runtime.lastError.message.includes('Extension context invalidated')
                ) {
                    stopContextInterval();
                }
            }
        });
    } catch (error) {
        console.warn('Could not send page context:', error.message || error);
        stopContextInterval();
    }
}

function stopContextInterval() {
    if (voiceAssistantContextInterval) {
        clearInterval(voiceAssistantContextInterval);
        voiceAssistantContextInterval = null;
    }
}

const SUPPORTED_EXTENSION_VOICE_LANGUAGES = {
    english: 'en-US',
    anglais: 'en-US',

    french: 'fr-FR',
    francais: 'fr-FR',
    français: 'fr-FR',

    arabic: 'ar-SA',
    arabe: 'ar-SA',
    عربي: 'ar-SA',
    عربى: 'ar-SA',

    tunisian: 'ar-TN',
    tunisien: 'ar-TN',
    تونسي: 'ar-TN',
    تونسية: 'ar-TN',

    spanish: 'es-ES',
    espagnol: 'es-ES',

    italian: 'it-IT',
    italien: 'it-IT',

    german: 'de-DE',
    allemand: 'de-DE'
};

let pageRecognition = null;
let pageIsListening = false;
let pageVoiceLanguage = 'en-US';

loadExtensionVoiceLanguage();
createFloatingVoiceButton();

function loadExtensionVoiceLanguage() {
    try {
        chrome.storage.local.get(['voiceAssistantLanguage'], function (result) {
            if (chrome.runtime.lastError) {
                return;
            }

            pageVoiceLanguage = result.voiceAssistantLanguage || 'en-US';
        });
    } catch (error) {
        pageVoiceLanguage = 'en-US';
    }
}

function saveExtensionVoiceLanguage(language) {
    pageVoiceLanguage = language || 'en-US';

    try {
        chrome.storage.local.set({
            voiceAssistantLanguage: pageVoiceLanguage
        });
    } catch (error) {
        console.warn('Could not save extension voice language:', error);
    }
}

function getCurrentExtensionVoiceLanguage() {
    return pageVoiceLanguage || 'en-US';
}

function createFloatingVoiceButton() {
    if (document.getElementById('voiceAssistantFloatingBtn')) {
        return;
    }

    if (!document.body) {
        return;
    }

    const button = document.createElement('button');
    button.id = 'voiceAssistantFloatingBtn';
    button.type = 'button';
    button.innerText = '🎙️ Voice';
    button.setAttribute('aria-label', 'Start voice assistant');

    button.style.position = 'fixed';
    button.style.right = '20px';
    button.style.bottom = '20px';
    button.style.zIndex = '999999';
    button.style.border = '3px solid #ffffff';
    button.style.borderRadius = '999px';
    button.style.padding = '16px 20px';
    button.style.background = '#2563eb';
    button.style.color = '#ffffff';
    button.style.fontSize = '17px';
    button.style.fontWeight = '900';
    button.style.cursor = 'pointer';
    button.style.boxShadow = '0 12px 30px rgba(0,0,0,0.25)';
    button.style.minWidth = '110px';
    button.style.minHeight = '58px';

    button.addEventListener('click', function () {
        togglePageVoiceListening();
    });

    document.body.appendChild(button);
}

function togglePageVoiceListening() {
    if (pageIsListening) {
        stopPageVoiceListening();
    } else {
        startPageVoiceListening();
    }
}

function startPageVoiceListening() {
    const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;

    if (!SpeechRecognition) {
        speakOnPage(getFallbackMessage('SPEECH_NOT_SUPPORTED', getCurrentExtensionVoiceLanguage()));
        return;
    }

    if (!pageRecognition) {
        pageRecognition = new SpeechRecognition();
        pageRecognition.interimResults = false;
        pageRecognition.continuous = false;

        pageRecognition.onstart = function () {
            pageIsListening = true;
            updateFloatingButton(true);
            speakOnPage(getFallbackMessage('LISTENING_NOW', getCurrentExtensionVoiceLanguage()));
        };

        pageRecognition.onend = function () {
            pageIsListening = false;
            updateFloatingButton(false);
        };

        pageRecognition.onerror = function (event) {
            pageIsListening = false;
            updateFloatingButton(false);

            let message = getFallbackMessage('VOICE_ERROR', getCurrentExtensionVoiceLanguage());

            if (event && event.error === 'not-allowed') {
                message = getFallbackMessage('MIC_BLOCKED', getCurrentExtensionVoiceLanguage());
            } else if (event && event.error === 'no-speech') {
                message = getFallbackMessage('NO_SPEECH', getCurrentExtensionVoiceLanguage());
            }

            speakOnPage(message);
        };

        pageRecognition.onresult = function (event) {
            const text = event.results[0][0].transcript.trim();

            if (!text) {
                speakOnPage(getFallbackMessage('NO_COMMAND', getCurrentExtensionVoiceLanguage()));
                return;
            }

            handlePageVoiceCommand(text);
        };
    }

    try {
        pageRecognition.lang = getCurrentExtensionVoiceLanguage();
        pageRecognition.start();
    } catch (error) {
        speakOnPage(getFallbackMessage('MIC_STARTING', getCurrentExtensionVoiceLanguage()));
    }
}

function stopPageVoiceListening() {
    if (pageRecognition && pageIsListening) {
        pageRecognition.stop();
    }
}

function updateFloatingButton(listening) {
    const button = document.getElementById('voiceAssistantFloatingBtn');

    if (!button) {
        return;
    }

    if (listening) {
        button.innerText = '🔴 Listening';
        button.style.background = '#dc2626';
    } else {
        button.innerText = '🎙️ Voice';
        button.style.background = '#2563eb';
    }
}
function handlePageVoiceCommand(text) {
    const requestedLanguage = detectExtensionLanguageSetupCommand(text);

    if (requestedLanguage) {
        saveExtensionVoiceLanguage(requestedLanguage);
        speakOnPage(getExtensionLanguageChangeMessage(requestedLanguage), requestedLanguage);
        return;
    }

    const localPageAction = detectLocalPageAction(text);

    if (localPageAction) {
        runPageAction({
            action: localPageAction,
            language: getCurrentExtensionVoiceLanguage()
        }).then(function (result) {
            speakOnPage(
                result.message || getFallbackMessage('COMMAND_COMPLETED', getCurrentExtensionVoiceLanguage()),
                getCurrentExtensionVoiceLanguage()
            );
        }).catch(function (error) {
            speakOnPage(
                error.message || getFallbackMessage('COMMAND_COMPLETED', getCurrentExtensionVoiceLanguage()),
                getCurrentExtensionVoiceLanguage()
            );
        });

        return;
    }

    speakOnPage(getProcessingMessage(text, getCurrentExtensionVoiceLanguage()));

    if (
        typeof chrome === 'undefined' ||
        !chrome.runtime ||
        !chrome.runtime.id
    ) {
        speakOnPage(getFallbackMessage('EXTENSION_NOT_READY', getCurrentExtensionVoiceLanguage()));
        return;
    }

    chrome.runtime.sendMessage({
        type: 'PAGE_VOICE_COMMAND',
        text: text,
        language: getCurrentExtensionVoiceLanguage(),
        browserContext: collectBrowserContext()
    }, function (response) {
        if (chrome.runtime.lastError) {
            speakOnPage(
                'Chrome extension error: ' + chrome.runtime.lastError.message,
                getCurrentExtensionVoiceLanguage()
            );
            return;
        }

        if (!response) {
            speakOnPage(getFallbackMessage('NO_RESPONSE', getCurrentExtensionVoiceLanguage()));
            return;
        }

        speakOnPage(
            response.message || getFallbackMessage('COMMAND_COMPLETED', getCurrentExtensionVoiceLanguage()),
            getCurrentExtensionVoiceLanguage()
        );
    });
}

function speakOnPage(text, language) {
    speak(text, language || getCurrentExtensionVoiceLanguage());
}

function normalizeExtensionText(text) {
    return String(text || '')
        .toLowerCase()
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .replace(/\s+/g, ' ')
        .trim();
}

function detectExtensionLanguageSetupCommand(text) {
    const normalized = normalizeExtensionText(text);

    const languagePatterns = [
        'speak ',
        'change language to ',
        'set language to ',
        'use ',
        'talk in ',
        'answer in ',

        'parle ',
        'reponds en ',
        'réponds en ',
        'changer la langue en ',
        'change la langue en ',
        'utilise ',

        'تكلم ',
        'احكي ',
        'جاوبني ب ',
        'بدل اللغة إلى ',
        'بدل اللغة ل '
    ];

    for (const pattern of languagePatterns) {
        if (normalized.includes(pattern)) {
            const afterPattern = normalized.split(pattern).pop().trim();

            for (const languageName in SUPPORTED_EXTENSION_VOICE_LANGUAGES) {
                if (afterPattern.includes(languageName)) {
                    return SUPPORTED_EXTENSION_VOICE_LANGUAGES[languageName];
                }
            }
        }
    }

    return null;
}

function getExtensionLanguageChangeMessage(language) {
    const messages = {
        'en-US': 'Voice language changed to English.',
        'fr-FR': 'La langue vocale est maintenant le français.',
        'ar-TN': 'تم تغيير لغة المساعد إلى التونسية.',
        'ar-SA': 'تم تغيير لغة المساعد إلى العربية.',
        'es-ES': 'El idioma de voz cambió a español.',
        'it-IT': 'La lingua vocale è stata cambiata in italiano.',
        'de-DE': 'Die Sprachsprache wurde auf Deutsch geändert.'
    };

    return messages[language] || 'Voice language changed.';
}

function getProcessingMessage(text, language) {
    if (language === 'fr-FR') {
        return 'Traitement de votre commande : ' + text;
    }

    if (language === 'ar-SA' || language === 'ar-TN') {
        return 'جاري تنفيذ الأمر: ' + text;
    }

    if (language === 'es-ES') {
        return 'Procesando tu comando: ' + text;
    }

    if (language === 'it-IT') {
        return 'Elaborazione del comando: ' + text;
    }

    if (language === 'de-DE') {
        return 'Befehl wird verarbeitet: ' + text;
    }

    return 'Processing: ' + text;
}

function getFallbackMessage(key, language) {
    const messages = {
        en: {
            SPEECH_NOT_SUPPORTED: 'Voice recognition is not supported in this browser.',
            LISTENING_NOW: 'Listening now.',
            VOICE_ERROR: 'Voice recognition error. Please try again.',
            MIC_BLOCKED: 'Microphone permission is blocked. Please allow microphone access for this site.',
            NO_SPEECH: 'I did not hear anything. Please try again.',
            NO_COMMAND: 'I did not hear a command.',
            MIC_STARTING: 'The microphone is already starting.',
            EXTENSION_NOT_READY: 'The Chrome extension is not ready. Please reload the extension and refresh this page.',
            NO_RESPONSE: 'No response received from the Chrome extension.',
            COMMAND_COMPLETED: 'Command completed.',
            NO_READABLE_TEXT: 'I could not find readable text on this page.',
            PAGE_READ: 'Page read aloud.',
            NO_SUMMARY: 'I could not summarize this page.',
            PAGE_SUMMARY: 'Page summary read aloud.',
            NO_VIDEO: 'No video found on this page.',
            NO_TARGET: 'No target provided.',
            NO_CLICK_MATCH: 'I could not find a matching clickable element.',
            ELEMENT_CLICKED: 'Element clicked.',
            NO_TEXT: 'No text provided.',
            NO_INPUT: 'No editable input found.',
            TEXT_INSERTED: 'Text inserted.'
        },
        fr: {
            SPEECH_NOT_SUPPORTED: 'La reconnaissance vocale n’est pas prise en charge par ce navigateur.',
            LISTENING_NOW: 'J’écoute maintenant.',
            VOICE_ERROR: 'Erreur de reconnaissance vocale. Veuillez réessayer.',
            MIC_BLOCKED: 'L’accès au microphone est bloqué. Veuillez autoriser le microphone pour ce site.',
            NO_SPEECH: 'Je n’ai rien entendu. Veuillez réessayer.',
            NO_COMMAND: 'Je n’ai pas entendu de commande.',
            MIC_STARTING: 'Le microphone est déjà en cours de démarrage.',
            EXTENSION_NOT_READY: 'L’extension Chrome n’est pas prête. Veuillez recharger l’extension et actualiser la page.',
            NO_RESPONSE: 'Aucune réponse reçue de l’extension Chrome.',
            COMMAND_COMPLETED: 'Commande terminée.',
            NO_READABLE_TEXT: 'Je n’ai pas trouvé de texte lisible sur cette page.',
            PAGE_READ: 'Page lue à voix haute.',
            NO_SUMMARY: 'Je n’ai pas pu résumer cette page.',
            PAGE_SUMMARY: 'Résumé de la page lu à voix haute.',
            NO_VIDEO: 'Aucune vidéo trouvée sur cette page.',
            NO_TARGET: 'Aucune cible fournie.',
            NO_CLICK_MATCH: 'Je n’ai pas trouvé d’élément cliquable correspondant.',
            ELEMENT_CLICKED: 'Élément cliqué.',
            NO_TEXT: 'Aucun texte fourni.',
            NO_INPUT: 'Aucun champ modifiable trouvé.',
            TEXT_INSERTED: 'Texte inséré.'
        },
        ar: {
            SPEECH_NOT_SUPPORTED: 'التعرّف على الصوت غير مدعوم في هذا المتصفح.',
            LISTENING_NOW: 'أنا أستمع الآن.',
            VOICE_ERROR: 'حدث خطأ في التعرّف على الصوت. حاول مرة أخرى.',
            MIC_BLOCKED: 'إذن الميكروفون محظور. يرجى السماح باستخدام الميكروفون لهذا الموقع.',
            NO_SPEECH: 'لم أسمع أي شيء. حاول مرة أخرى.',
            NO_COMMAND: 'لم أسمع أمراً.',
            MIC_STARTING: 'الميكروفون قيد التشغيل بالفعل.',
            EXTENSION_NOT_READY: 'إضافة كروم غير جاهزة. يرجى إعادة تحميل الإضافة وتحديث الصفحة.',
            NO_RESPONSE: 'لم يتم استلام أي رد من إضافة كروم.',
            COMMAND_COMPLETED: 'تم تنفيذ الأمر.',
            NO_READABLE_TEXT: 'لم أجد نصاً قابلاً للقراءة في هذه الصفحة.',
            PAGE_READ: 'تمت قراءة الصفحة بصوت عالٍ.',
            NO_SUMMARY: 'لم أتمكن من تلخيص هذه الصفحة.',
            PAGE_SUMMARY: 'تمت قراءة ملخص الصفحة.',
            NO_VIDEO: 'لم يتم العثور على فيديو في هذه الصفحة.',
            NO_TARGET: 'لم يتم تحديد هدف.',
            NO_CLICK_MATCH: 'لم أجد عنصراً قابلاً للنقر مطابقاً.',
            ELEMENT_CLICKED: 'تم النقر على العنصر.',
            NO_TEXT: 'لم يتم تقديم نص.',
            NO_INPUT: 'لم يتم العثور على حقل قابل للتحرير.',
            TEXT_INSERTED: 'تم إدخال النص.'
        },
        es: {
            SPEECH_NOT_SUPPORTED: 'El reconocimiento de voz no es compatible con este navegador.',
            LISTENING_NOW: 'Escuchando ahora.',
            VOICE_ERROR: 'Error de reconocimiento de voz. Inténtalo de nuevo.',
            MIC_BLOCKED: 'El permiso del micrófono está bloqueado. Permite el acceso al micrófono para este sitio.',
            NO_SPEECH: 'No escuché nada. Inténtalo de nuevo.',
            NO_COMMAND: 'No escuché ningún comando.',
            MIC_STARTING: 'El micrófono ya se está iniciando.',
            EXTENSION_NOT_READY: 'La extensión de Chrome no está lista. Recarga la extensión y actualiza esta página.',
            NO_RESPONSE: 'No se recibió respuesta de la extensión de Chrome.',
            COMMAND_COMPLETED: 'Comando completado.',
            NO_READABLE_TEXT: 'No pude encontrar texto legible en esta página.',
            PAGE_READ: 'Página leída en voz alta.',
            NO_SUMMARY: 'No pude resumir esta página.',
            PAGE_SUMMARY: 'Resumen de la página leído en voz alta.',
            NO_VIDEO: 'No se encontró ningún video en esta página.',
            NO_TARGET: 'No se proporcionó ningún objetivo.',
            NO_CLICK_MATCH: 'No pude encontrar un elemento clicable coincidente.',
            ELEMENT_CLICKED: 'Elemento seleccionado.',
            NO_TEXT: 'No se proporcionó texto.',
            NO_INPUT: 'No se encontró ningún campo editable.',
            TEXT_INSERTED: 'Texto insertado.'
        },
        it: {
            SPEECH_NOT_SUPPORTED: 'Il riconoscimento vocale non è supportato da questo browser.',
            LISTENING_NOW: 'Sto ascoltando.',
            VOICE_ERROR: 'Errore di riconoscimento vocale. Riprova.',
            MIC_BLOCKED: 'Il permesso del microfono è bloccato. Consenti l’accesso al microfono per questo sito.',
            NO_SPEECH: 'Non ho sentito nulla. Riprova.',
            NO_COMMAND: 'Non ho sentito un comando.',
            MIC_STARTING: 'Il microfono si sta già avviando.',
            EXTENSION_NOT_READY: 'L’estensione Chrome non è pronta. Ricarica l’estensione e aggiorna la pagina.',
            NO_RESPONSE: 'Nessuna risposta ricevuta dall’estensione Chrome.',
            COMMAND_COMPLETED: 'Comando completato.',
            NO_READABLE_TEXT: 'Non ho trovato testo leggibile in questa pagina.',
            PAGE_READ: 'Pagina letta ad alta voce.',
            NO_SUMMARY: 'Non sono riuscito a riassumere questa pagina.',
            PAGE_SUMMARY: 'Riepilogo della pagina letto ad alta voce.',
            NO_VIDEO: 'Nessun video trovato in questa pagina.',
            NO_TARGET: 'Nessun obiettivo fornito.',
            NO_CLICK_MATCH: 'Non ho trovato un elemento cliccabile corrispondente.',
            ELEMENT_CLICKED: 'Elemento cliccato.',
            NO_TEXT: 'Nessun testo fornito.',
            NO_INPUT: 'Nessun campo modificabile trovato.',
            TEXT_INSERTED: 'Testo inserito.'
        },
        de: {
            SPEECH_NOT_SUPPORTED: 'Spracherkennung wird in diesem Browser nicht unterstützt.',
            LISTENING_NOW: 'Ich höre jetzt zu.',
            VOICE_ERROR: 'Fehler bei der Spracherkennung. Bitte versuche es erneut.',
            MIC_BLOCKED: 'Der Mikrofonzugriff ist blockiert. Bitte erlaube den Mikrofonzugriff für diese Seite.',
            NO_SPEECH: 'Ich habe nichts gehört. Bitte versuche es erneut.',
            NO_COMMAND: 'Ich habe keinen Befehl gehört.',
            MIC_STARTING: 'Das Mikrofon startet bereits.',
            EXTENSION_NOT_READY: 'Die Chrome-Erweiterung ist nicht bereit. Bitte lade die Erweiterung neu und aktualisiere diese Seite.',
            NO_RESPONSE: 'Keine Antwort von der Chrome-Erweiterung erhalten.',
            COMMAND_COMPLETED: 'Befehl abgeschlossen.',
            NO_READABLE_TEXT: 'Ich konnte auf dieser Seite keinen lesbaren Text finden.',
            PAGE_READ: 'Seite wurde vorgelesen.',
            NO_SUMMARY: 'Ich konnte diese Seite nicht zusammenfassen.',
            PAGE_SUMMARY: 'Seitenzusammenfassung wurde vorgelesen.',
            NO_VIDEO: 'Kein Video auf dieser Seite gefunden.',
            NO_TARGET: 'Kein Ziel angegeben.',
            NO_CLICK_MATCH: 'Ich konnte kein passendes anklickbares Element finden.',
            ELEMENT_CLICKED: 'Element angeklickt.',
            NO_TEXT: 'Kein Text angegeben.',
            NO_INPUT: 'Kein bearbeitbares Eingabefeld gefunden.',
            TEXT_INSERTED: 'Text eingefügt.'
        }
    };

    const group = getMessageLanguageGroup(language);

    return (messages[group] && messages[group][key]) || messages.en[key] || key;
}

function getMessageLanguageGroup(language) {
    if (language === 'fr-FR') {
        return 'fr';
    }

    if (language === 'ar-SA' || language === 'ar-TN') {
        return 'ar';
    }

    if (language === 'es-ES') {
        return 'es';
    }

    if (language === 'it-IT') {
        return 'it';
    }

    if (language === 'de-DE') {
        return 'de';
    }

    return 'en';
}

function getActionMessage(action, language) {
    if (action === 'SCROLL_DOWN') {
        return getMessageByLanguage(language, {
            en: 'Scrolled down.',
            fr: 'Défilement vers le bas.',
            ar: 'تم التمرير إلى الأسفل.',
            es: 'Desplazado hacia abajo.',
            it: 'Scorrimento verso il basso.',
            de: 'Nach unten gescrollt.'
        });
    }

    if (action === 'SCROLL_UP') {
        return getMessageByLanguage(language, {
            en: 'Scrolled up.',
            fr: 'Défilement vers le haut.',
            ar: 'تم التمرير إلى الأعلى.',
            es: 'Desplazado hacia arriba.',
            it: 'Scorrimento verso l’alto.',
            de: 'Nach oben gescrollt.'
        });
    }

    if (action === 'GO_BACK') {
        return getMessageByLanguage(language, {
            en: 'Going back.',
            fr: 'Retour en arrière.',
            ar: 'العودة إلى الخلف.',
            es: 'Volviendo atrás.',
            it: 'Torno indietro.',
            de: 'Gehe zurück.'
        });
    }

    if (action === 'GO_FORWARD') {
        return getMessageByLanguage(language, {
            en: 'Going forward.',
            fr: 'Navigation vers l’avant.',
            ar: 'الانتقال إلى الأمام.',
            es: 'Avanzando.',
            it: 'Vado avanti.',
            de: 'Gehe vorwärts.'
        });
    }

    return getFallbackMessage('COMMAND_COMPLETED', language);
}

function getVideoActionMessage(action, language) {
    const messages = {
        play: {
            en: 'Video is playing.',
            fr: 'La vidéo est lancée.',
            ar: 'تم تشغيل الفيديو.',
            es: 'El video se está reproduciendo.',
            it: 'Il video è in riproduzione.',
            de: 'Das Video wird abgespielt.'
        },
        pause: {
            en: 'Video paused.',
            fr: 'Vidéo mise en pause.',
            ar: 'تم إيقاف الفيديو مؤقتاً.',
            es: 'Video pausado.',
            it: 'Video in pausa.',
            de: 'Video pausiert.'
        },
        mute: {
            en: 'Video muted.',
            fr: 'Vidéo mise en sourdine.',
            ar: 'تم كتم صوت الفيديو.',
            es: 'Video silenciado.',
            it: 'Video silenziato.',
            de: 'Video stummgeschaltet.'
        },
        unmute: {
            en: 'Video unmuted.',
            fr: 'Son de la vidéo activé.',
            ar: 'تم تشغيل صوت الفيديو.',
            es: 'Sonido del video activado.',
            it: 'Audio del video attivato.',
            de: 'Video-Ton aktiviert.'
        }
    };

    return getMessageByLanguage(language, messages[action] || messages.play);
}

function getVolumeMessage(volume, language) {
    return getMessageByLanguage(language, {
        en: `Volume changed to ${volume} percent.`,
        fr: `Volume réglé à ${volume} pour cent.`,
        ar: `تم تغيير مستوى الصوت إلى ${volume} بالمئة.`,
        es: `Volumen cambiado al ${volume} por ciento.`,
        it: `Volume impostato al ${volume} percento.`,
        de: `Lautstärke auf ${volume} Prozent geändert.`
    });
}

function getMessageByLanguage(language, messages) {
    const group = getMessageLanguageGroup(language);

    return messages[group] || messages.en;
}

document.addEventListener('keydown', function (event) {
    if (event.code === 'Space' && event.altKey && !event.ctrlKey && !event.shiftKey && !event.metaKey) {
        event.preventDefault();
        togglePageVoiceListening();
    }
});
function detectLocalPageAction(text) {
    const normalized = normalizeExtensionText(text);

    if (
        normalized.includes('stop muting') ||
        normalized.includes('stop the mute') ||
        normalized.includes('unmute') ||
        normalized.includes('sound on') ||
        normalized.includes('turn sound on') ||
        normalized.includes('enable sound') ||
        normalized.includes('active le son') ||
        normalized.includes('remets le son') ||
        normalized.includes('coupe pas le son') ||
        normalized.includes('شغل الصوت') ||
        normalized.includes('رجع الصوت')
    ) {
        return 'UNMUTE';
    }

    if (
        normalized.includes('mute') ||
        normalized.includes('turn sound off') ||
        normalized.includes('sound off') ||
        normalized.includes('disable sound') ||
        normalized.includes('coupe le son') ||
        normalized.includes('désactive le son') ||
        normalized.includes('اطفي الصوت') ||
        normalized.includes('كتم الصوت')
    ) {
        return 'MUTE';
    }

    if (
        normalized.includes('pause') ||
        normalized.includes('stop video') ||
        normalized.includes('stop this') ||
        normalized.includes('pause video') ||
        normalized.includes('met pause') ||
        normalized.includes('mets pause') ||
        normalized.includes('وقف الفيديو')
    ) {
        return 'PAUSE_VIDEO';
    }

    if (
        normalized.includes('play') ||
        normalized.includes('continue') ||
        normalized.includes('resume') ||
        normalized.includes('start video') ||
        normalized.includes('continue video') ||
        normalized.includes('lance la video') ||
        normalized.includes('joue la video') ||
        normalized.includes('شغل الفيديو')
    ) {
        return 'PLAY_VIDEO';
    }

    if (
        normalized.includes('scroll down') ||
        normalized.includes('go down') ||
        normalized.includes('show me more') ||
        normalized.includes('descends') ||
        normalized.includes('انزل')
    ) {
        return 'SCROLL_DOWN';
    }

    if (
        normalized.includes('scroll up') ||
        normalized.includes('go up') ||
        normalized.includes('monte') ||
        normalized.includes('اطلع')
    ) {
        return 'SCROLL_UP';
    }

    if (
        normalized.includes('go back') ||
        normalized.includes('back page') ||
        normalized.includes('retour') ||
        normalized.includes('ارجع')
    ) {
        return 'GO_BACK';
    }

    return null;
}
async function clickYouTubeVideoByTitle(targetText, language) {
    if (!targetText) {
        return {
            success: false,
            message: getFallbackMessage('NO_TARGET', language)
        };
    }

    const normalizedTarget = normalize(targetText);

    for (let attempt = 0; attempt < 14; attempt++) {
        const videoLinks = getYouTubeVideoTitleLinks();

        let bestLink = null;
        let bestScore = 0;

        for (const link of videoLinks) {
            const title = normalize(
                link.innerText ||
                link.getAttribute('title') ||
                link.getAttribute('aria-label') ||
                ''
            );

            if (!title) {
                continue;
            }

            let score = 0;

            if (title === normalizedTarget) {
                score = 100;
            } else if (title.includes(normalizedTarget)) {
                score = 95;
            } else if (normalizedTarget.includes(title)) {
                score = 85;
            } else {
                score = similarity(title, normalizedTarget);
            }

            if (score > bestScore) {
                bestScore = score;
                bestLink = link;
            }
        }

        if (bestLink && bestScore >= 35) {
            bestLink.scrollIntoView({ behavior: 'smooth', block: 'center' });
            await sleep(300);

            bestLink.focus();
            bestLink.click();

            return {
                success: true,
                message: getMessageByLanguage(language, {
                    en: 'Opened the matching video.',
                    fr: 'J’ai ouvert la vidéo correspondante.',
                    ar: 'تم فتح الفيديو المطابق.',
                    es: 'Abrí el video correspondiente.',
                    it: 'Ho aperto il video corrispondente.',
                    de: 'Das passende Video wurde geöffnet.'
                })
            };
        }

        await sleep(500);
    }

    return {
        success: false,
        message: getMessageByLanguage(language, {
            en: 'I could not find a video with that name.',
            fr: 'Je n’ai pas trouvé de vidéo avec ce nom.',
            ar: 'لم أجد فيديو بهذا الاسم.',
            es: 'No pude encontrar un video con ese nombre.',
            it: 'Non ho trovato un video con quel nome.',
            de: 'Ich konnte kein Video mit diesem Namen finden.'
        })
    };
}

function getYouTubeVideoTitleLinks() {
    return Array.from(document.querySelectorAll(
        'ytd-video-renderer a#video-title, ytd-rich-item-renderer a#video-title-link, a#video-title'
    ))
        .filter(isVisible)
        .filter(link => {
            const href = link.href || '';
            return href.includes('/watch');
        });
}