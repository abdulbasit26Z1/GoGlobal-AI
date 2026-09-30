# GoGlobal-AI

## AI setup

The chat uses the Azmeer Gemini-compatible API. It sends requests to `gemini-2.5-flash` by default:

```bash
php -S localhost:8000
```

Set `AZMEER_AI_MODEL` to use another supported model. If the provider is unavailable, the app uses its local catalogue matcher so the demo still works offline.