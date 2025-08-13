<?php
/**
 * Telegram Coding Helper Bot (Qwen/OpenRouter)
 *
 * Features:
 *  - Answers coding questions, errors, stack traces, code review, patch requests.
 *  - Remembers recent conversation (per chat) for better context.
 *  - /forget clears context, /memory shows turns, /help for usage tips.
 *  - No romance/persona, only coding-focused.
 *
 * HOW MEMORY WORKS:
 *  - Each chat gets a history/history_<chatId>.json file.
 *  - Keeps last $MAX_TURNS turns (user+assistant), trimmed for token budget.
 *
 * REQUIRED: Fill in $TELEGRAM_BOT_TOKEN and $OPENROUTER_API_KEY.
 * DO NOT commit real tokens publicly.
 */

//////////////////// CONFIG ////////////////////
$TELEGRAM_BOT_TOKEN = 'YOUR_TELEGRAM_BOT_TOKEN_HERE';
$OPENROUTER_API_KEY = 'YOUR_OPENROUTER_API_KEY_HERE';
$OPENROUTER_MODEL   = 'qwen/qwen-2.5-coder:free'; // Use any supported coder model

$DEBUG              = false;              // true for extra logging
$LOG_FILE           = __DIR__ . '/bot_error.log';

$MAX_INPUT_CHARS    = 16000;
$MAX_TELEGRAM_MSG   = 3900;

//////////////////// MEMORY SETTINGS ////////////////////
$HISTORY_DIR        = __DIR__ . '/history';
$MAX_TURNS          = 24;
$MAX_HISTORY_CHARS  = 6000;

