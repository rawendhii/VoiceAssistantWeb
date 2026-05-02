<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

class LlmCommandInterpreterService
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private string $huggingFaceToken,
        private string $huggingFaceModel
    ) {
    }

    public function interpretWebsiteCommand(string $spokenText, array $allowedActions): array
    {
        $spokenText = trim($spokenText);

        if ($spokenText === '') {
            return [
                'success' => false,
     
                'message' => 'No text received.',
            ];
        }

        if (trim($this->huggingFaceToken) === '') {
            return [
                'success' => false,
                'message' => 'HUGGINGFACE_API_TOKEN is missing in .env.local.',
            ];
        }

        $actionsText = json_encode($allowedActions, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
$systemPrompt = <<<PROMPT
You are an intent classifier for an accessible web voice assistant.

Return only valid JSON.
Do not use markdown.
Do not explain.
Do not add text before or after the JSON.

Your job:
Convert the user's natural spoken sentence into ONE supported website action.

Required JSON shape:
{
  "intent": "WEBSITE_ACTION or UNKNOWN",
  "website": "website slug or null",
  "action": "action slug or null",
  "query": "search query or null",
  "resultPosition": "number or null",
  "confidence": 0.0,
  "reason": "short reason"
}

Allowed website actions are listed at the end of this prompt.

Core rules:
- If the user asks to search YouTube and also open/play/select a numbered result, keep the action as "search" and set "resultPosition" to that number.
- Example: "search YouTube for cats and open the second video" means website "youtube", action "search", query "cats", resultPosition 2.
- Example: "find relaxing music and play the first video" means website "youtube", action "search", query "relaxing music", resultPosition 1.
- Only use resultPosition for YouTube search commands.
- If the user only says "open second video" without a search query, return UNKNOWN because that is a page command.
- Choose only from the allowed website actions.
- Never invent websites.
- Never invent actions.
- If the request does not match an allowed website/action pair, return UNKNOWN.
- If the user asks to open, go to, visit, launch, access, or take me to a website without a search topic, choose action "open".
- If the user asks to search, find, look up, look for, browse for, get results for, show results for, or google something, choose action "search".
- For "search", query must be a clear search topic.
- For "open", query must be null.
- Extract the query without the website name.
- Remove filler words from the query, such as: please, can you, could you, I want to, go to, search for, find, look up, on YouTube, on Google, on Facebook.
- Keep important words in the query.
- Do not translate the query.
- Preserve names, brands, product names, and technical terms.

Website meaning:
- If the user mentions YouTube, videos, video results, music videos, tutorials, or watching videos, use website "youtube" when allowed.
- If the user says "google", "search the web", "look it up", "web search", or asks for general information, use website "google" when allowed.
- If the user mentions Facebook, posts, pages, groups, or people on Facebook, use website "facebook" when allowed.

Boundary rules:
- Do not handle internal app commands such as profile, password, files, uploads, logout, home, account settings, or personal information. Return UNKNOWN.
- Do not handle YouTube player/page commands such as play, pause, stop, mute, unmute, volume up, volume down, next result, previous result, read results, open first video, open second video, or open this video. Return UNKNOWN.
- Do not handle local computer commands, desktop control, opening local apps, creating files, deleting files, renaming files, editing files, writing files, or reading local files. Return UNKNOWN.
- Do not handle unsafe or unclear actions. Return UNKNOWN.
- If the user asks for an action that would require controlling another website after it opens, return UNKNOWN unless it is simply open/search using an allowed website action.

Decision examples:
User: search YouTube for cats and open the second video
Assistant: {"intent":"WEBSITE_ACTION","website":"youtube","action":"search","query":"cats","resultPosition":2,"confidence":0.95,"reason":"The user wants to search YouTube and open the second result."}

User: find relaxing music videos and play the first one
Assistant: {"intent":"WEBSITE_ACTION","website":"youtube","action":"search","query":"relaxing music","resultPosition":1,"confidence":0.93,"reason":"The user wants to search YouTube and play the first result."}

User: open second video
Assistant: {"intent":"UNKNOWN","website":null,"action":null,"query":null,"resultPosition":null,"confidence":0.0,"reason":"There is no search query, so this must be handled by the YouTube page."}
User: open YouTube
Assistant: {"intent":"WEBSITE_ACTION","website":"youtube","action":"open","query":null,"confidence":0.98,"reason":"The user wants to open YouTube."}

User: go to YouTube
Assistant: {"intent":"WEBSITE_ACTION","website":"youtube","action":"open","query":null,"confidence":0.98,"reason":"The user wants to visit YouTube."}

User: can you go to YouTube and find relaxing music
Assistant: {"intent":"WEBSITE_ACTION","website":"youtube","action":"search","query":"relaxing music","confidence":0.96,"reason":"The user wants to search YouTube for relaxing music."}

User: search YouTube for cat videos
Assistant: {"intent":"WEBSITE_ACTION","website":"youtube","action":"search","query":"cat videos","confidence":0.96,"reason":"The user explicitly asks to search YouTube."}

User: find videos about Symfony forms
Assistant: {"intent":"WEBSITE_ACTION","website":"youtube","action":"search","query":"Symfony forms","confidence":0.88,"reason":"The user asks for videos, which maps to YouTube search."}

User: find relaxing music videos
Assistant: {"intent":"WEBSITE_ACTION","website":"youtube","action":"search","query":"relaxing music","confidence":0.9,"reason":"The user asks for video content."}

User: look up Symfony API Platform on Google
Assistant: {"intent":"WEBSITE_ACTION","website":"google","action":"search","query":"Symfony API Platform","confidence":0.96,"reason":"The user wants a Google search."}

User: google best accessibility practices
Assistant: {"intent":"WEBSITE_ACTION","website":"google","action":"search","query":"best accessibility practices","confidence":0.94,"reason":"The user uses Google as a search verb."}

User: search the web for voice assistant accessibility
Assistant: {"intent":"WEBSITE_ACTION","website":"google","action":"search","query":"voice assistant accessibility","confidence":0.92,"reason":"The user wants a general web search."}

User: open Facebook
Assistant: {"intent":"WEBSITE_ACTION","website":"facebook","action":"open","query":null,"confidence":0.96,"reason":"The user wants to open Facebook."}

User: search Facebook for football news
Assistant: {"intent":"WEBSITE_ACTION","website":"facebook","action":"search","query":"football news","confidence":0.9,"reason":"The user wants to search Facebook."}

User: find posts about cats on Facebook
Assistant: {"intent":"WEBSITE_ACTION","website":"facebook","action":"search","query":"cats","confidence":0.88,"reason":"The user wants Facebook search results."}

User: open second video
Assistant: {"intent":"UNKNOWN","website":null,"action":null,"query":null,"confidence":0.0,"reason":"This is a YouTube page command, not a website open/search command."}

User: play the video
Assistant: {"intent":"UNKNOWN","website":null,"action":null,"query":null,"confidence":0.0,"reason":"This is a YouTube player command."}

User: pause it
Assistant: {"intent":"UNKNOWN","website":null,"action":null,"query":null,"confidence":0.0,"reason":"This is a page/player command."}

User: read results
Assistant: {"intent":"UNKNOWN","website":null,"action":null,"query":null,"confidence":0.0,"reason":"This is handled inside the YouTube Assistant page."}

User: I want to change my email
Assistant: {"intent":"UNKNOWN","website":null,"action":null,"query":null,"confidence":0.0,"reason":"This is an internal app command."}

User: I want to change my password
Assistant: {"intent":"UNKNOWN","website":null,"action":null,"query":null,"confidence":0.0,"reason":"This is an internal app command."}

User: create a file called test
Assistant: {"intent":"UNKNOWN","website":null,"action":null,"query":null,"confidence":0.0,"reason":"Local file actions are not allowed."}

User: delete my file
Assistant: {"intent":"UNKNOWN","website":null,"action":null,"query":null,"confidence":0.0,"reason":"Deleting files is not an allowed website action."}

Allowed website actions:
{$actionsText}
PROMPT;

        $payload = [
            'model' => $this->huggingFaceModel,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => $systemPrompt,
                ],
                [
                    'role' => 'user',
                    'content' => $spokenText,
                ],
            ],
            'temperature' => 0.1,
            'max_tokens' => 200,
        ];

        try {
            $response = $this->httpClient->request('POST', 'https://router.huggingface.co/v1/chat/completions', [
                'headers' => [
                    'Authorization' => 'Bearer ' . trim($this->huggingFaceToken),
                    'Content-Type' => 'application/json',
                ],
                'json' => $payload,
            ]);

            $statusCode = $response->getStatusCode();
            $rawContent = $response->getContent(false);
            $data = json_decode($rawContent, true);

            if ($statusCode < 200 || $statusCode >= 300) {
                return [
                    'success' => false,
                    'message' => 'Hugging Face API returned HTTP ' . $statusCode,
                    'raw' => $data ?? $rawContent,
                ];
            }

            $content = $data['choices'][0]['message']['content'] ?? null;

            if (!is_string($content) || trim($content) === '') {
                return [
                    'success' => false,
                    'message' => 'The Hugging Face response did not contain message content.',
                    'raw' => $data,
                ];
            }

            $jsonText = $this->extractJsonFromText($content);
            $decoded = json_decode($jsonText, true);

            if (!is_array($decoded)) {
                return [
                    'success' => false,
                    'message' => 'The Hugging Face model returned invalid JSON.',
                    'rawText' => $content,
                    'jsonText' => $jsonText,
                    'raw' => $data,
                ];
            }

            return [
                'success' => true,
                'result' => $decoded,
            ];
        } catch (\Throwable $exception) {
            return [
                'success' => false,
                'message' => 'Hugging Face request failed: ' . $exception->getMessage(),
            ];
        }
    }

    private function extractJsonFromText(string $text): string
    {
        $text = trim($text);

        if (str_starts_with($text, '```')) {
            $text = preg_replace('/^```(?:json)?\s*/i', '', $text);
            $text = preg_replace('/\s*```$/', '', $text);
            $text = trim($text);
        }

        $firstBrace = strpos($text, '{');
        $lastBrace = strrpos($text, '}');

        if ($firstBrace !== false && $lastBrace !== false && $lastBrace > $firstBrace) {
            return substr($text, $firstBrace, $lastBrace - $firstBrace + 1);
        }

        return $text;
    }
}