<?php
$title = "DIALECTIC Server Setup";
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title><?php echo htmlspecialchars($title, ENT_QUOTES, "UTF-8"); ?></title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 40px;
            color: #222;
            background: #f8f8f8;
        }
        main {
            max-width: 820px;
            background: #fff;
            border: 1px solid #d8d8d8;
            padding: 24px;
        }
        code {
            background: #f0f0f0;
            padding: 2px 5px;
        }
        li {
            margin: 8px 0;
        }
    </style>
</head>
<body>
<main>
    <h1>DIALECTIC Server setup required</h1>
    <p>DIALECTIC Server requires the PHP PostgreSQL extension and database bootstrap before it can render the full settings and profile pages.</p>
    <p>The in-game DIALECTIC endpoint is available at <code>main.php</code> under your server install path once configuration and database setup are complete.</p>
    <h2>Required next pieces</h2>
    <ul>
        <li>Enable/load PHP's PostgreSQL extension so <code>pg_connect()</code> exists.</li>
        <li>Create or point DIALECTIC Server at its PostgreSQL database.</li>
        <li>Run the DIALECTIC Server database bootstrap/update path.</li>
    </ul>
</main>
</body>
</html>
