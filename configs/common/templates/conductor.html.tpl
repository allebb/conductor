<!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<title>Application ready to deploy!</title>
	<style>
		:root {
			color-scheme: light dark;
			--bg: #f6f7f9;
			--panel: #ffffff;
			--text: #1f2933;
			--muted: #5f6b7a;
			--border: #d9dee7;
			--accent: #2563eb;
			--accent-soft: #dbeafe;
		}

		@media (prefers-color-scheme: dark) {
			:root {
				--bg: #111827;
				--panel: #17212f;
				--text: #edf2f7;
				--muted: #a7b0be;
				--border: #2d3a4d;
				--accent: #93c5fd;
				--accent-soft: #1d3557;
			}
		}

		* {
			box-sizing: border-box;
		}

		body {
			margin: 0;
			min-height: 100vh;
			display: grid;
			place-items: center;
			padding: 32px 16px;
			background: var(--bg);
			color: var(--text);
			font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
			line-height: 1.5;
		}

		main {
			width: min(100%, 660px);
			padding: 36px;
			background: var(--panel);
			border: 1px solid var(--border);
			border-radius: 8px;
			box-shadow: 0 18px 45px rgba(15, 23, 42, 0.08);
		}

		.status {
			display: inline-flex;
			align-items: center;
			gap: 10px;
			padding: 6px 10px;
			border-radius: 999px;
			background: var(--accent-soft);
			color: var(--accent);
			font-size: 13px;
			font-weight: 700;
			letter-spacing: 0.04em;
			text-transform: uppercase;
		}

		.status::before {
			content: "";
			width: 8px;
			height: 8px;
			border-radius: 50%;
			background: currentColor;
		}

		h1 {
			margin: 22px 0 12px;
			font-size: clamp(28px, 5vw, 42px);
			line-height: 1.1;
			letter-spacing: 0;
		}

		p {
			margin: 0;
			color: var(--muted);
			font-size: 17px;
		}

		.details {
			margin-top: 26px;
			padding-top: 20px;
			border-top: 1px solid var(--border);
			display: grid;
			gap: 8px;
			font-size: 14px;
			color: var(--muted);
		}

		.details strong,
		code {
			color: var(--text);
		}

		code {
			display: inline-block;
			padding: 2px 7px;
			border: 1px solid var(--border);
			border-radius: 5px;
			background: rgba(37, 99, 235, 0.08);
			font-family: "SFMono-Regular", Consolas, "Liberation Mono", monospace;
			font-size: 0.95em;
		}
	</style>
</head>
<body>
	<main>
		<div class="status">Virtual host ready!</div>
		<h1>This site is ready to be deployed.</h1>
		<p>Conductor has prepared this virtual host and is waiting for your application or site files.</p>
		<div class="details">
			<div><strong>Application:</strong> <code>@@APPNAME@@</code></div>
			<div><strong>Next step:</strong> deploy your site or application files into this document root.</div>
			<div><strong>Cleanup:</strong> once your own index file is deployed, you can safely delete <code>conductor.html</code>.</div>
		</div>
	</main>
</body>
</html>
