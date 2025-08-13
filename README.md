# Telegram Coding Helper Bot

A simple PHP Telegram bot that helps you debug code, analyze errors, review code, and suggest patches using the Qwen coding model via [OpenRouter](https://openrouter.ai).  
It remembers recent chat context per user for more helpful answers.

## Features

- **Coding Help:** Answers programming questions, error messages, stack traces, code review, and patch requests.
- **Memory:** Remembers recent conversation for better context. `/forget` clears context, `/memory` shows memory status.
- **Patch Suggestions:** Ask for a patch/diff by saying "Suggest a patch:" and providing your code.
- **Concise, Actionable Answers:** Replies are short, focused, and always fenced in code blocks when needed.
- **Command Support:** `/start`, `/help`, `/forget`, `/memory`

## Setup

1. **Requirements**
   - PHP 8.0+ with cURL enabled (`php-curl`)
   - Telegram bot token from [@BotFather](https://t.me/botfather)
   - OpenRouter API key ([get one here](https://openrouter.ai))
   - Write permissions to the directory where the bot runs

2. **Clone/Download**
   - Place `telegram_coding_helper_bot.php` in your project folder.

3. **Configuration**
   - Open the file and set your credentials:
     ```php
     $TELEGRAM_BOT_TOKEN = 'YOUR_TELEGRAM_BOT_TOKEN';
     $OPENROUTER_API_KEY = 'YOUR_OPENROUTER_API_KEY';
     ```
   - (Optional) Change `$OPENROUTER_MODEL` to your preferred coding model.

4. **Create history directory**
   - In the bot’s directory, run:
     ```
     mkdir history
     chmod 775 history
     ```
   - This allows the bot to store per-user conversation context.

5. **Run the bot**
   - **Long Polling (CLI):**
     ```
     php telegram_coding_helper_bot.php
     ```
     (Use `screen`, `tmux`, or a supervisor for 24/7 uptime.)

   - **Webhook (optional):**
     - Upload the bot file to a public HTTPS server.
     - Set the webhook:
       ```
       curl -X POST "https://api.telegram.org/botYOUR_TELEGRAM_BOT_TOKEN/setWebhook" \
            -d "url=https://yourserver.example/bot.php"
       ```

## Usage

- Type `/start` or `/help` in your Telegram chat with the bot for instructions.
- Send any coding question, error, or patch request.
- Use `/forget` to clear context and start a new topic.
- Use `/memory` to check how many recent turns the bot remembers.

### Example Interactions

```
User: I'm getting "TypeError: cannot read property 'foo' of undefined" in my JS code.
Bot: Likely causes:
- `foo` is undefined at the time of access.
- The object holding `foo` was not initialized.
Minimal fix:
```js
if (obj && obj.foo) { /* use obj.foo */ }
```
If you can share the code around this error, I can help further.
```

```
User: Suggest a patch:
```python
def add(a, b):
    return a + b
print(add("1", 2))
```
Bot:
```diff
--- a.py
+++ b.py
@@
-def add(a, b):
-    return a + b
+def add(a, b):
+    return int(a) + int(b)
```
This ensures both inputs are converted to integers before addition.
```

## Security

- **Never share your real API keys or Telegram bot token publicly.**
- Store credentials securely and restrict file permissions.

## License

MIT

## Credits

Powered by [Qwen](https://openrouter.ai/models/qwen/qwen-2.5-coder:free) via OpenRouter.

---
