<?php
/* Standalone 500 page — intentionally dependency-free so it works even when
   the application itself failed to load. Never reveal error details here. */
http_response_code(500);
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Something Went Wrong | Fashlab Studio</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Georgia', 'Times New Roman', serif; background: #faf7f4; color: #3b2b26; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
        .card { max-width: 520px; padding: 56px 40px; text-align: center; }
        .code { font-size: 72px; color: #5a1424; letter-spacing: 4px; }
        h1 { font-size: 28px; margin: 16px 0 12px; }
        p { font-family: 'Helvetica Neue', Arial, sans-serif; font-size: 15px; line-height: 1.7; color: #6b5a52; }
        a { display: inline-block; margin-top: 24px; padding: 12px 28px; background: #3b2b26; color: #fff; text-decoration: none; font-family: 'Helvetica Neue', Arial, sans-serif; font-size: 13px; letter-spacing: 1.5px; }
        a:hover { background: #5a1424; }
    </style>
</head>
<body>
    <div class="card">
        <div class="code">500</div>
        <h1>Something went wrong</h1>
        <p>We're sorry — an unexpected error occurred. Our team has been notified and we're working to fix it. Please try again shortly.</p>
        <a href="/">Back to Home</a>
    </div>
</body>
</html>