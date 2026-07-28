<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    <style>
        :root { --blue: #0f4c81; --aqua: #38bdf8; --green: #10b981; --ink: #0f172a; --muted: #64748b; --line: #e2e8f0; --bg: #f8fafc; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: Inter, system-ui, -apple-system, Segoe UI, sans-serif; background: var(--bg); color: var(--ink); }
        main { min-height: 100vh; display: grid; place-items: center; padding: 24px; }
        .card { width: min(640px, 100%); background: #fff; border: 1px solid var(--line); border-radius: 8px; box-shadow: 0 18px 40px rgba(15, 23, 42, .08); padding: 28px; }
        .eyebrow { margin: 0 0 10px; text-transform: uppercase; letter-spacing: 0; font-size: 12px; font-weight: 800; color: var(--blue); }
        h1 { margin: 0 0 10px; font-size: 34px; line-height: 1.05; }
        p { margin: 0 0 18px; color: var(--muted); line-height: 1.6; }
        .actions { display: flex; flex-wrap: wrap; gap: 12px; }
        a { text-decoration: none; border-radius: 8px; min-height: 42px; display: inline-flex; align-items: center; justify-content: center; padding: 10px 16px; font-weight: 800; }
        .primary { background: var(--blue); color: #fff; }
        .secondary { background: #e0f2fe; color: var(--blue); }
        .tag { display: inline-flex; align-items: center; border-radius: 999px; padding: 6px 10px; font-size: 12px; font-weight: 800; margin-bottom: 12px; }
        .tag.success { background: #dcfce7; color: #047857; }
        .tag.cancelled { background: #fee2e2; color: #b91c1c; }
    </style>
</head>
<body>
    <main>
        <section class="card">
            <div class="tag {{ $status }}">{{ $title }}</div>
            <p class="eyebrow">AbaiMarket</p>
            <h1>{{ $headline }}</h1>
            <p>{{ $message }}</p>
        <div class="actions">
                <a class="primary" href="{{ $primary_url }}">{{ $primary_label }}</a>
                <a class="secondary" href="{{ $secondary_url }}">{{ $secondary_label }}</a>
            </div>
        </section>
    </main>
</body>
</html>
