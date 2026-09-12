# GoGlobal-AI

## GoGlobal AI Travel Bot (PHP)

This repository now includes a PHP page (`/home/runner/work/GoGlobal-AI/GoGlobal-AI/index.php`) that:

- collects user travel preferences (`destination`, `budget`, `travelers`, `days`, `interests`)
- connects to SQL using environment variables
- searches the configured table and ranks the best option plus alternatives using a weighted scoring model

### Database environment variables

- `DB_HOST` (default `127.0.0.1`)
- `DB_PORT` (default `3306`)
- `DB_NAME` (default `goglobal`)
- `DB_USER` (default `root`)
- `DB_PASS` (default empty)
- `DB_TABLE` (default `packages`)

### Run locally

```bash
php -S 127.0.0.1:8000 -t /home/runner/work/GoGlobal-AI/GoGlobal-AI
```

Then open: `http://127.0.0.1:8000/index.php`
