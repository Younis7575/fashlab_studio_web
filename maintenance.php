<?php
/* Standalone maintenance page shown when store_status is set to "closed". */
http_response_code(503);
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>We'll Be Right Back | Fashlab Studio</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Helvetica Neue', Arial, sans-serif; background: #faf4f5; color: #3b2b26; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
        .card { max-width: 560px; padding: 56px 40px; text-align: center; }
        .icon { font-size: 48px; margin-bottom: 16px; }
        h1 { font-family: Georgia, serif; font-size: 32px; margin-bottom: 12px; }
        p { line-height: 1.7; color: #6b5258; }
    </style>
</head>
<body>
    <div class="card">
        <div class="icon">🌸</div>
        <h1>We'll Be Right Back</h1>
        <p>Our store is currently being refreshed with new arrivals. Please check back shortly — we can't wait to show you what's next.</p>
    </div>
</body>
</html>