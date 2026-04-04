# Rural Lease Portal - Repository Structure

```text
w2t50/
├── prompt.md
├── metadata.json
├── docs/
│   ├── questions.md
│   ├── build-order.md
│   ├── architecture.md
│   ├── api.md
│   ├── api-spec.md
│   ├── features.md
│   ├── structure.md
│   ├── acceptance-checklist.md
│   ├── AI-self-test.md
│   └── design.md
├── repo/
│   ├── Dockerfile
│   ├── docker-compose.yml
│   ├── run_tests.sh
│   ├── README.md
│   ├── src/
│   │   ├── app/                # ThinkPHP application
│   │   ├── config/
│   │   ├── route/
│   │   └── public/             # web entry only, no encryption keys
│   ├── database/
│   │   ├── migrations/
│   │   └── seeders/
│   ├── unit_tests/
│   ├── API_tests/
│   └── storage/
│       ├── uploads/
│       ├── exports/
│       └── logs/
├── sessions/
│   └── develop-1.json
└── .tmp/
    └── eaglepoint-workflow.md
```

## Notes

- Keep encryption keys outside `src/public/` and outside source-controlled paths.
- Keep scope/RBAC checks in backend services or middleware, not only UI.
- Keep API tests connected to real MySQL in Docker, not mocked DB calls.
