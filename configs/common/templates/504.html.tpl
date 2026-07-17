<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>504 Gateway Timeout</title>
    <style>
        :root {
            color-scheme: light;
            --background: #f4f4f2;
            --foreground: #222222;
            --muted: #666666;
            --border: #d7d2c8;
            --code: #eeece7;
            --danger: #c82333;
            --danger-soft: #fff1f2;
            --danger-ring: rgba(200, 35, 51, 0.24);
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            padding: clamp(72px, 14vh, 132px) 20px 48px;
            display: grid;
            justify-items: center;
            align-items: start;
            background: var(--background);
            color: var(--foreground);
            font: 16px/1.5 system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        main {
            width: 100%;
            max-width: 620px;
            margin: 0;
        }

        .heading {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 20px;
            margin: 0 0 16px;
        }

        .status {
            display: inline-block;
            flex: 0 0 auto;
            margin: 0;
            padding: 6px 10px;
            border: 1px solid var(--danger);
            border-radius: 4px;
            background: var(--danger-soft);
            color: var(--danger);
            font-size: 13px;
            font-weight: 700;
            text-transform: uppercase;
            animation: pulse-border 1.8s ease-in-out infinite;
        }

        @keyframes pulse-border {
            0%, 100% {
                box-shadow: 0 0 0 0 rgba(200, 35, 51, 0);
            }
            50% {
                box-shadow: 0 0 0 5px var(--danger-ring);
            }
        }

        h1 {
            margin: 0;
            font-size: 32px;
            font-weight: 650;
            line-height: 1.15;
        }

        p {
            margin: 0 0 24px;
            color: var(--muted);
            font-size: 17px;
        }

        dl {
            margin: 32px 0 0;
            padding: 22px 0 0;
            border-top: 1px solid var(--border);
            display: grid;
            grid-template-columns: max-content 1fr;
            gap: 10px 18px;
        }

        dt {
            color: var(--muted);
        }

        dd {
            margin: 0;
        }

        code {
            padding: 2px 6px;
            border-radius: 4px;
            background: var(--code);
            font-family: ui-monospace, SFMono-Regular, Consolas, "Liberation Mono", monospace;
            font-size: 0.94em;
        }

        @media (max-width: 560px) {
            .heading {
                display: grid;
                gap: 14px;
            }

            .status {
                justify-self: start;
            }
        }
    </style>
</head>
<body>
    <main>
        <div class="heading">
            <h1>Gateway timeout.</h1>
            <p class="status">HTTP 504</p>
        </div>
        <p>The proxy/load-balancer is running, but the upstream service did not respond before the timeout.</p>
    </main>
</body>
</html>
