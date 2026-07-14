<!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex, nofollow">
	<title>ERROR 503</title>
	<style>
		:root {
			color-scheme: dark;
			--bg: #18181b;
			--panel: #242427;
			--text: #f4f4f5;
			--muted: #b8b8bf;
			--border: #3f3f46;
			--danger: #f87171;
			--danger-soft: #3f1d1d;
			--danger-ring: rgba(248, 113, 113, 0.45);
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
			background:
				radial-gradient(circle at top, rgba(63, 63, 70, 0.34), transparent 34rem),
				var(--bg);
			color: var(--text);
			font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
			line-height: 1.5;
		}

		main {
			width: min(100%, 620px);
			padding: 36px;
			background: var(--panel);
			border: 1px solid var(--border);
			border-radius: 8px;
			box-shadow: 0 18px 45px rgba(0, 0, 0, 0.24);
		}

		.status {
			display: inline-flex;
			align-items: center;
			gap: 10px;
			padding: 6px 10px;
			border-radius: 999px;
			background: var(--danger-soft);
			border: 1px solid var(--danger);
			color: var(--danger);
			font-size: 13px;
			font-weight: 700;
			letter-spacing: 0.04em;
			text-transform: uppercase;
			animation: pulse-border 1.8s ease-in-out infinite;
		}

		.status::before {
			content: "";
			width: 8px;
			height: 8px;
			border-radius: 50%;
			background: currentColor;
		}

		@keyframes pulse-border {
			0%, 100% {
				box-shadow: 0 0 0 0 rgba(248, 113, 113, 0);
			}
			50% {
				box-shadow: 0 0 0 5px var(--danger-ring);
			}
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

		.details strong {
			color: var(--text);
		}

		.retry {
			margin-top: 26px;
			padding-top: 20px;
			border-top: 1px solid var(--border);
			display: flex;
			align-items: center;
			gap: 16px;
			color: var(--muted);
		}

		.timer-wheel {
			width: 54px;
			height: 54px;
			flex: 0 0 auto;
			border-radius: 50%;
			position: relative;
			background:
				conic-gradient(var(--danger) 0deg, rgba(248, 113, 113, 0.14) 0deg),
				var(--danger-soft);
			animation: timer-fill 15s linear forwards;
		}

		.timer-wheel::before {
			content: "";
			position: absolute;
			inset: 8px;
			border-radius: 50%;
			background: var(--panel);
			border: 1px solid var(--border);
		}

		.timer-wheel::after {
			content: "";
			position: absolute;
			left: calc(50% - 3px);
			top: 4px;
			width: 6px;
			height: 23px;
			border-radius: 999px;
			background: var(--danger);
			transform-origin: 50% 23px;
			animation: timer-hand 15s linear forwards;
		}

		.retry p {
			font-size: 14px;
		}

		@keyframes timer-fill {
			from {
				background:
					conic-gradient(var(--danger) 0deg, rgba(248, 113, 113, 0.14) 0deg),
					var(--danger-soft);
			}
			to {
				background:
					conic-gradient(var(--danger) 360deg, rgba(248, 113, 113, 0.14) 0deg),
					var(--danger-soft);
			}
		}

		@keyframes timer-hand {
			from {
				transform: rotate(0deg);
			}
			to {
				transform: rotate(360deg);
			}
		}

		code {
			display: inline-block;
			padding: 2px 7px;
			border: 1px solid var(--border);
			border-radius: 5px;
			background: #18181b;
			color: var(--text);
			font-family: "SFMono-Regular", Consolas, "Liberation Mono", monospace;
			font-size: 0.94em;
		}
	</style>
</head>
<body>
	<main>
		<div class="status">HTTP 503</div>
		<h1>The backend application is temporarily unavailable.</h1>
		<p>This proxy/load-balancer is online, but the upstream service is not available to handle this request right now.</p>
		<div class="details">
			<div><strong>Application:</strong> <code>@@APPNAME@@</code></div>
			<div><strong>What the team should check:</strong> confirm the backend service is running, healthy, and not in maintenance mode.</div>
		</div>
		<div class="retry">
			<div class="timer-wheel" aria-hidden="true"></div>
			<p>We'll automatically try the connection again periodically, every 15 seconds, to see if the backend node maintenance is completed.</p>
		</div>
	</main>
	<script>
		(function () {
			window.setTimeout(function () {
				window.location.reload();
			}, 15000);
		}());
	</script>
</body>
</html>
