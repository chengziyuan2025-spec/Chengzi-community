@php
	$statusCode = isset($code) ? (int) $code : 500;
@endphp
<!DOCTYPE html>
<html lang="zh-CN">
	<head>
		<meta charset="utf-8">
		<meta name="viewport" content="width=device-width, initial-scale=1">
		<meta name="robots" content="noindex">
		<title>页面不可用</title>
		<style>
			html,
			body {
				height: 100%;
				margin: 0;
			}

			body {
				align-items: center;
				background: #f7f8fa;
				color: #20242a;
				display: flex;
				font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
				justify-content: center;
			}

			main {
				max-width: 420px;
				padding: 32px;
				text-align: center;
			}

			.code {
				color: #6b7280;
				font-size: 40px;
				font-weight: 700;
				line-height: 1;
				margin: 0 0 18px;
			}

			h1 {
				font-size: 24px;
				line-height: 1.25;
				margin: 0 0 10px;
			}

			p {
				color: #5b6472;
				font-size: 15px;
				line-height: 1.6;
				margin: 0 0 24px;
			}

			a {
				color: #0f766e;
				font-weight: 600;
				text-decoration: none;
			}
		</style>
	</head>
	<body>
		<main>
			<p class="code">{{ $statusCode }}</p>
			<h1>页面不可用</h1>
			<p>请求无法完成，请返回首页后重试。</p>
			<a href="{{ route('home') }}">返回首页</a>
		</main>
	</body>
</html>