//////////////////// SYSTEM PROMPT ////////////////////
$SYSTEM_PROMPT = <<<PROMPT
You are a senior coding assistant. Help users debug code, analyze errors, suggest minimal fixes, and review code snippets.
Rules:
- Always give concise, actionable help.
- If asked about errors, suggest likely root causes and fixes.
- For code review, point out improvements, bugs, and style issues.
- For patch requests, provide a minimal, safe diff (unified format in ```diff).
- If info is missing, ask ONE clear follow-up question.
- Use code blocks for code/diff only.
- Never answer non-programming questions.
PROMPT;

//////////////////// TELEGRAM API ////////////////////
function tg_api(string $method, array $params): array {
    global $TELEGRAM_BOT_TOKEN;
    $url = "https://api.telegram.org/bot{$TELEGRAM_BOT_TOKEN}/{$method}";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS => $params,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
    ]);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    return [$res, $err];
}

function send_message($chatId, $text) {
    global $MAX_TELEGRAM_MSG;
    if (mb_strlen($text) > $MAX_TELEGRAM_MSG) {
        $text = mb_substr($text, 0, $MAX_TELEGRAM_MSG - 12) . "\n...[truncated]";
    }
    [$res, $err] = tg_api('sendMessage', [
        'chat_id' => $chatId,
        'text' => $text,
        'disable_web_page_preview' => true
    ]);
}

//////////////////// OPENROUTER ////////////////////
function openrouter_chat(array $messages): array {
    global $OPENROUTER_API_KEY, $OPENROUTER_MODEL;
    $payload = [
        'model' => $OPENROUTER_MODEL,
        'messages' => $messages,
        'temperature' => 0.15,
        'max_tokens' => 900,
        'top_p' => 0.9,
    ];
    $ch = curl_init("https://openrouter.ai/api/v1/chat/completions");
    $headers = [
        "Authorization: Bearer {$OPENROUTER_API_KEY}",
        "Content-Type: application/json",
    ];
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 60,
    ]);
    $res = curl_exec($ch);
    $err = curl_error($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($err) return ['error' => "cURL error: $err"];
    if ($status < 200 || $status >= 300) return ['error' => "HTTP $status: $res"];
    $data = json_decode($res, true);
    $content = $data['choices'][0]['message']['content'] ?? null;
    return $content ? ['content' => $content] : ['error' => 'No response from model'];
}

//////////////////// MEMORY ////////////////////
function history_path($chatId) {
    global $HISTORY_DIR;
    return $HISTORY_DIR . '/history_' . $chatId . '.json';
}

function load_history($chatId): array {
    $path = history_path($chatId);
    if (!file_exists($path)) return [];
    $json = @file_get_contents($path);
    if (!$json) return [];
    $data = json_decode($json, true);
    return is_array($data) ? $data : [];
}

function save_history($chatId, array $messages) {
    $path = history_path($chatId);
    @file_put_contents($path, json_encode($messages, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

function trim_history(array &$messages, int $maxTurns, int $maxChars) {
    if (count($messages) > $maxTurns) {
        $messages = array_slice($messages, -$maxTurns);
    }
    $total = 0;
    $rev = array_reverse($messages);
    $kept = [];
    foreach ($rev as $m) {
        $len = mb_strlen($m['content']);
        if ($total + $len > $maxChars) continue;
        $total += $len;
        $kept[] = $m;
    }
    $messages = array_reverse($kept);
}

function build_context_messages(string $systemPrompt, array $history, string $newUserMessage): array {
    $msgs = [];
    $msgs[] = ['role' => 'system', 'content' => $systemPrompt];
    foreach ($history as $m) {
        if (in_array($m['role'], ['user','assistant'])) $msgs[] = $m;
    }
    $msgs[] = ['role' => 'user', 'content' => $newUserMessage];
    return $msgs;
}

function log_err(string $msg) {
    global $DEBUG, $LOG_FILE;
    if ($DEBUG) file_put_contents($LOG_FILE, '['.date('Y-m-d H:i:s')."] $msg\n", FILE_APPEND);
}

//////////////////// CORE PROCESS ////////////////////
function process_update(array $update) {
    global $SYSTEM_PROMPT, $MAX_INPUT_CHARS, $MAX_TURNS, $MAX_HISTORY_CHARS;
    if (!isset($update['message'])) return;
    $msg    = $update['message'];
    $chatId = $msg['chat']['id'] ?? null;
    if (!$chatId) return;
    $text   = trim($msg['text'] ?? '');
    if ($text === '') return;

    $history = load_history($chatId);

    // Commands
    if (preg_match('/^\/(start|help|forget|memory)(@[\w_]+)?$/i', $text, $m)) {
        $cmd = strtolower($m[1]);
        switch ($cmd) {
            case 'start':
                $reply = "Hi! I am a coding helper bot. Send me any programming or debugging question, code, stacktrace, or ask for a patch. Use /help for tips.";
                break;
            case 'help':
                $reply = "Coding Helper Bot Usage:\n- Send errors, stack traces, code for help.\n- For reviews, send code snippets.\n- For patches, say 'Suggest a patch:' and your code.\n- /forget clears memory, /memory shows turns.";
                break;
            case 'forget':
                $history = [];
                $reply = "Context cleared! Start a new coding topic.";
                break;
            case 'memory':
                $reply = "Memory contains ".count($history)." recent turns.";
                break;
            default:
                $reply = "Unknown command.";
        }
        send_message($chatId, $reply);
        if ($cmd !== 'forget') {
            $history[] = ['role'=>'user','content'=>$text];
            $history[] = ['role'=>'assistant','content'=>$reply];
            trim_history($history, $MAX_TURNS, $MAX_HISTORY_CHARS);
            save_history($chatId, $history);
        } else {
            save_history($chatId, $history);
        }
        return;
    }

    if (mb_strlen($text) > $MAX_INPUT_CHARS) {
        $warn = "Message too long, please shorten your code or question.";
        send_message($chatId, $warn);
        $history[] = ['role'=>'user','content'=>$text];
        $history[] = ['role'=>'assistant','content'=>$warn];
        trim_history($history, $MAX_TURNS, $MAX_HISTORY_CHARS);
        save_history($chatId, $history);
        return;
    }

    // Coding context
    $contextMessages = build_context_messages($SYSTEM_PROMPT, $history, $text);
    $ai = openrouter_chat($contextMessages);
    if (isset($ai['error'])) {
        $reply = "Sorry, model error: " . $ai['error'] . "\nRetry or simplify your question.";
    } else {
        $reply = $ai['content'];
    }
    send_message($chatId, $reply);

    // Update memory
    $history[] = ['role'=>'user','content'=>$text];
    $history[] = ['role'=>'assistant','content'=>$reply];
    trim_history($history, $MAX_TURNS, $MAX_HISTORY_CHARS);
    save_history($chatId, $history);
}

//////////////////// ENTRY ////////////////////
if (!is_dir($HISTORY_DIR)) {
    @mkdir($HISTORY_DIR, 0775, true);
}
if (php_sapi_name() === 'cli') {
    $offsetFile = __DIR__ . '/offset_codingbot.dat';
    $offset = 0;
    if (file_exists($offsetFile)) $offset = (int)trim(file_get_contents($offsetFile));
    while (true) {
        [$res, $err] = tg_api('getUpdates', [
            'timeout' => 25,
            'offset'  => $offset + 1,
        ]);
        if ($err) { log_err("getUpdates error: $err"); sleep(3); continue; }
        $data = json_decode($res, true);
        if (!is_array($data) || !($data['ok'] ?? false)) {
            log_err("Bad getUpdates response: $res");
            sleep(2);
            continue;
        }
        foreach ($data['result'] as $update) {
            $uId = $update['update_id'];
            if ($uId > $offset) {
                $offset = $uId;
                file_put_contents($offsetFile, (string)$offset);
            }
            try {
                process_update($update);
            } catch (Throwable $e) {
                log_err("Process exception: ".$e->getMessage());
                if (isset($update['message']['chat']['id'])) {
                    send_message($update['message']['chat']['id'], "Internal error, retry.");
                }
            }
        }
    }
} else {
    $raw = file_get_contents('php://input');
    if ($raw) {
        $update = json_decode($raw, true);
        if (is_array($update)) {
            try { process_update($update); }
            catch (Throwable $e) { log_err("Webhook exception: ".$e->getMessage()); }
        }
    }
    echo "OK";
}
?>
