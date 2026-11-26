# A2UI Livewire demo

A Laravel 13 + Livewire 4 + Tailwind app that uses `acip/a2ui-livewire` from the
parent directory (Composer path repository).

```bash
composer install && npm install && npm run build
cp .env.example .env && php artisan key:generate && php artisan migrate
php artisan serve
```

- `/`: gallery of the 43 official A2UI Basic Catalog examples, with a
  message stepper, a live data-model inspector and an action log.
- `/agent`: agent playground. It uses the built-in deterministic agent
  (`app/A2ui/DemoAgent.php`) by default; set `PLAYGROUND_TRANSPORT=a2a` and
  `A2UI_A2A_URL` to talk to a real A2A agent instead.

See the [main README](../README.md) for running it against the official A2UI
restaurant agent.
