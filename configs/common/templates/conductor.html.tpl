<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Site ready</title>
    <style>
        :root {
            color-scheme: light dark;
            --background: #f7f5ef;
            --foreground: #1f2328;
            --muted: #68707a;
            --border: #d8d0c2;
            --panel: #fffdf8;
            --panel-strong: #ffffff;
            --accent: #0f766e;
            --accent-strong: #134e4a;
            --accent-soft: #d7f3ef;
            --code: #efebe2;
            --glow-a: rgba(15, 118, 110, 0.18);
            --glow-b: rgba(19, 78, 74, 0.12);
            --wash: #fbfaf6;
            --shadow: rgba(31, 35, 40, 0.11);
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --background: #181a1b;
                --foreground: #f1efe6;
                --muted: #aaa79d;
                --border: #413d34;
                --panel: #222425;
                --panel-strong: #292b2c;
                --accent: #5eead4;
                --accent-strong: #99f6e4;
                --accent-soft: #123330;
                --code: #161717;
                --glow-a: rgba(94, 234, 212, 0.13);
                --glow-b: rgba(153, 246, 228, 0.08);
                --wash: #202223;
                --shadow: rgba(0, 0, 0, 0.34);
            }
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            background:
                radial-gradient(circle at 18% 18%, var(--glow-a), transparent 28rem),
                radial-gradient(circle at 82% 78%, var(--glow-b), transparent 24rem),
                linear-gradient(135deg, var(--wash), var(--background) 58%),
                var(--background);
            color: var(--foreground);
            font: 16px/1.5 system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        main {
            width: min(100% - 40px, 880px);
            margin: 0 auto;
            padding: 56px 0;
        }

        .shell {
            position: relative;
            overflow: hidden;
            border: 1px solid var(--border);
            border-radius: 8px;
            background: var(--panel);
            box-shadow: 0 22px 60px var(--shadow);
        }

        .shell::before {
            content: "";
            display: block;
            height: 7px;
            background: var(--accent);
        }

        .content {
            display: grid;
            grid-template-columns: minmax(0, 1fr) 260px;
            gap: 36px;
            padding: 40px;
        }

        .label {
            margin: 0 0 22px;
            color: var(--accent-strong);
            font-size: 13px;
            font-weight: 700;
            text-transform: uppercase;
        }

        h1 {
            max-width: 560px;
            margin: 0 0 16px;
            font-size: clamp(34px, 6vw, 58px);
            font-weight: 760;
            line-height: 1.02;
        }

        p {
            margin: 0;
            color: var(--muted);
            font-size: 18px;
        }

        .status-list {
            display: grid;
            gap: 10px;
            align-self: start;
        }

        .status-item {
            padding: 14px;
            border: 1px solid var(--border);
            border-radius: 6px;
            background: var(--panel-strong);
        }

        .status-item span {
            display: block;
            margin-bottom: 4px;
            color: var(--muted);
            font-size: 12px;
            text-transform: uppercase;
        }

        .status-item strong {
            font-size: 15px;
            font-weight: 650;
        }

        .details {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            border-top: 1px solid var(--border);
            background: var(--panel-strong);
        }

        .detail {
            padding: 22px 40px;
            border-right: 1px solid var(--border);
        }

        .detail:last-child {
            border-right: 0;
        }

        .detail span {
            display: block;
            margin-bottom: 6px;
            color: var(--muted);
            font-size: 13px;
        }

        code {
            padding: 3px 7px;
            border-radius: 4px;
            background: var(--code);
            color: var(--foreground);
            font-family: ui-monospace, SFMono-Regular, Consolas, "Liberation Mono", monospace;
            font-size: 0.94em;
        }

        .prompt {
            margin-top: 28px;
            padding: 13px 15px;
            border: 1px solid var(--border);
            border-radius: 6px;
            background: var(--code);
            color: var(--muted);
            font-family: ui-monospace, SFMono-Regular, Consolas, "Liberation Mono", monospace;
            font-size: 13px;
            overflow-wrap: anywhere;
        }

        .prompt strong {
            color: var(--accent-strong);
            font-weight: 700;
        }

        @media (max-width: 760px) {
            body {
                background:
                    radial-gradient(circle at 18% 12%, var(--glow-a), transparent 19rem),
                    linear-gradient(150deg, var(--wash), var(--background) 62%),
                    var(--background);
            }

            main {
                width: min(100% - 28px, 880px);
                padding: 28px 0;
            }

            .content,
            .details {
                grid-template-columns: 1fr;
            }

            .content {
                padding: 28px;
            }

            .detail {
                padding: 18px 28px;
                border-right: 0;
                border-bottom: 1px solid var(--border);
            }

            .detail:last-child {
                border-bottom: 0;
            }
        }
    </style>
</head>
<body>
    <main>
        <section class="shell" aria-labelledby="page-title">
            <div class="content">
                <div>
                    <p class="label">Conductor</p>
                    <h1 id="page-title">Virtual host ready.</h1>
                    <p>The server block is in place. Deploy your application files into the document root and this page will disappear.</p>
                    <div class="prompt"><strong>$</strong> ready for first deploy</div>
                </div>
                <div class="status-list" aria-label="Provisioning status">
                    <div class="status-item">
                        <span>Server</span>
                        <strong>Configured</strong>
                    </div>
                    <div class="status-item">
                        <span>Routing</span>
                        <strong>Listening</strong>
                    </div>
                    <div class="status-item">
                        <span>Content</span>
                        <strong>Awaiting files</strong>
                    </div>
                </div>
            </div>
            <div class="details">
                <div class="detail">
                    <span>Application</span>
                    <code>@@APPNAME@@</code>
                </div>
                <div class="detail">
                    <span>Placeholder</span>
                    <code>conductor.html</code>
                </div>
            </div>
        </section>
    </main>
</body>
</html>
